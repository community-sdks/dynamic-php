<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

use CommunitySDKs\Exception\SdkException;

final class MultipartDecoder
{
    /** @return list<MultipartPart> */
    public function decode(Response $response): array
    {
        $contentType = $response->header('Content-Type') ?? '';
        if (preg_match('/(?:^|;)\s*boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $match) !== 1) {
            throw new SdkException('Multipart response is missing a boundary parameter.');
        }
        $boundary = $match[1] !== '' ? $match[1] : $match[2];
        if (preg_match('/^[0-9A-Za-z\x27()+_,.\/:=?-]{1,70}$/D', $boundary) !== 1) {
            throw new SdkException('Multipart response has an invalid boundary.');
        }
        $delimiter = '--'.$boundary;
        $body = $response->body;
        $start = strpos($body, $delimiter);
        if ($start === false || ($start !== 0 && substr($body, $start - 2, 2) !== "\r\n")) {
            throw new SdkException('Multipart response has no opening boundary.');
        }
        $body = substr($body, $start);
        $parts = [];
        while (str_starts_with($body, $delimiter)) {
            $body = substr($body, strlen($delimiter));
            if (str_starts_with($body, '--')) return $parts;
            if (!str_starts_with($body, "\r\n")) throw new SdkException('Malformed multipart boundary separator.');
            $body = substr($body, 2);
            $next = strpos($body, "\r\n".$delimiter);
            if ($next === false) throw new SdkException('Multipart response is missing a closing boundary.');
            $part = substr($body, 0, $next);
            $body = substr($body, $next + 2);
            $parts[] = $this->part($part);
        }
        throw new SdkException('Malformed multipart response boundary sequence.');
    }

    private function part(string $raw): MultipartPart
    {
        $separator = strpos($raw, "\r\n\r\n");
        if ($separator === false) throw new SdkException('Multipart part is missing its header separator.');
        $headerBlock = substr($raw, 0, $separator);
        $body = substr($raw, $separator + 4);
        $headers = [];
        foreach (explode("\r\n", $headerBlock) as $line) {
            if (!str_contains($line, ':')) throw new SdkException('Malformed multipart part header.');
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
        }
        $disposition = $this->header($headers, 'Content-Disposition') ?? '';
        $name = $this->dispositionParameter($disposition, 'name');
        if ($name === null) throw new SdkException('Multipart part is missing its field name.');
        $filename = $this->dispositionParameter($disposition, 'filename');
        if ($filename === null && preg_match('/(?:^|;)\s*filename\*=UTF-8\'\'([^;]+)/i', $disposition, $match) === 1) {
            $filename = rawurldecode($match[1]);
        }
        $contentType = $this->header($headers, 'Content-Type');
        $baseType = strtolower(trim(explode(';', $contentType ?? '', 2)[0]));
        $data = $body;
        if ($baseType === 'application/json' || str_ends_with($baseType, '+json')) {
            try {
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new SdkException('Multipart JSON part is invalid.', previous: $e);
            }
        }
        return new MultipartPart($name, $filename, $contentType, $headers, $body, $data);
    }

    /** @param array<string,string> $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) if (strcasecmp($key, $name) === 0) return $value;
        return null;
    }

    private function dispositionParameter(string $header, string $name): ?string
    {
        if (preg_match('/(?:^|;)\s*'.preg_quote($name, '/').'="((?:\\\\.|[^"\\\\])*)"/i', $header, $match) === 1) {
            return stripcslashes($match[1]);
        }
        if (preg_match('/(?:^|;)\s*'.preg_quote($name, '/').'=([^;\s]+)/i', $header, $match) === 1) return $match[1];
        return null;
    }
}