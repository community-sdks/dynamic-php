<?php

declare(strict_types=1);
namespace CommunitySDKs\Developer;

use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\OpenApi\Document;

final readonly class DtoGenerator
{
    private const KEYWORDS = ['class', 'function', 'public', 'private', 'protected', 'static', 'readonly', 'enum', 'match', 'trait', 'interface', 'extends', 'implements', 'new', 'return', 'null', 'true', 'false'];

    public function __construct(private Document $document) {}

    public function generate(string $namespace = 'CommunitySDKs\\Generated'): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace) !== 1) {
            throw new SdkException('Generated DTO namespace is not a valid PHP namespace.');
        }
        $schemas = $this->document->data['components']['schemas'] ?? [];
        if (!is_array($schemas)) throw new SdkException('OpenAPI components.schemas must be an object.');
        $classNames = [];
        foreach (array_keys($schemas) as $name) {
            $className = $this->className((string) $name);
            if (isset($classNames[$className])) throw new SdkException("Schema names collide after PHP normalization: {$name}");
            $classNames[$className] = (string) $name;
        }
        $lines = ['<?php', '', 'declare(strict_types=1);', '', 'namespace '.$namespace.';', ''];
        foreach ($schemas as $schemaName => $schema) {
            if (!is_array($schema) || (($schema['type'] ?? null) !== 'object' && !isset($schema['properties']))) continue;
            $className = $this->className((string) $schemaName);
            $properties = $schema['properties'] ?? [];
            if (!is_array($properties)) throw new SdkException("Schema {$schemaName} properties must be an object.");
            $required = $schema['required'] ?? [];
            $requiredProperties = [];
            $optionalProperties = [];
            $usedNames = [];
            foreach ($properties as $jsonName => $property) {
                if (!is_array($property)) continue;
                $phpName = $this->propertyName((string) $jsonName);
                if (isset($usedNames[$phpName])) throw new SdkException("Schema {$schemaName} has properties that collide after PHP normalization.");
                $usedNames[$phpName] = true;
                $isRequired = is_array($required) && in_array($jsonName, $required, true);
                $types = $this->types($property);
                if (!$isRequired && !in_array('null', $types, true)) $types[] = 'null';
                $entry = [
                    'jsonName' => (string) $jsonName,
                    'phpName' => $phpName,
                    'nativeType' => $this->nativeType($types),
                    'docType' => $this->docType($types, $property),
                    'required' => $isRequired,
                ];
                if ($isRequired) $requiredProperties[] = $entry;
                else $optionalProperties[] = $entry;
            }
            $all = array_merge($requiredProperties, $optionalProperties);
            $lines[] = 'final readonly class '.$className.' implements \\JsonSerializable';
            $lines[] = '{';
            if ($all !== []) {
                $lines[] = '    /**';
                foreach ($all as $property) $lines[] = '     * @param '.$property['docType'].' $'.$property['phpName'];
                $lines[] = '     */';
            }
            $lines[] = '    public function __construct('; 
            foreach ($all as $index => $property) {
                $comma = $index === count($all) - 1 ? '' : ',';
                $default = $property['required'] ? '' : ' = null';
                $lines[] = '        public '.$property['nativeType'].' $'.$property['phpName'].$default.$comma;
            }
            $lines[] = '    ) {}';
            $lines[] = '';
            $lines[] = '    public function jsonSerialize(): array';
            $lines[] = '    {';
            $lines[] = '        return [';
            foreach ($all as $property) $lines[] = '            '.var_export($property['jsonName'], true).' => $this->'.$property['phpName'].',';
            $lines[] = '        ];';
            $lines[] = '    }';
            $lines[] = '}';
            $lines[] = '';
        }
        return implode("\n", $lines);
    }

    private function className(string $name): string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $class = implode('', array_map('ucfirst', $parts));
        if ($class === '' || ctype_digit($class[0])) $class = 'Schema'.$class;
        if (in_array(strtolower($class), self::KEYWORDS, true)) $class .= 'Schema';
        return $class;
    }

    private function propertyName(string $name): string
    {
        $property = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? '';
        if ($property === '' || ctype_digit($property[0])) $property = '_'.$property;
        if (in_array(strtolower($property), self::KEYWORDS, true)) $property .= '_';
        return $property;
    }

    /** @param array<string,mixed> $schema @return list<string> */
    private function types(array $schema): array
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            if (!preg_match('~^#/components/schemas/[^/]+$~', $schema['$ref'])) return ['mixed'];
            $parts = explode('/', $schema['$ref']);
            return [self::classNameStatic(rawurldecode(end($parts)))];
        }
        if (isset($schema['oneOf']) || isset($schema['anyOf'])) {
            $alternatives = $schema['oneOf'] ?? $schema['anyOf'];
            $types = [];
            foreach (is_array($alternatives) ? $alternatives : [] as $alternative) {
                if (is_array($alternative)) array_push($types, ...$this->types($alternative));
            }
            return $types === [] ? ['mixed'] : array_values(array_unique($types));
        }
        $types = array_map('strval', (array) ($schema['type'] ?? []));
        if ($types === [] && isset($schema['properties'])) $types = ['object'];
        if ($types === [] && isset($schema['items'])) $types = ['array'];
        return $types === [] ? ['mixed'] : array_values(array_unique($types));
    }

    private function nativeType(array $types): string
    {
        $mapped = array_map(static fn (string $type): string => match ($type) {
            'integer' => 'int', 'number' => 'int|float', 'boolean' => 'bool', 'string' => 'string',
            'object', 'array' => 'array', 'null' => 'null', default => 'mixed',
        }, $types);
        $flattened = [];
        foreach ($mapped as $type) array_push($flattened, ...explode('|', $type));
        $flattened = array_values(array_unique($flattened));
        if (in_array('mixed', $flattened, true)) return 'mixed';
        if (count($flattened) === 2 && in_array('null', $flattened, true)) {
            $other = $flattened[0] === 'null' ? $flattened[1] : $flattened[0];
            return '?'.$other;
        }
        sort($flattened);
        return implode('|', $flattened);
    }

    private function docType(array $types, array $schema): string
    {
        if (in_array('array', $types, true)) {
            $items = is_array($schema['items'] ?? null) ? $this->types($schema['items']) : ['mixed'];
            $itemTypes = array_map(static fn (string $type): string => match ($type) {
                'integer' => 'int', 'number' => 'int|float', 'boolean' => 'bool', 'string' => 'string',
                'object' => 'array<string, mixed>', 'array' => 'list<mixed>', 'null' => 'null', default => $type,
            }, $items);
            $itemType = implode('|', array_unique($itemTypes));
            return 'list<'.$itemType.'>'.(in_array('null', $types, true) ? '|null' : '');
        }
        $native = $this->nativeType($types);
        return $native[0] === '?' ? substr($native, 1).'|null' : $native;
    }

    private static function classNameStatic(string $name): string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $class = implode('', array_map('ucfirst', $parts));
        if ($class === '' || ctype_digit($class[0])) $class = 'Schema'.$class;
        if (in_array(strtolower($class), self::KEYWORDS, true)) $class .= 'Schema';
        return $class;
    }
}