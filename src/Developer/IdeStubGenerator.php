<?php

declare(strict_types=1);
namespace CommunitySDKs\Developer;

use CommunitySDKs\OpenApi\{Document, Registry};

final readonly class IdeStubGenerator
{
    private const PHP_KEYWORDS = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue',
        'declare', 'default', 'do', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch',
        'endwhile', 'enum', 'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global',
        'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset', 'list',
        'match', 'namespace', 'new', 'or', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'require_once',
        'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield', 'yield_from',
        'true', 'false', 'null', 'self', 'parent',
    ];

    public function __construct(private Document $document) {}

    public function generate(string $className = 'CommunitySdkIdeClient'): string
    {
        $registry = new Registry($this->document);
        $lines = [
            '<?php',
            '',
            '/**',
            ' * Generated PHPDoc hints only. This class contains no runtime implementations.',
            ' * @method \CommunitySDKs\Http\Result<mixed> call(string $operationId, array $arguments = [])',
        ];
        foreach ($registry->all() as $id => $operation) {
            if (!$this->isIdentifier($id) || in_array(strtolower($id), self::PHP_KEYWORDS, true)) continue;
            if (in_array($id, ['call', 'operations', 'fromFile', 'paginate', 'download', 'streamEvents'], true)) continue;
            $requiredParameters = [];
            $optionalParameters = [];
            $parameterNames = [];
            $operationParameters = $operation['_parameters'];
            usort($operationParameters, static fn (array $left, array $right): int => (int) ($right['required'] ?? false) <=> (int) ($left['required'] ?? false));
            foreach ($operationParameters as $parameter) {
                $name = $parameter['name'];
                if (!is_string($name) || !$this->isIdentifier($name) || in_array(strtolower($name), self::PHP_KEYWORDS, true) || isset($parameterNames[$name])) continue 2;
                $parameterNames[$name] = true;
                $type = $this->type($parameter['schema'] ?? [], []);
                $required = (bool) ($parameter['required'] ?? false);
                if (!$required && !str_contains($type, 'null')) $type = $this->union([$type, 'null']);
                $signature = $type.' $'.$name.($required ? '' : ' = null');
                if ($required) $requiredParameters[] = $signature;
                else $optionalParameters[] = $signature;
            }
            if (isset($operation['requestBody'])) {
                $requestBody = $this->document->resolve($operation['requestBody']);
                if (isset($parameterNames['body'])) continue;
                $requestType = 'mixed';
                foreach ($requestBody['content'] ?? [] as $media) {
                    if (isset($media['schema'])) {
                        $requestType = $this->union([$requestType === 'mixed' ? '' : $requestType, $this->type($media['schema'], [])]);
                    }
                }
                if ($requestType === '') $requestType = 'mixed';
                $required = (bool) ($requestBody['required'] ?? false);
                if (!$required && !str_contains($requestType, 'null')) $requestType = $this->union([$requestType, 'null']);
                $signature = $requestType.' $body'.($required ? '' : ' = null');
                if ($required) $requiredParameters[] = $signature;
                else $optionalParameters[] = $signature;
            }
            $returnType = $this->responseType($operation);
            $lines[] = ' * @method \\CommunitySDKs\\Http\\Result<'.$returnType.'> '.$id.'('.implode(', ', array_merge($requiredParameters, $optionalParameters)).')';
        }
        $lines[] = ' */';
        $lines[] = 'class '.$className.' extends \\CommunitySDKs\\Client {}';
        $lines[] = '';
        return implode("\n", $lines);
    }

    private function responseType(array $operation): string
    {
        $types = [];
        foreach ($operation['responses'] ?? [] as $response) {
            if (!is_array($response)) continue;
            $response = $this->document->resolve($response);
            foreach ($response['content'] ?? [] as $content) {
                if (isset($content['schema']) && is_array($content['schema'])) $types[] = $this->type($content['schema'], []);
            }
        }
        return $types === [] ? 'mixed' : $this->union($types);
    }

    /** @param array<string,mixed> $schema @param array<string,true> $visited */
    private function type(array $schema, array $visited): string
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $reference = $schema['$ref'];
            if (isset($visited[$reference])) return 'array<string, mixed>';
            $visited[$reference] = true;
            try {
                return $this->type($this->document->resolve($schema), $visited);
            } catch (\Throwable) {
                return 'mixed';
            }
        }
        if (isset($schema['oneOf']) || isset($schema['anyOf'])) {
            $alternatives = $schema['oneOf'] ?? $schema['anyOf'];
            return $this->union(array_map(fn (array $part): string => $this->type($part, $visited), array_filter($alternatives, 'is_array')));
        }
        if (isset($schema['allOf'])) return 'array<string, mixed>';
        $types = (array) ($schema['type'] ?? []);
        if (($schema['nullable'] ?? false) === true) $types[] = 'null';
        if ($types === []) {
            if (isset($schema['properties'])) $types = ['object'];
            elseif (isset($schema['items'])) $types = ['array'];
            else return 'mixed';
        }
        return $this->union(array_map(fn (string $type): string => match ($type) {
            'integer' => 'int', 'number' => 'int|float', 'boolean' => 'bool', 'string' => 'string',
            'null' => 'null', 'array' => 'list<'.(is_array($schema['items'] ?? null) ? $this->type($schema['items'], $visited) : 'mixed').'>',
            'object' => $this->objectType($schema, $visited), default => 'mixed',
        }, array_map('strval', $types)));
    }

    /** @param array<string,mixed> $schema @param array<string,true> $visited */
    private function objectType(array $schema, array $visited): string
    {
        if (!isset($schema['properties']) || !is_array($schema['properties']) || $schema['properties'] === []) {
            return 'array<string, mixed>';
        }
        $required = $schema['required'] ?? [];
        $properties = [];
        foreach ($schema['properties'] as $name => $property) {
            if (!is_array($property)) continue;
            $key = $this->isIdentifier((string) $name) ? (string) $name : "'".str_replace("'", "\\'", (string) $name)."'";
            $optional = !in_array($name, $required, true);
            $properties[] = $key.($optional ? '?' : '').': '.$this->type($property, $visited);
        }
        return 'array{'.implode(', ', $properties).'}';
    }

    /** @param list<string> $types */
    private function union(array $types): string
    {
        $types = array_values(array_unique(array_filter($types, static fn (string $type): bool => $type !== '')));
        if ($types === []) return 'mixed';
        if (in_array('mixed', $types, true)) return 'mixed';
        if (count($types) === 2 && in_array('null', $types, true)) {
            $nonNull = $types[0] === 'null' ? $types[1] : $types[0];
            if (in_array($nonNull, ['int', 'float', 'string', 'bool'], true)) return '?'.$nonNull;
        }
        sort($types);
        return implode('|', $types);
    }

    private function isIdentifier(string $value): bool
    {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $value) === 1;
    }
}