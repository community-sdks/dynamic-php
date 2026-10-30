<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Http\Response;
use CommunitySDKs\Security\ClientCredentialsTokenProvider;
use CommunitySDKs\Security\DeviceCodeTokenProvider;
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

    public function testClientCredentialsProviderRejectsInsufficientGrantedScopes(): void
    {
        $transport = new FakeTransport(new Response(200, [], '{"access_token":"under-scoped","scope":"items:read"}'));
        $provider = new ClientCredentialsTokenProvider(
            'https://auth.example.test/token', 'client-id', 'client-secret', $transport, ['auth.example.test'],
        );

        $this->expectException(SdkException::class);
        $provider->accessToken('oauth', ['items:read', 'profile:read']);
    }

    public function testDeviceCodeFlowHandlesPendingAndSlowDown(): void
    {
        $transport = new FakeTransport(responses: [
            new Response(200, [], '{"device_code":"device-secret","user_code":"ABCD-EFGH","verification_uri":"https://auth.example.test/activate","expires_in":60,"interval":0}'),
            new Response(400, [], '{"error":"authorization_pending"}'),
            new Response(400, [], '{"error":"slow_down"}'),
            new Response(200, [], '{"access_token":"device-token","token_type":"Bearer","expires_in":3600,"scope":"items:read"}'),
        ]);
        $waits = [];
        $provider = new DeviceCodeTokenProvider(
            'https://auth.example.test/device', 'https://auth.example.test/token', 'client-id',
            $transport, ['auth.example.test'], static function (int $seconds) use (&$waits): void { $waits[] = $seconds; },
        );

        $authorization = $provider->begin(['items:read']);
        $token = $provider->accessToken('device-oauth', ['items:read']);

        self::assertSame('ABCD-EFGH', $authorization->userCode);
        self::assertSame('https://auth.example.test/activate', $authorization->verificationUri);
        self::assertSame('device-token', $token);
        self::assertSame([0, 0, 5], $waits);
    }

    public function testDeviceCodeFlowRejectsUntrustedVerificationUri(): void
    {
        $transport = new FakeTransport(new Response(200, [], '{"device_code":"device-secret","user_code":"ABCD-EFGH","verification_uri":"https://phishing.example.test/activate","expires_in":60}'));
        $provider = new DeviceCodeTokenProvider(
            'https://auth.example.test/device', 'https://auth.example.test/token', 'client-id',
            $transport, ['auth.example.test'], static function (int $seconds): void {},
        );

        $this->expectException(SdkException::class);
        $provider->begin(['items:read']);
    }
}