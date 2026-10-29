<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

/** @template-covariant TData */
final readonly class Result
{
    /**
     * @param array<string,string> $headers
     * @param TData $data
     */
    public function __construct(
        public int $status,
        public array $headers,
        /** @var TData */
        public mixed $data,
        public string $rawBody,
    ) {}
}
