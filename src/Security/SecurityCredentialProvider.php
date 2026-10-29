<?php

declare(strict_types=1);
namespace CommunitySDKs\Security;

interface SecurityCredentialProvider
{
    /** @param array<string,mixed> $scheme */
    public function credential(string $schemeName, array $scheme): string;
}