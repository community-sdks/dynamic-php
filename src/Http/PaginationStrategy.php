<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

interface PaginationStrategy
{
    /** Return the next operation arguments, or null when pagination is complete. @param array<string,mixed> $currentArguments @return array<string,mixed>|null */
    public function nextArguments(Result $result, array $currentArguments): ?array;
}