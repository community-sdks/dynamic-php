<?php

declare(strict_types=1);
namespace CommunitySDKs\OpenApi;

use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Exception\ValidationException;

final class ParameterSerializer
{
    /** @param array<string,mixed> $parameter */
    public function contentValue(array $parameter, mixed $value): string
    {
        $content = $parameter['content'] ?? [];
        if (!is_array($content) || count($content) !== 1) {
            throw new SdkException('A parameter content definition must declare exactly one media type.');
        }
        $mediaType = strtolower(trim(explode(';', (string) array_key_first($content), 2)[0]));
        if ($mediaType === 'application/json' || str_ends_with($mediaType, '+json')) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        if ($mediaType === 'text/plain' && is_scalar($value)) return (string) $value;
        throw new SdkException("Unsupported parameter content media type: {$mediaType}");
    }

    public function contentQuery(string $name, string $value): string
    {
        return rawurlencode($name).'='.rawurlencode($value);
    }

    public function contentCookie(string $name, string $value): string
    {
        return rawurlencode($name).'='.rawurlencode($value);
    }

    /** @param array<string,mixed> $parameter */
    public function path(array $parameter, mixed $value): string
    {
        $name = $parameter['name'];
        $style = $parameter['style'] ?? 'simple';
        $explode = $parameter['explode'] ?? false;
        $encodedName = rawurlencode($name);
        if (is_scalar($value) || $value === null) {
            $encoded = rawurlencode($this->scalar($name, $value));
            return match ($style) {
                'simple' => $encoded,
                'label' => '.'.$encoded,
                'matrix' => ';'.$encodedName.'='.$encoded,
                default => throw new SdkException("Unsupported path parameter style: {$style}"),
            };
        }
        if (!is_array($value)) {
            throw new ValidationException(["{$name}: path parameter must be scalar or array"]);
        }

        if (array_is_list($value)) {
            $items = array_map(fn (mixed $item): string => $this->encodeValue($name, $item), $value);
            return match ($style) {
                'simple' => implode(',', $items),
                'label' => '.'.implode($explode ? '.' : ',', $items),
                'matrix' => $explode
                    ? implode('', array_map(fn (string $item): string => ';'.$encodedName.'='.$item, $items))
                    : ';'.$encodedName.'='.implode(',', $items),
                default => throw new SdkException("Unsupported path parameter style: {$style}"),
            };
        }

        $pairs = $this->pairs($name, $value);
        $flat = implode(',', array_merge(...array_map(static fn (array $pair): array => $pair, $pairs)));
        return match ($style) {
            'simple' => $explode
                ? implode(',', array_map(static fn (array $pair): string => $pair[0].'='.$pair[1], $pairs))
                : $flat,
            'label' => '.'.($explode
                ? implode('.', array_map(static fn (array $pair): string => $pair[0].'='.$pair[1], $pairs))
                : $flat),
            'matrix' => $explode
                ? implode('', array_map(static fn (array $pair): string => ';'.$pair[0].'='.$pair[1], $pairs))
                : ';'.$encodedName.'='.$flat,
            default => throw new SdkException("Unsupported path parameter style: {$style}"),
        };
    }

    /** @param array<string,mixed> $parameter @return list<string> encoded query pairs */
    public function query(array $parameter, mixed $value): array
    {
        $name = $parameter['name'];
        $style = $parameter['style'] ?? 'form';
        $explode = $parameter['explode'] ?? ($style === 'form');
        $allowReserved = (bool) ($parameter['allowReserved'] ?? false);
        $encodedName = $this->encode($name, $allowReserved);
        if (is_scalar($value) || $value === null) {
            return [$encodedName.'='.$this->encode($this->scalar($name, $value), $allowReserved)];
        }
        if (!is_array($value)) {
            throw new ValidationException(["{$name}: unsupported query value"]);
        }
        if (array_is_list($value)) {
            $items = array_map(fn (mixed $item): string => $this->encodeValue($name, $item, $allowReserved), $value);
            if ($style === 'form' && $explode) {
                return array_map(static fn (string $item): string => $encodedName.'='.$item, $items);
            }
            $delimiter = match ($style) {
                'form' => ',', 'spaceDelimited' => '%20', 'pipeDelimited' => '%7C',
                default => throw new SdkException("Unsupported query parameter style: {$style}"),
            };
            return [$encodedName.'='.implode($delimiter, $items)];
        }

        $pairs = $this->pairs($name, $value, $allowReserved);
        if ($style === 'deepObject') {
            if (!$explode) {
                throw new SdkException('deepObject query parameters require explode=true.');
            }
            $encodedName = $this->encode($name, $allowReserved);
            return array_map(fn (string|int $key, mixed $item): string =>
                $encodedName.'%5B'.$this->encode((string) $key, $allowReserved).'%5D='.$this->encodeValue($name, $item, $allowReserved),
                array_keys($value),
                array_values($value),
            );
        }
        if ($style !== 'form') {
            throw new SdkException("Unsupported query object style: {$style}");
        }
        if ($explode) {
            return array_map(static fn (array $pair): string => $pair[0].'='.$pair[1], $pairs);
        }
        $flat = implode(',', array_merge(...array_map(static fn (array $pair): array => $pair, $pairs)));
        return [$encodedName.'='.$flat];
    }

