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

    /** @param array<string,mixed> $data */
    public function __construct(public readonly array $data, ?string $sourcePath = null)
    {
        $this->sourcePath = $sourcePath === null ? null : (realpath($sourcePath) ?: $sourcePath);
        $this->sourceDirectory = $this->sourcePath === null ? null : dirname($this->sourcePath);
        if (!isset($data['openapi'], $data['paths']) || !is_array($data['paths'])) {
            throw new SdkException('Expected an OpenAPI 3.x document with paths.');
        }
        if (!str_starts_with((string) $data['openapi'], '3.')) {
            throw new SdkException('Only OpenAPI 3.x is supported.');
        }
    }

    public static function fromFile(string $file): self
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
        return new self($value, $realPath);
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
            if ($currentPath === null || $this->sourceDirectory === null) {
                throw new SdkException('Cross-file $ref requires a specification loaded from a local file.');
            }
            if (str_starts_with($ref, '//') || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $ref) === 1 || str_contains($ref, '\\')) {
                throw new SdkException('Network and absolute-path $ref are not supported: '.$ref);
            }
            [$pathPart, $fragmentPart] = array_pad(explode('#', $ref, 2), 2, '');
            if (str_contains($pathPart, '?')) throw new SdkException('Query strings are not supported in $ref paths.');
            $fragment = $fragmentPart === '' ? '' : '#'.$fragmentPart;
            $resolved = realpath(dirname($currentPath).DIRECTORY_SEPARATOR.rawurldecode($pathPart));
            if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) throw new SdkException("Cannot read local reference: {$pathPart}");
            $root = rtrim($this->sourceDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            if ($resolved !== $this->sourcePath && !str_starts_with($resolved, $root)) {
                throw new SdkException('Local $ref resolves outside the specification directory.');
            }
            $documentPath = $resolved;
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
        $contents = file_get_contents($path);
        if (!is_string($contents)) throw new SdkException("Could not read local reference: {$path}");
        $data = self::parseFile($path, $contents);
        if (!is_array($data)) throw new SdkException("Local reference must contain an object: {$path}");
        $this->externalDocuments[$key] = ['path' => $path, 'data' => $data];
        return $data;
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
        if (!isset($node['$ref'])) {
            return $node;
        }
        if ($depth > 32) {
            throw new SdkException('Reference depth exceeded (possible reference cycle).');
        }
        $ref = $node['$ref'];
        if (!is_string($ref)) throw new SdkException('Invalid $ref value.');
        $resolved = $this->resolveReference($ref, $sourcePath);
        return $this->resolve(array_replace($resolved['value'], array_diff_key($node, ['$ref' => true])), $depth + 1, $resolved['sourcePath']);
    }
}
