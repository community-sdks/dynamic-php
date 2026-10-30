<?php

declare(strict_types=1);
namespace CommunitySDKs\OpenApi;

use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Http\{Request, StreamTransport};

final readonly class StreamRemoteReferenceLoader implements RemoteReferenceLoader
{
    public function __construct(private StreamTransport $transport = new StreamTransport()) {}

    public function load(string $url, int $maxBytes): string
    {
        $body = '';
        $response = $this->transport->stream(
            new Request('GET', $url, ['Accept' => 'application/json, application/yaml, application/x-yaml']),
            static function (string $chunk) use (&$body, $maxBytes): void {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    throw new SdkException('Remote reference exceeds the configured size limit.');
                }
                $body .= $chunk;
            },
            16384,
        );
        if ($response->status !== 200) throw new SdkException("Remote reference returned HTTP {$response->status}.");
        if ($response->body !== '') {
            if (strlen($body) + strlen($response->body) > $maxBytes) throw new SdkException('Remote reference exceeds the configured size limit.');
            $body .= $response->body;
        }
        return $body;
    }
}