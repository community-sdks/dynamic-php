<?php

declare(strict_types=1);
namespace CommunitySDKs\OpenApi;

use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Exception\ValidationException;
use CommunitySDKs\Http\Upload;

final class RequestBodySerializer
{
    /** @param array<string,mixed> $media @return array{body:string,contentType:string} */
    public function serialize(mixed $value, string $mediaType, array $media): array
    {
        $baseType = strtolower(trim(explode(';', $mediaType, 2)[0]));
        if ($this->isJson($baseType)) {
            if ($value instanceof Upload) {
                throw new ValidationException(['Upload values require a binary or multipart request body.']);
            }
            return ['body' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'contentType' => $mediaType];
        }
        if ($baseType === 'application/x-www-form-urlencoded') {
            return ['body' => $this->form($value, $media['encoding'] ?? []), 'contentType' => $mediaType];
        }
        if ($baseType === 'multipart/form-data') {
            $boundary = 'CommunitySDKs'.bin2hex(random_bytes(18));
            return [
                'body' => $this->multipart($value, $media['encoding'] ?? [], $boundary),
                'contentType' => $mediaType.'; boundary='.$boundary,
            ];
        }
        if ($value instanceof Upload) {
            return ['body' => $value->contents, 'contentType' => $mediaType];
        }
        if (is_string($value)) {
            return ['body' => $value, 'contentType' => $mediaType];
        }
        throw new SdkException("Request body value is not supported for {$mediaType}.");
    }

    /** @param array<string,mixed> $content */
    public function selectMediaType(array $content, mixed $value): string
    {
        if ($content === []) {
            throw new SdkException('Request body does not declare any content media types.');
        }
        $types = array_keys($content);
        $preferred = $value instanceof Upload
            ? ['application/octet-stream', 'multipart/form-data']
            : ['application/json', 'application/x-www-form-urlencoded', 'multipart/form-data'];
        foreach ($preferred as $candidate) {
            foreach ($types as $type) {
                if (strtolower(trim(explode(';', $type, 2)[0])) === $candidate) {
                    return $type;
                }
            }
        }
        return $types[0];
    }

    private function isJson(string $mediaType): bool
    {
        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }

    /** @param array<string,mixed> $encoding */
    private function form(mixed $value, array $encoding): string
    {
        if (!is_array($value)) {
            throw new ValidationException(['Form request body must be an object or array of fields.']);
        }
        $pairs = [];
        foreach ($value as $name => $field) {
            $name = (string) $name;
            $fieldEncoding = $encoding[$name] ?? [];
            $style = $fieldEncoding['style'] ?? 'form';
            $explode = $fieldEncoding['explode'] ?? ($style === 'form');
            if ($field instanceof Upload) {
                throw new ValidationException(["{$name}: file uploads require multipart/form-data or a binary media type."]);
            }
            if (is_array($field)) {
                if (array_is_list($field)) {
                    $items = array_map(fn (mixed $item): string => $this->scalar($name, $item), $field);
                    if ($style === 'deepObject') {
                        throw new SdkException('deepObject cannot serialize an array request-body field.');
                    }
                    if ($style === 'form' && $explode) {
                        foreach ($items as $item) $pairs[] = [$name, $item];
                    } else {
                        $delimiter = match ($style) {
                            'form' => ',', 'spaceDelimited' => ' ', 'pipeDelimited' => '|',
                            default => throw new SdkException("Unsupported form field style: {$style}"),
                        };
                        $pairs[] = [$name, implode($delimiter, $items)];
                    }
                } elseif ($style === 'deepObject') {
                    foreach ($field as $key => $item) $pairs[] = [$name.'['.$key.']', $this->scalar($name, $item)];
                } elseif ($style === 'form' && $explode) {
                    foreach ($field as $key => $item) $pairs[] = [(string) $key, $this->scalar($name, $item)];
                } elseif ($style === 'form') {
                    $flat = [];
                    foreach ($field as $key => $item) array_push($flat, (string) $key, $this->scalar($name, $item));
                    $pairs[] = [$name, implode(',', $flat)];
                } else {
                    throw new SdkException("Unsupported form object style: {$style}");
                }
            } else {
                $pairs[] = [$name, $this->scalar($name, $field)];
            }
        }
        return implode('&', array_map(static fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]), $pairs));
    }

    /** @param array<string,mixed> $encoding */
    private function multipart(mixed $value, array $encoding, string $boundary): string
    {
        if (!is_array($value)) {
            throw new ValidationException(['Multipart request body must be an object or array of fields.']);
        }
        $parts = [];
        foreach ($value as $name => $field) {
            $name = (string) $name;
            $fieldEncoding = $encoding[$name] ?? [];
            $style = $fieldEncoding['style'] ?? 'form';
            $explode = $fieldEncoding['explode'] ?? ($style === 'form');
            $fields = [];
            if (is_array($field) && !array_is_list($field) && $style === 'form' && $explode) {
                foreach ($field as $key => $item) $fields[] = [(string) $key, $item];
            } elseif (is_array($field) && array_is_list($field) && $explode) {
                foreach ($field as $item) $fields[] = [$name, $item];
            } elseif (is_array($field) && !array_is_list($field) && $style === 'deepObject') {
                foreach ($field as $key => $item) $fields[] = [$name.'['.$key.']', $item];
            } elseif (is_array($field)) {
                $fields[] = [$name, implode(',', array_map(fn (mixed $item): string => $this->scalar($name, $item), $field))];
            } else {
                $fields[] = [$name, $field];
            }
            foreach ($fields as [$fieldName, $fieldValue]) {
                $parts[] = $this->multipartPart((string) $fieldName, $fieldValue, $fieldEncoding);
            }
        }
        return '--'.$boundary."\r\n".implode("\r\n--{$boundary}\r\n", $parts)."\r\n--{$boundary}--\r\n";
    }

    /** @param array<string,mixed> $encoding */
    private function multipartPart(string $name, mixed $value, array $encoding): string
    {
        if (preg_match('/[\r\n]/', $name)) {
            throw new ValidationException(['Multipart field names cannot contain line breaks.']);
        }
        $dispositionName = str_replace(['\\', '"'], ['\\\\', '\\"'], $name);
        $headers = ['Content-Disposition: form-data; name="'.$dispositionName.'"'];
        if ($value instanceof Upload) {
            $filename = str_replace(['\\', '"'], ['\\\\', '\\"'], $value->filename);
            $headers[0] .= '; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($value->filename);
            $contentType = $encoding['contentType'] ?? $value->contentType;
            $contents = $value->contents;
        } elseif (is_array($value)) {
            $contentType = $encoding['contentType'] ?? 'application/json';
            $contents = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } else {
            $contentType = $encoding['contentType'] ?? 'text/plain; charset=UTF-8';
            $contents = $this->scalar($name, $value);
        }
        if (!is_string($contentType) || preg_match('/[\r\n]/', $contentType)) {
            throw new ValidationException(["{$name}: invalid multipart content type."]);
        }
        $headers[] = 'Content-Type: '.$contentType;
        return implode("\r\n", $headers)."\r\n\r\n".$contents;
    }

    private function scalar(string $name, mixed $value): string
    {
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (!is_scalar($value)) throw new ValidationException(["{$name}: form fields must be scalar values."]);
        return (string) $value;
    }
}