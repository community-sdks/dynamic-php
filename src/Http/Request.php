<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

final readonly class Request
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public string $body = '',
    ) {}
}
