<?php

declare(strict_types=1);
namespace CommunitySDKs\Security;

use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Http\{Request, StreamTransport, Transport};

final class ClientCredentialsTokenProvider implements OAuthTokenProvider
{
    /** @var array<string,array{token:string,expiresAt:int}> */
    private array $cache = [];
    private readonly string $tokenUrl;
    private readonly string $clientAuthMethod;

    /** @param list<string> $allowedHosts exact hosts allowed to receive client credentials */
    public function __construct(
        string $tokenUrl,
        private string $clientId,
        private string $clientSecret,
        private Transport $transport = new StreamTransport(),
        private array $allowedHosts = [],
        string $clientAuthMethod = 'client_secret_basic',
        private int $expiryLeewaySeconds = 30,
    ) {
        $parts = parse_url($tokenUrl);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new SdkException('OAuth token URL must be an absolute HTTPS URL without user info or fragment.');
        }
        if ($allowedHosts === [] || !in_array(strtolower($parts['host']), array_map('strtolower', $allowedHosts), true)) {
            throw new SdkException('OAuth token URL host must be explicitly allow-listed.');
        }
        if (!in_array($clientAuthMethod, ['client_secret_basic', 'client_secret_post'], true)) {
            throw new SdkException('Unsupported OAuth client authentication method.');
        }
        if ($clientId === '' || $clientSecret === '' || $expiryLeewaySeconds < 0) {
            throw new SdkException('OAuth client credentials and expiry leeway must be valid.');
        }
        $this->tokenUrl = $tokenUrl;
        $this->clientAuthMethod = $clientAuthMethod;
    }

    /** @param list<string> $scopes */
    public function accessToken(string $schemeName, array $scopes): string
    {
        $scopes = array_values(array_unique($scopes));
        sort($scopes);
        $cacheKey = hash('sha256', $schemeName."\0".implode("\0", $scopes));
        $cached = $this->cache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expiresAt'] > time()) return $cached['token'];

        $form = ['grant_type' => 'client_credentials'];
        if ($scopes !== []) $form['scope'] = implode(' ', $scopes);
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        if ($this->clientAuthMethod === 'client_secret_basic') {
            $headers['Authorization'] = 'Basic '.base64_encode(rawurlencode($this->clientId).':'.rawurlencode($this->clientSecret));
        } else {
            $form['client_id'] = $this->clientId;
            $form['client_secret'] = $this->clientSecret;
        }
        try {
            $response = $this->transport->send(new Request(
                'POST',
                $this->tokenUrl,
                $headers,
                http_build_query($form, '', '&', PHP_QUERY_RFC3986),
            ));
        } catch (\Throwable $e) {
            throw new SdkException('OAuth token request failed.', previous: $e);
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new SdkException("OAuth token endpoint returned HTTP {$response->status}.");
        }
        try {
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SdkException('OAuth token endpoint returned invalid JSON.', previous: $e);
        }
        if (!is_array($payload) || !is_string($payload['access_token'] ?? null) || $payload['access_token'] === '') {
            throw new SdkException('OAuth token response is missing an access_token.');
        }
        $tokenType = strtolower((string) ($payload['token_type'] ?? 'bearer'));
        if ($tokenType !== 'bearer') throw new SdkException('OAuth token response uses an unsupported token type.');
        $grantedScopes = is_string($payload['scope'] ?? null)
            ? (preg_split('/\s+/', trim($payload['scope']), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            : $scopes;
        if (array_diff($scopes, $grantedScopes) !== []) {
            throw new SdkException('OAuth token response does not grant all requested scopes.');
        }
        if (is_numeric($payload['expires_in'] ?? null)) {
            $lifetime = max(0, (int) $payload['expires_in'] - $this->expiryLeewaySeconds);
            $this->cache[$cacheKey] = ['token' => $payload['access_token'], 'expiresAt' => time() + $lifetime];
        }
        return $payload['access_token'];
    }
}