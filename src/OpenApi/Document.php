<?php

declare(strict_types=1);
namespace CommunitySDKs\OpenApi;

use CommunitySDKs\Exception\SdkException;
use Symfony\Component\Yaml\Yaml;

/** In-memory OpenAPI document. Only local #/ JSON references are dereferenced. */
final readonly class Document
{
    /** @param array<string,mixed> $data */
    public function __construct(public array $data)
    {
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
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($extension, ['yaml', 'yml'], true)) {
            if (!class_exists(Yaml::class)) {
                throw new SdkException('Install dependencies with composer install to parse YAML.');
            }
            $value = Yaml::parse($contents);
        } else {
            $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        }
        if (!is_array($value)) {
            throw new SdkException('Specification must be an object.');
        }
        return new self($value);
    }

    /** @return array<string,mixed> */
    public function resolve(array $node, int $depth = 0): array
    {
        if (!isset($node['$ref'])) {
            return $node;
        }
        if ($depth > 32) {
            throw new SdkException('Reference depth exceeded (possible reference cycle).');
        }
        $ref = $node['$ref'];
        if (!is_string($ref) || !str_starts_with($ref, '#/')) {
            throw new SdkException('Remote and cross-file $ref are not supported: '.(string)$ref);
        }
        $value = $this->data;
        foreach (explode('/', substr($ref, 2)) as $part) {
            $part = str_replace(['~1', '~0'], ['/', '~'], $part);
            if (!is_array($value) || !array_key_exists($part, $value)) {
                throw new SdkException("Unresolved reference {$ref}");
            }
            $value = $value[$part];
        }
        if (!is_array($value)) {
            throw new SdkException("Reference {$ref} is not an object");
        }
        return $this->resolve(array_replace($value, array_diff_key($node, ['$ref' => true])), $depth + 1);
    }
}
