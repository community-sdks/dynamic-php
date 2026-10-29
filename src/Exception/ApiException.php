<?php

declare(strict_types=1);
namespace CommunitySDKs\Exception;

final class ApiException extends SdkException
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly mixed $data,
        public readonly array $headers,
        public readonly string $rawBody,
    ) {
        $message = is_array($data) ? ($data['message'] ?? $data['error'] ?? null) : null;
        parent::__construct(is_string($message) ? $message : "API returned HTTP {$status}", $status);
    }
}