    /** @param array<string,mixed> $parameter */
    public function header(array $parameter, mixed $value): string
    {
        $name = $parameter['name'];
        if (is_scalar($value) || $value === null) {
            $serialized = $this->scalar($name, $value);
        } elseif (is_array($value)) {
            if (array_is_list($value)) {
                $serialized = implode(',', array_map(fn (mixed $item): string => $this->scalar($name, $item), $value));
            } else {
                $pairs = [];
                foreach ($value as $key => $item) {
                    $pairs[] = [(string) $key, $this->scalar($name, $item)];
                }
                $serialized = ($parameter['explode'] ?? false)
                    ? implode(',', array_map(static fn (array $pair): string => $pair[0].'='.$pair[1], $pairs))
                    : implode(',', array_merge(...array_map(static fn (array $pair): array => $pair, $pairs)));
            }
        } else {
            throw new ValidationException(["{$name}: unsupported header value"]);
        }
        if (preg_match('/[\r\n]/', $serialized)) {
            throw new ValidationException(["{$name}: invalid header"]);
        }
        return $serialized;
    }

    /** @param array<string,mixed> $parameter @return list<string> */
    public function cookie(array $parameter, mixed $value): array
    {
        $name = $parameter['name'];
        $style = $parameter['style'] ?? 'form';
        if ($style !== 'form') {
            throw new SdkException("Unsupported cookie parameter style: {$style}");
        }
        $explode = $parameter['explode'] ?? true;
        $encodedName = rawurlencode($name);
        if (is_scalar($value) || $value === null) {
            return [$encodedName.'='.rawurlencode($this->scalar($name, $value))];
        }
        if (!is_array($value)) {
            throw new ValidationException(["{$name}: unsupported cookie value"]);
        }
        if (array_is_list($value)) {
            $items = array_map(fn (mixed $item): string => rawurlencode($this->scalar($name, $item)), $value);
            return $explode
                ? array_map(static fn (string $item): string => $encodedName.'='.$item, $items)
                : [$encodedName.'='.implode(',', $items)];
        }
        $pairs = $this->pairs($name, $value);
        return $explode
            ? array_map(static fn (array $pair): string => $pair[0].'='.$pair[1], $pairs)
            : [$encodedName.'='.implode(',', array_merge(...array_map(static fn (array $pair): array => $pair, $pairs)))];
    }

    private function encodeValue(string $name, mixed $value, bool $allowReserved = false): string
    {
        return $this->encode($this->scalar($name, $value), $allowReserved);
    }

    private function scalar(string $name, mixed $value): string
    {
        if ($value === null) return '';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (!is_scalar($value)) {
            throw new ValidationException(["{$name}: parameter values must be scalar"]);
        }
        return (string) $value;
    }

    /** @param array<mixed> $value @return list<array{0:string,1:string}> */
    private function pairs(string $name, array $value, bool $allowReserved = false): array
    {
        $pairs = [];
        foreach ($value as $key => $item) {
            $pairs[] = [$this->encode((string) $key, $allowReserved), $this->encodeValue($name, $item, $allowReserved)];
        }
        return $pairs;
    }

    private function encode(string $value, bool $allowReserved): string
    {
        $encoded = rawurlencode($value);
        if ($allowReserved) {
            $encoded = str_replace(['%3A', '%2F', '%3F', '%23', '%5B', '%5D', '%40', '%21', '%24', '%26', '%27', '%28', '%29', '%2A', '%2B', '%2C', '%3B', '%3D'], [':', '/', '?', '#', '[', ']', '@', '!', '$', '&', "'", '(', ')', '*', '+', ',', ';', '='], $encoded);
        }
        return $encoded;
    }
}