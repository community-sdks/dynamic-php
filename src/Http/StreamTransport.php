<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

use CommunitySDKs\Exception\TransportException;

/** PHP stream adapter. Inject your own Transport to use Guzzle, Symfony, etc. */
final readonly class StreamTransport implements Transport
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
        $headers = $http_response_header;
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
}
