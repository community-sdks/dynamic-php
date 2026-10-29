<?php

declare(strict_types=1);
namespace CommunitySDKs\OpenApi;

use CommunitySDKs\Exception\ValidationException;
use CommunitySDKs\Http\Upload;

/** Small strict JSON-schema subset; unsupported keywords are not silently promised. */
final readonly class Validator
{
    public function __construct(private Document $document) {}

    /** @param array<string,mixed> $schema */
    public function assert(mixed $value, array $schema, string $location = '$'): void
    {
        $issues = $this->issues($value, $schema, $location);
        if ($issues !== []) {
            throw new ValidationException($issues);
        }
    }

    /** @param array<string,mixed> $schema @return list<string> */
    private function issues(mixed $value, array $schema, string $at, int $depth = 0): array
    {
        if ($depth > 32) {
            return ["{$at}: schema recursion limit exceeded"];
        }
        if ($value instanceof Upload) {
            $value = $value->contents;
        }
        $schema = $this->document->resolve($schema);
        if (isset($schema['allOf'])) {
            $errors = [];
            foreach ($schema['allOf'] as $part) {
                array_push($errors, ...$this->issues($value, $part, $at, $depth + 1));
            }
            return $errors;
        }
        if (isset($schema['oneOf']) || isset($schema['anyOf'])) {
            $one = isset($schema['oneOf']);
            $matches = 0;
            foreach ($schema[$one ? 'oneOf' : 'anyOf'] as $option) {
                $matches += $this->issues($value, $option, $at, $depth + 1) === [] ? 1 : 0;
            }
            return ($one ? $matches === 1 : $matches >= 1) ? [] : ["{$at}: union schema mismatch"];
        }
        if ($value === null) {
            $type = $schema['type'] ?? null;
            return (($schema['nullable'] ?? false) || $type === 'null' || (is_array($type) && in_array('null', $type, true))) ? [] : ["{$at}: null not allowed"];
        }
        $types = (array) ($schema['type'] ?? []);
        if ($types && !in_array(true, array_map(fn ($type) => match ($type) {
            'object' => is_array($value) && !array_is_list($value) || $value === [],
            'array' => is_array($value) && array_is_list($value),
            'integer' => is_int($value), 'number' => is_int($value) || is_float($value),
            'string' => is_string($value), 'boolean' => is_bool($value),
            'null' => $value === null, default => false,
        }, $types), true)) {
            return ["{$at}: expected ".implode('|', $types)];
        }
        $errors = [];
        if (array_key_exists('enum', $schema) && !in_array($value, $schema['enum'], true)) {
            $errors[] = "{$at}: value is not in enum";
        }
        if (isset($schema['const']) && $schema['const'] !== $value) {
            $errors[] = "{$at}: const mismatch";
        }
        if (is_string($value)) {
            if (isset($schema['minLength']) && mbLen($value) < $schema['minLength']) $errors[] = "{$at}: too short";
            if (isset($schema['maxLength']) && mbLen($value) > $schema['maxLength']) $errors[] = "{$at}: too long";
            if (isset($schema['pattern']) && @preg_match('~'.str_replace('~', '\\~', $schema['pattern']).'~u', $value) === 0) $errors[] = "{$at}: pattern mismatch";
            if (($schema['format'] ?? '') === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) $errors[] = "{$at}: invalid email";
            if (($schema['format'] ?? '') === 'uri' && !filter_var($value, FILTER_VALIDATE_URL)) $errors[] = "{$at}: invalid URI";
        }
        if (is_numeric($value) && !is_string($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) $errors[] = "{$at}: below minimum";
            if (isset($schema['maximum']) && $value > $schema['maximum']) $errors[] = "{$at}: above maximum";
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                if (isset($schema['minItems']) && count($value) < $schema['minItems']) $errors[] = "{$at}: too few items";
                if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) $errors[] = "{$at}: too many items";
                foreach ($value as $i => $item) {
                    if (isset($schema['items'])) array_push($errors, ...$this->issues($item, $schema['items'], "{$at}[{$i}]", $depth + 1));
                }
            } else {
                foreach ($schema['required'] ?? [] as $key) {
                    if (!array_key_exists($key, $value)) $errors[] = "{$at}.{$key}: required";
                }
                foreach ($value as $key => $item) {
                    if (isset($schema['properties'][$key])) {
                        array_push($errors, ...$this->issues($item, $schema['properties'][$key], "{$at}.{$key}", $depth + 1));
                    } elseif (($schema['additionalProperties'] ?? true) === false) {
                        $errors[] = "{$at}.{$key}: unknown property";
                    } elseif (is_array($schema['additionalProperties'] ?? null)) {
                        array_push($errors, ...$this->issues($item, $schema['additionalProperties'], "{$at}.{$key}", $depth + 1));
                    }
                }
            }
        }
        return $errors;
    }
}

/** Unicode-aware when mbstring exists, graceful fallback otherwise. */
function mbLen(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}
