<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

final readonly class Result
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public mixed $data,
        public string $rawBody,
    ) {}
}
