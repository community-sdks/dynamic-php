<?php

declare(strict_types=1);
namespace CommunitySDKs\OpenApi;

interface RemoteReferenceLoader
{
    public function load(string $url, int $maxBytes): string;
}