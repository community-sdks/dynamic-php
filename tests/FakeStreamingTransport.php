<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\Http\{Request, Response, StreamingTransport};

final class FakeStreamingTransport implements StreamingTransport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @param list<string> $chunks */
    public function __construct(private array $chunks, private Response $response) {}

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        return $this->response;
    }

    public function stream(Request $request, callable $onChunk, int $chunkSize = 16384): Response
    {
        $this->requests[] = $request;
        foreach ($this->chunks as $chunk) $onChunk($chunk);
        return $this->response;
    }
}