<?php

declare(strict_types=1);
namespace CommunitySDKs\Security;

use Closure;
use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Http\{Request, StreamTransport, Transport};

final class DeviceCodeTokenProvider implements OAuthTokenProvider
{
    /** @var array<string,array{authorization:DeviceAuthorization,scopes:list<string>,startedAt:int,interval:int}> */
    private array $devices = [];

    /** @var array<string,array{token:string,refreshToken:?string,expiresAt:int}> */
    private array $tokens = [];

    /** @var Closure(int):void */
    private Closure $waiter;

    /** @param list<string> $allowedHosts @param callable(int):void|null $waiter interval in seconds */
    public function __construct(
        private readonly string $deviceAuthorizationUrl,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly Transport $transport = new StreamTransport(),
        private readonly array $allowedHosts = [],
        ?callable $waiter = null,
        private readonly int $expiryLeewaySeconds = 30,
    ) {
        $this->assertAllowedHttpsUrl($deviceAuthorizationUrl);
        $this->assertAllowedHttpsUrl($tokenUrl);
        if ($clientId === '' || $expiryLeewaySeconds < 0) throw new SdkException('Device authorization settings are invalid.');
        $this->waiter = Closure::fromCallable($waiter ?? static function (int $seconds): void {
            if ($seconds > 0) sleep($seconds);
        });
    }

    /** @param list<string> $scopes */
    public function begin(array $scopes): DeviceAuthorization
    {
        $scopes = array_values(array_unique($scopes));
        sort($scopes);
        $form = ['client_id' => $this->clientId];
        if ($scopes !== []) $form['scope'] = implode(' ', $scopes);
        $response = $this->sendForm($this->deviceAuthorizationUrl, $form);
        if ($response->status < 200 || $response->status >= 300) throw new SdkException("Device authorization endpoint returned HTTP {$response->status}.");
        $payload = $this->jsonObject($response->body, 'Device authorization endpoint');
        foreach (['device_code', 'user_code', 'verification_uri'] as $required) {
            if (!is_string($payload[$required] ?? null) || $payload[$required] === '') throw new SdkException("Device authorization response is missing {$required}.");
        }
        $this->assertAllowedHttpsUrl($payload['verification_uri']);
        if (is_string($payload['verification_uri_complete'] ?? null)) {
            $this->assertAllowedHttpsUrl($payload['verification_uri_complete']);
        }
        if (!is_int($payload['expires_in'] ?? null) || $payload['expires_in'] < 1) throw new SdkException('Device authorization response has invalid expires_in.');
        $interval = is_int($payload['interval'] ?? null) && $payload['interval'] >= 0 ? $payload['interval'] : 5;
        $authorization = new DeviceAuthorization(
            $payload['device_code'],
            $payload['user_code'],
            $payload['verification_uri'],
            is_string($payload['verification_uri_complete'] ?? null) ? $payload['verification_uri_complete'] : null,
            $payload['expires_in'],
            $interval,
        );
        $key = $this->scopeKey($scopes);
        $this->devices[$key] = ['authorization' => $authorization, 'scopes' => $scopes, 'startedAt' => time(), 'interval' => $interval];
        return $authorization;
    }

    /** @param list<string> $scopes */
    public function accessToken(string $schemeName, array $scopes): string
    {
        $scopes = array_values(array_unique($scopes));
        sort($scopes);
        $key = $this->scopeKey($scopes);
        $cached = $this->tokens[$key] ?? null;
        if ($cached !== null && $cached['expiresAt'] > time()) return $cached['token'];
        if ($cached !== null && $cached['refreshToken'] !== null) {
            $response = $this->sendForm($this->tokenUrl, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $cached['refreshToken'],
                'client_id' => $this->clientId,
            ]);
            if ($response->status < 200 || $response->status >= 300) {
                unset($this->tokens[$key]);
                throw new SdkException("OAuth refresh endpoint returned HTTP {$response->status}.");
            }
            $payload = $this->jsonObject($response->body, 'OAuth refresh endpoint');
            if (!is_string($payload['access_token'] ?? null) || $payload['access_token'] === '') {
                throw new SdkException('OAuth refresh response is missing an access_token.');
            }
            $grantedScopes = is_string($payload['scope'] ?? null)
                ? (preg_split('/\s+/', trim($payload['scope']), -1, PREG_SPLIT_NO_EMPTY) ?: [])
                : $scopes;
            if (array_diff($scopes, $grantedScopes) !== []) throw new SdkException('OAuth refresh response does not grant all requested scopes.');
            $refreshToken = is_string($payload['refresh_token'] ?? null) ? $payload['refresh_token'] : $cached['refreshToken'];
            $expiresAt = is_numeric($payload['expires_in'] ?? null)
                ? time() + max(0, (int) $payload['expires_in'] - $this->expiryLeewaySeconds)
                : time();
            $this->tokens[$key] = ['token' => $payload['access_token'], 'refreshToken' => $refreshToken, 'expiresAt' => $expiresAt];
            return $payload['access_token'];
        }
        $device = $this->devices[$key] ?? null;
        if ($device === null) throw new SdkException("Call begin() and complete device authorization for {$schemeName} first.");

