<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

final readonly class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status,
        public array $headers = [],
        public string $body = '',
    ) {}

    public function contentType(): string
    {
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === 'content-type') {
                return strtolower(trim(explode(';', $value)[0]));
            }
        }
        return '';
    }
}
