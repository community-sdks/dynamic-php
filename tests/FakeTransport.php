<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\Http\{Request, Response, Transport};

final class FakeTransport implements Transport
{
    public ?Request $lastRequest = null;
    public function __construct(public Response $response = new Response(200, ['Content-Type'=>'application/json'], '{}')) {}
    public function send(Request $request): Response
    {
        $this->lastRequest = $request;
        return $this->response;
    }
}