        while (time() - $device['startedAt'] < $device['authorization']->expiresIn) {
            ($this->waiter)($device['interval']);
            $response = $this->sendForm($this->tokenUrl, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                'device_code' => $device['authorization']->deviceCode,
                'client_id' => $this->clientId,
            ]);
            if ($response->status >= 200 && $response->status < 300) {
                $payload = $this->jsonObject($response->body, 'OAuth token endpoint');
                if (!is_string($payload['access_token'] ?? null) || $payload['access_token'] === '') throw new SdkException('OAuth token response is missing an access_token.');
                if (strtolower((string) ($payload['token_type'] ?? 'bearer')) !== 'bearer') throw new SdkException('OAuth token response uses an unsupported token type.');
                $grantedScopes = is_string($payload['scope'] ?? null)
                    ? (preg_split('/\s+/', trim($payload['scope']), -1, PREG_SPLIT_NO_EMPTY) ?: [])
                    : $scopes;
                if (array_diff($scopes, $grantedScopes) !== []) throw new SdkException('OAuth token response does not grant all requested scopes.');
                $expiresAt = is_numeric($payload['expires_in'] ?? null)
                    ? time() + max(0, (int) $payload['expires_in'] - $this->expiryLeewaySeconds)
                    : time();
                $refreshToken = is_string($payload['refresh_token'] ?? null) ? $payload['refresh_token'] : null;
                $this->tokens[$key] = ['token' => $payload['access_token'], 'refreshToken' => $refreshToken, 'expiresAt' => $expiresAt];
                unset($this->devices[$key]);
                return $payload['access_token'];
            }
            $payload = $this->jsonObject($response->body, 'OAuth token endpoint');
            $error = $payload['error'] ?? null;
            if ($error === 'authorization_pending') continue;
            if ($error === 'slow_down') {
                $device['interval'] += 5;
                $this->devices[$key] = $device;
                continue;
            }
            if ($error === 'access_denied') throw new SdkException('Device authorization was denied.');
            if ($error === 'expired_token') throw new SdkException('Device authorization code expired.');
            throw new SdkException("OAuth device token endpoint returned HTTP {$response->status}.");
        }
        unset($this->devices[$key]);
        throw new SdkException('Device authorization expired before the user completed it.');
    }

    private function assertAllowedHttpsUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new SdkException('OAuth endpoints must be absolute HTTPS URLs without user info or fragments.');
        }
        if ($this->allowedHosts === [] || !in_array(strtolower($parts['host']), array_map('strtolower', $this->allowedHosts), true)) {
            throw new SdkException('OAuth endpoint host must be explicitly allow-listed.');
        }
    }

    /** @param array<string,string> $form */
    private function sendForm(string $url, array $form): \CommunitySDKs\Http\Response
    {
        return $this->transport->send(new Request(
            'POST', $url,
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query($form, '', '&', PHP_QUERY_RFC3986),
        ));
    }

    /** @return array<string,mixed> */
    private function jsonObject(string $body, string $endpoint): array
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SdkException("{$endpoint} returned invalid JSON.", previous: $e);
        }
        if (!is_array($payload)) throw new SdkException("{$endpoint} returned an invalid object.");
        return $payload;
    }

    /** @param list<string> $scopes */
    private function scopeKey(array $scopes): string
    {
        return hash('sha256', implode("\0", $scopes));
    }
}