<?php

declare(strict_types=1);
namespace CommunitySDKs\OpenApi;

use CommunitySDKs\Exception\SdkException;
use Symfony\Component\Yaml\Yaml;

/** OpenAPI document with local pointer and rooted local-file reference resolution. */
final class Document
{
    private ?string $sourcePath;
    private ?string $sourceDirectory;

    /** @var array<string,array{path:string,data:array<string,mixed>}> */
    private array $externalDocuments = [];

    /** @param list<string> $allowedRemoteRefHosts */
    public function __construct(
        public readonly array $data,
        ?string $sourcePath = null,
        private readonly array $allowedRemoteRefHosts = [],
        private readonly ?RemoteReferenceLoader $remoteReferenceLoader = null,
        private readonly int $maxRemoteReferenceBytes = 1_048_576,
    ) {
        if ($maxRemoteReferenceBytes < 1) throw new SdkException('Remote reference size limit must be positive.');
        $this->sourcePath = $sourcePath === null ? null : (realpath($sourcePath) ?: $sourcePath);
        $this->sourceDirectory = $this->sourcePath === null ? null : dirname($this->sourcePath);
        if (!isset($data['openapi'], $data['paths']) || !is_array($data['paths'])) {
            throw new SdkException('Expected an OpenAPI 3.x document with paths.');
        }
        if (!str_starts_with((string) $data['openapi'], '3.')) {
            throw new SdkException('Only OpenAPI 3.x is supported.');
        }
    }

