<?php

declare(strict_types=1);
namespace CommunitySDKs\Security;

interface OAuthTokenProvider
{
    /** @param list<string> $scopes */
    public function accessToken(string $schemeName, array $scopes): string;
}