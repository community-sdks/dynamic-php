<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

interface HttpMiddleware
{
    /** @param callable(Request):Response $next */
    public function process(Request $request, callable $next): Response;
}