    /** @param list<string> $allowedRemoteRefHosts */
    public static function fromFile(string $file, array $allowedRemoteRefHosts = [], ?RemoteReferenceLoader $remoteReferenceLoader = null, int $maxRemoteReferenceBytes = 1_048_576): self
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new SdkException("Cannot read specification: {$file}");
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new SdkException('Could not read specification.');
        }
        $realPath = realpath($file);
        if ($realPath === false) throw new SdkException("Cannot resolve specification path: {$file}");
        $value = self::parseFile($realPath, $contents);
        if (!is_array($value)) throw new SdkException('Specification must be an object.');
        if ($maxRemoteReferenceBytes < 1) throw new SdkException('Remote reference size limit must be positive.');
        return new self($value, $realPath, $allowedRemoteRefHosts, $remoteReferenceLoader, $maxRemoteReferenceBytes);
    }

    /** @return array<string,array{path:string,data:array<string,mixed>}> */
    public function externalDocuments(): array
    {
        return $this->externalDocuments;
    }

    public function sourcePath(): ?string
    {
        return $this->sourcePath;
    }

    /** @return array{value:mixed,sourcePath:?string,key:string,pointer:string} */
    public function resolveReference(string $ref, ?string $sourcePath = null): array
    {
        $currentPath = $sourcePath === null ? $this->sourcePath : (realpath($sourcePath) ?: $sourcePath);
        $documentPath = $currentPath;
        $fragment = '';
        $pathPart = '';
        if (str_starts_with($ref, '#')) {
            $fragment = $ref;
        } else {
            [$pathPart, $fragmentPart] = array_pad(explode('#', $ref, 2), 2, '');
            if (str_contains($pathPart, '?')) throw new SdkException('Query strings are not supported in $ref paths.');
            if ($this->isHttpsUrl($pathPart)) {
                $documentPath = $this->validateRemoteRefUrl($pathPart);
            } elseif ($currentPath !== null && $this->isHttpsUrl($currentPath)) {
                $documentPath = $this->resolveRemoteRelative($currentPath, $pathPart);
            } elseif ($currentPath === null || $this->sourceDirectory === null) {
                throw new SdkException('Cross-file $ref requires a specification loaded from a local file.');
            } elseif (str_starts_with($ref, '//') || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $ref) === 1 || str_contains($ref, '\\')) {
                throw new SdkException('Network and absolute-path $ref are not supported: '.$ref);
            } else {
                $resolved = realpath(dirname($currentPath).DIRECTORY_SEPARATOR.rawurldecode($pathPart));
                if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) throw new SdkException("Cannot read local reference: {$pathPart}");
                $root = rtrim($this->sourceDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
                if ($resolved !== $this->sourcePath && !str_starts_with($resolved, $root)) {
                    throw new SdkException('Local $ref resolves outside the specification directory.');
                }
                $documentPath = $resolved;
            }
            $fragment = $fragmentPart === '' ? '' : '#'.$fragmentPart;
        }

        if ($fragment !== '' && !str_starts_with($fragment, '#/')) {
            throw new SdkException('Only JSON Pointer fragments are supported in $ref values.');
        }
        $document = $this->documentData($documentPath);
        $pointer = $fragment === '' ? '' : substr($fragment, 1);
        $value = $document;
        if ($pointer !== '') {
            foreach (explode('/', substr($pointer, 1)) as $part) {
                $part = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($part));
                if (!is_array($value) || !array_key_exists($part, $value)) throw new SdkException("Unresolved reference {$ref}");
                $value = $value[$part];
            }
        }
        if (!is_array($value)) throw new SdkException("Reference {$ref} is not an object");
        $key = $documentPath === null || $documentPath === $this->sourcePath
            ? 'root'
            : 'external/'.hash('sha256', $documentPath);
        return ['value' => $value, 'sourcePath' => $documentPath, 'key' => $key, 'pointer' => $pointer];
    }

    /** @return array<string,mixed> */
    private function documentData(?string $path): array
    {
        if ($path === null || $path === $this->sourcePath) return $this->data;
        $key = hash('sha256', $path);
        if (isset($this->externalDocuments[$key])) return $this->externalDocuments[$key]['data'];
        $contents = $this->isHttpsUrl($path)
            ? ($this->remoteReferenceLoader ?? new StreamRemoteReferenceLoader())->load($path, $this->maxRemoteReferenceBytes)
            : file_get_contents($path);
        if (!is_string($contents)) throw new SdkException("Could not read reference: {$path}");
        if ($this->isHttpsUrl($path) && strlen($contents) > $this->maxRemoteReferenceBytes) {
            throw new SdkException('Remote reference exceeds the configured size limit.');
        }
        $data = self::parseFile($path, $contents);
        if (!is_array($data)) throw new SdkException("Local reference must contain an object: {$path}");
        $this->externalDocuments[$key] = ['path' => $path, 'data' => $data];
        return $data;
    }

    private function isHttpsUrl(string $value): bool
    {
        return str_starts_with(strtolower($value), 'https://');
    }

    private function validateRemoteRefUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])) {
            throw new SdkException('Remote $ref must be an HTTPS URL without user info or query parameters.');
        }
        $host = strtolower($parts['host']);
        if ($this->allowedRemoteRefHosts === [] || !in_array($host, array_map('strtolower', $this->allowedRemoteRefHosts), true)) {
            throw new SdkException('Remote $ref host must be explicitly allow-listed.');
        }
        return $url;
    }

    private function resolveRemoteRelative(string $baseUrl, string $relativePath): string
    {
        if ($relativePath === '' || str_starts_with($relativePath, '//') || str_starts_with($relativePath, '/')
            || str_contains($relativePath, '\\') || str_contains($relativePath, '?')) {
            throw new SdkException('Invalid relative remote $ref path.');
        }
        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['host'])) throw new SdkException('Invalid remote $ref base URL.');
        $path = $base['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);
        $segments = [];
        foreach (explode('/', $directory.rawurldecode($relativePath)) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') array_pop($segments);
            else $segments[] = $segment;
        }
        $url = 'https://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '').'/'.implode('/', array_map('rawurlencode', $segments));
        return $this->validateRemoteRefUrl($url);
    }

    /** @return mixed */
    private static function parseFile(string $file, string $contents): mixed
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($extension, ['yaml', 'yml'], true)) {
            if (!class_exists(Yaml::class)) {
                throw new SdkException('Install dependencies with composer install to parse YAML.');
            }
            return Yaml::parse($contents);
        }
        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    public function resolve(array $node, int $depth = 0, ?string $sourcePath = null): array
    {
        return $this->resolveWithSource($node, $sourcePath, $depth)['value'];
    }

    /** @param array<string,mixed> $node @return array{value:array<string,mixed>,sourcePath:?string} */
    public function resolveWithSource(array $node, ?string $sourcePath = null, int $depth = 0): array
    {
        if (!isset($node['$ref'])) {
            return ['value' => $node, 'sourcePath' => $sourcePath ?? $this->sourcePath];
        }
        if ($depth > 32) {
            throw new SdkException('Reference depth exceeded (possible reference cycle).');
        }
        $ref = $node['$ref'];
        if (!is_string($ref)) throw new SdkException('Invalid $ref value.');
        $resolved = $this->resolveReference($ref, $sourcePath);
        return $this->resolveWithSource(
            array_replace($resolved['value'], array_diff_key($node, ['$ref' => true])),
            $resolved['sourcePath'],
            $depth + 1,
        );
    }
}
