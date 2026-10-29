<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

interface StreamingTransport extends Transport
{
    /** @param callable(string):void $onChunk */
    public function stream(Request $request, callable $onChunk, int $chunkSize = 16384): Response;
}