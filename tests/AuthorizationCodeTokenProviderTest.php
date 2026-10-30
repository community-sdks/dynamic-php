<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Http\Response;
use CommunitySDKs\Security\AuthorizationCodeTokenProvider;
use PHPUnit\Framework\TestCase;

final class AuthorizationCodeTokenProviderTest extends TestCase
{
    public function testExchangesPkceCodeAndRefreshesExpiredToken(): void
    {
        $transport = new FakeTransport(responses: [
            new Response(200, [], '{"access_token":"first","refresh_token":"refresh-me","expires_in":0,"scope":"items:read"}'),
            new Response(200, [], '{"access_token":"second","expires_in":3600,"scope":"items:read"}'),
        ]);
        $provider = new AuthorizationCodeTokenProvider(
            'https://auth.example.test/token', 'client-id', 'auth-code', 'https://app.example.test/callback',
            $transport, ['auth.example.test'], 'client-secret', str_repeat('a', 43), ['items:read'], 0,
        );

        self::assertSame('first', $provider->accessToken('oauth', ['items:read']));
        self::assertSame('second', $provider->accessToken('oauth', ['items:read']));
        self::assertSame(2, $transport->sendCount);
        self::assertStringContainsString('grant_type=refresh_token&refresh_token=refresh-me', $transport->requests[1]->body);
    }

    public function testRejectsRequestedScopesNotGrantedByApplication(): void
    {
        $provider = new AuthorizationCodeTokenProvider(
            'https://auth.example.test/token', 'client-id', 'auth-code', 'https://app.example.test/callback',
            new FakeTransport(), ['auth.example.test'], grantedScopes: ['items:read'],
        );

        $this->expectException(SdkException::class);
        $provider->accessToken('oauth', ['items:write']);
    }
}