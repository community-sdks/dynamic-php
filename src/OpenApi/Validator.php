<?php

declare(strict_types=1);
namespace CommunitySDKs\OpenApi;

use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Exception\ValidationException;
use CommunitySDKs\Http\Upload;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator as JsonSchemaValidator;

final readonly class Validator
{
    private JsonSchemaValidator $engine;

    public function __construct(private Document $document)
    {
        $this->engine = new JsonSchemaValidator(max_errors: 25, stop_at_first_error: false);
    }

    /** @param array<string,mixed> $schema */
    public function assert(mixed $value, array $schema, string $location = '$', ?string $sourcePath = null): void
    {
        $sourcePath ??= $this->document->sourcePath();
        $this->assertLocalReferences($schema, sourcePath: $sourcePath);
        $normalizedSchema = $this->normalizeSchema($schema, sourcePath: $sourcePath);
        $schema = $this->toOpisValue($normalizedSchema, 'schema');
        $referenceRoot = ['root' => $this->normalizeSchema($this->document->data, false, $sourcePath)];
        foreach ($this->document->externalDocuments() as $key => $external) {
            $referenceRoot['external'][$key] = $this->normalizeSchema($external['data'], false, $external['path']);
        }
        $referenceRoot = $this->toOpisValue($referenceRoot, 'document');
        $schemaId = 'https://community-sdks.invalid/schema/'.hash('sha256', serialize($schema));
        $root = (object) [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => $schemaId,
            '$defs' => $referenceRoot,
            'allOf' => [$schema],
        ];
        $data = $this->normalizeData($value, $normalizedSchema);
        try {
            $result = $this->engine->validate($data, $root);
        } catch (\Throwable $e) {
            throw new SdkException('Could not validate schema: '.$e->getMessage(), previous: $e);
        }
        if (!$result->isValid()) {
            $formatter = new ErrorFormatter();
            $errors = $formatter->formatFlat($result->error());
            throw new ValidationException(array_map(static fn (string $error): string => $location.': '.$error, $errors));
        }
    }

    /** @param array<string,mixed> $schema @return array<string,mixed> */
    private function normalizeSchema(array $schema, bool $rejectExternal = true, ?string $sourcePath = null): array
    {
        $result = [];
        foreach ($schema as $key => $value) {
            if ($key === '$ref') {
                if (!is_string($value)) throw new SdkException('Invalid $ref value.');
                try {
                    $reference = $this->document->resolveReference($value, $sourcePath);
                } catch (SdkException $e) {
                    if ($rejectExternal) throw $e;
                    $result[$key] = $value;
                    continue;
                }
                $result[$key] = '#/$defs/'.$reference['key'].$reference['pointer'];
            } elseif (in_array($key, ['nullable', '$id', '$schema', '$anchor', '$dynamicAnchor'], true)) {
                continue;
            } elseif (is_array($value)) {
                if (in_array($key, ['allOf', 'anyOf', 'oneOf', 'prefixItems', 'examples', 'enum', 'required'], true)) {
                    $result[$key] = array_map(fn (mixed $item): mixed => is_array($item) ? $this->normalizeSchema($item, $rejectExternal, $sourcePath) : $item, $value);
                } else {
                    $result[$key] = $this->normalizeSchema($value, $rejectExternal, $sourcePath);
                }
            } else {
                $result[$key] = $value;
            }
        }
        if (isset($schema['discriminator']) && is_array($schema['discriminator'])) {
            $discriminator = $schema['discriminator'];
            $property = $discriminator['propertyName'] ?? null;
            if (is_string($property) && isset($result['oneOf']) && is_array($result['oneOf'])) {
                $mapping = $discriminator['mapping'] ?? [];
                foreach ($result['oneOf'] as $index => $option) {
                    if (!is_array($option) || !is_string($option['$ref'] ?? null)) continue;
                    $reference = $option['$ref'];
                    $tag = null;
                    foreach (is_array($mapping) ? $mapping : [] as $mappedTag => $mappedReference) {
                        if (!is_string($mappedReference)) continue;
                        try {
                            $mappedInfo = $this->document->resolveReference($mappedReference, $sourcePath);
                            $normalizedReference = '#/$defs/'.$mappedInfo['key'].$mappedInfo['pointer'];
                        } catch (SdkException) {
                            $normalizedReference = $mappedReference;
                        }
                        if ($normalizedReference === $reference) $tag = (string) $mappedTag;
                    }
                    if ($tag === null) {
                        $tag = rawurldecode(substr($reference, strrpos($reference, '/') + 1));
                    }
                    $option['allOf'][] = [
                        'type' => 'object',
                        'required' => [$property],
                        'properties' => [$property => ['const' => $tag]],
                    ];
                    $result['oneOf'][$index] = $option;
                }
            }
        }
        if (($schema['nullable'] ?? false) === true && isset($schema['type'])) {
            $types = (array) $schema['type'];
            if (!in_array('null', $types, true)) $types[] = 'null';
            $result['type'] = count($types) === 1 ? $types[0] : $types;
        }
        return $result;
    }

    /** @param array<string,mixed> $schema @param array<string,true> $visited */
    private function assertLocalReferences(array $schema, array $visited = [], int $depth = 0, ?string $sourcePath = null): void
    {
        if ($depth > 64) throw new SdkException('Schema reference depth exceeded.');
        foreach ($schema as $key => $value) {
            if ($key === '$ref') {
                if (!is_string($value)) throw new SdkException('Invalid $ref value.');
                $reference = $this->document->resolveReference($value, $sourcePath);
                $identity = $reference['key'].$reference['pointer'];
                if (isset($visited[$identity])) continue;
                $this->assertLocalReferences($reference['value'], $visited + [$identity => true], $depth + 1, $reference['sourcePath']);
            } elseif (is_array($value)) {
                $this->assertLocalReferences($value, $visited, $depth + 1, $sourcePath);
            }
        }
    }

    private function toOpisValue(mixed $value, string $key, bool $emptyList = false): mixed
    {
        if (!is_array($value)) return $value;
        $listKeys = ['allOf', 'anyOf', 'oneOf', 'prefixItems', 'examples', 'enum', 'required', 'type', 'parameters', 'servers', 'tags', 'security', 'responses'];
        $isList = in_array($key, $listKeys, true) || (array_is_list($value) && $value !== []);
        if ($value === []) return $emptyList || $isList ? [] : (object) [];
        if ($isList) return array_map(fn (mixed $item): mixed => $this->toOpisValue($item, 'item', true), $value);
        $object = new \stdClass();
        foreach ($value as $name => $item) $object->{(string) $name} = $this->toOpisValue($item, (string) $name);
        return $object;
    }

    /** @param array<string,mixed> $schema */
    private function normalizeData(mixed $value, array $schema): mixed
    {
        if ($value instanceof Upload) return $value->contents;
        if ($value instanceof \JsonSerializable) $value = $value->jsonSerialize();
        if (is_object($value)) {
            $value = get_object_vars($value);
            $object = new \stdClass();
            foreach ($value as $key => $item) {
                $childSchema = $schema['properties'][$key] ?? (is_array($schema['additionalProperties'] ?? null) ? $schema['additionalProperties'] : []);
                $object->{(string) $key} = $this->normalizeData($item, $childSchema);
            }
            return $object;
        }
        if (!is_array($value)) return $value;
        if (array_is_list($value) && $value !== []) {
            $itemSchema = is_array($schema['items'] ?? null) ? $schema['items'] : [];
            return array_map(fn (mixed $item): mixed => $this->normalizeData($item, $itemSchema), $value);
        }
        if ($value === [] && !$this->expectsObject($schema)) return [];
        if (array_is_list($value) && $value !== []) return array_map(fn (mixed $item): mixed => $this->normalizeData($item, []), $value);
        $object = new \stdClass();
        foreach ($value as $key => $item) {
            $childSchema = $schema['properties'][$key] ?? (is_array($schema['additionalProperties'] ?? null) ? $schema['additionalProperties'] : []);
            $object->{(string) $key} = $this->normalizeData($item, $childSchema);
        }
        return $object;
    }

    /** @param array<string,mixed> $schema */
    private function expectsObject(array $schema): bool
    {
        if (isset($schema['$ref']) && is_string($schema['$ref']) && str_starts_with($schema['$ref'], '#/')) {
            $target = $this->document->data;
            foreach (explode('/', substr($schema['$ref'], 2)) as $part) {
                $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                if (!is_array($target) || !array_key_exists($part, $target)) return false;
                $target = $target[$part];
            }
            if (is_array($target) && $this->expectsObject($target)) return true;
        }
        if (($schema['type'] ?? null) === 'object' || isset($schema['properties'], $schema['patternProperties'])) return true;
        foreach (['allOf', 'anyOf', 'oneOf'] as $composition) {
            foreach ($schema[$composition] ?? [] as $part) {
                if (is_array($part) && $this->expectsObject($part)) return true;
            }
        }
        return false;
    }
}
