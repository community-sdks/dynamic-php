<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\Http\{Request, Response, Transport};

final class FakeTransport implements Transport
{
    public ?Request $lastRequest = null;
    public int $sendCount = 0;
    /** @param list<Response> $responses */
    public function __construct(public Response $response = new Response(200, ['Content-Type'=>'application/json'], '{}'), private array $responses = []) {}
    public function send(Request $request): Response
    {
        $this->sendCount++;
        $this->lastRequest = $request;
        return array_shift($this->responses) ?? $this->response;
    }
}
