<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

use CommunitySDKs\Exception\TransportException;

/** PHP stream adapter. Inject your own Transport to use Guzzle, Symfony, etc. */
final readonly class StreamTransport implements StreamingTransport
{
    public function __construct(private float $timeout = 15.0) {}

    public function send(Request $request): Response
    {
        $lines = [];
        foreach ($request->headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $context = stream_context_create(['http' => [
            'method' => $request->method,
            'header' => implode("\r\n", $lines),
            'content' => $request->body,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'follow_location' => 0, // Avoid leaking auth on redirect to another host.
        ]]);
        $body = @file_get_contents($request->url, false, $context);
        if ($body === false) {
            throw new TransportException('HTTP connection failed (timeout, DNS or TLS error).');
        }
        $localVariables = get_defined_vars();
        if (function_exists('http_get_last_response_headers')) {
            $headers = http_get_last_response_headers();
        } else {
            $headers = $localVariables['http_response_header'];
        }
        if (!is_array($headers)) $headers = [];
        $status = preg_match('~^HTTP/\S+\s+(\d+)~', $headers[0] ?? '', $m) ? (int) $m[1] : 0;
        $parsed = [];
        foreach (array_slice($headers, 1) as $header) {
            if (str_contains($header, ':')) {
                [$key, $value] = explode(':', $header, 2);
                $parsed[trim($key)] = trim($value);
            }
        }
        return new Response($status, $parsed, $body);
    }

    public function stream(Request $request, callable $onChunk, int $chunkSize = 16384): Response
    {
        if ($chunkSize < 1) throw new \InvalidArgumentException('Chunk size must be positive.');
        $lines = [];
        foreach ($request->headers as $name => $value) $lines[] = "{$name}: {$value}";
        $context = stream_context_create(['http' => [
            'method' => $request->method,
            'header' => implode("\r\n", $lines),
            'content' => $request->body,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $stream = @fopen($request->url, 'rb', false, $context);
        if ($stream === false) throw new TransportException('HTTP connection failed (timeout, DNS or TLS error).');
        $localVariables = get_defined_vars();
        if (function_exists('http_get_last_response_headers')) {
            $headers = http_get_last_response_headers();
        } else {
            $headers = $localVariables['http_response_header'];
        }
        if (!is_array($headers)) $headers = [];
        $status = preg_match('~^HTTP/\S+\s+(\d+)~', $headers[0] ?? '', $match) ? (int) $match[1] : 0;
        $parsed = [];
        foreach (array_slice($headers, 1) as $header) {
            if (str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $parsed[trim($name)] = trim($value);
            }
        }
        $errorBody = '';
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, $chunkSize);
                if ($chunk === false) throw new TransportException('HTTP response stream failed while reading.');
                if ($chunk === '') continue;
                if ($status >= 400) {
                    if (strlen($errorBody) < 1_048_576) $errorBody .= substr($chunk, 0, 1_048_576 - strlen($errorBody));
                } else {
                    $onChunk($chunk);
                }
            }
        } finally {
            fclose($stream);
        }
        return new Response($status, $parsed, $errorBody);
    }
}
