<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

final readonly class MultipartPart
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $name,
        public ?string $filename,
        public ?string $contentType,
        public array $headers,
        public string $body,
        public mixed $data,
    ) {}
}