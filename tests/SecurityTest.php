<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Http\Response;
use CommunitySDKs\Security\ClientCredentialsTokenProvider;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    public function testClientCredentialsProviderCachesByNormalizedScopes(): void
    {
        $transport = new FakeTransport(new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"secret-token","token_type":"Bearer","expires_in":3600}'));
        $provider = new ClientCredentialsTokenProvider(
            'https://auth.example.test/token', 'client-id', 'client-secret', $transport, ['auth.example.test'],
        );

        self::assertSame('secret-token', $provider->accessToken('oauth', ['items:read', 'profile:read']));
        self::assertSame('secret-token', $provider->accessToken('oauth', ['profile:read', 'items:read']));

        self::assertSame(1, $transport->sendCount);
        self::assertSame('POST', $transport->lastRequest->method);
        self::assertSame('Basic '.base64_encode('client-id:client-secret'), $transport->lastRequest->headers['Authorization']);
        self::assertStringContainsString('scope=items%3Aread%20profile%3Aread', $transport->lastRequest->body);
    }

    public function testClientCredentialsProviderRequiresAllowlistedHttpsHost(): void
    {
        $this->expectException(SdkException::class);
        new ClientCredentialsTokenProvider(
            'http://auth.example.test/token', 'client-id', 'client-secret', new FakeTransport(), ['auth.example.test'],
        );
    }
}