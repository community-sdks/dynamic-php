<?php

declare(strict_types=1);
namespace CommunitySDKs\Security;

use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Http\{Request, StreamTransport, Transport};

final class AuthorizationCodeTokenProvider implements OAuthTokenProvider
{
    /** @var array<string,array{token:string,refreshToken:?string,expiresAt:int}> */
    private array $cache = [];
    private readonly string $tokenUrl;

    /** @param list<string> $allowedHosts @param list<string> $grantedScopes */
    public function __construct(
        string $tokenUrl,
        private readonly string $clientId,
        private readonly string $authorizationCode,
        private readonly string $redirectUri,
        private readonly Transport $transport = new StreamTransport(),
        private readonly array $allowedHosts = [],
        private readonly ?string $clientSecret = null,
        private readonly ?string $codeVerifier = null,
        private readonly array $grantedScopes = [],
        private readonly int $expiryLeewaySeconds = 30,
    ) {
        $parts = parse_url($tokenUrl);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new SdkException('OAuth token URL must be an absolute HTTPS URL without user info or fragment.');
        }
        if ($allowedHosts === [] || !in_array(strtolower($parts['host']), array_map('strtolower', $allowedHosts), true)) {
            throw new SdkException('OAuth token URL host must be explicitly allow-listed.');
        }
        if ($clientId === '' || $authorizationCode === '' || $redirectUri === '' || $expiryLeewaySeconds < 0) {
            throw new SdkException('Authorization-code exchange parameters are invalid.');
        }
        if ($codeVerifier !== null && preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $codeVerifier) !== 1) {
            throw new SdkException('PKCE code verifier has an invalid format.');
        }
        $this->tokenUrl = $tokenUrl;
    }

    /** @param list<string> $scopes */
    public function accessToken(string $schemeName, array $scopes): string
    {
        $scopes = array_values(array_unique($scopes));
        sort($scopes);
        if ($this->grantedScopes !== [] && array_diff($scopes, $this->grantedScopes) !== []) {
            throw new SdkException("OAuth provider does not have the requested scopes for {$schemeName}.");
        }
        $cacheKey = hash('sha256', $schemeName."\0".implode("\0", $scopes));
        $cached = $this->cache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expiresAt'] > time()) return $cached['token'];

        $form = $cached !== null && $cached['refreshToken'] !== null
            ? ['grant_type' => 'refresh_token', 'refresh_token' => $cached['refreshToken']]
            : [
                'grant_type' => 'authorization_code',
                'code' => $this->authorizationCode,
                'redirect_uri' => $this->redirectUri,
            ];
        if ($form['grant_type'] === 'authorization_code' && $this->codeVerifier !== null) {
            $form['code_verifier'] = $this->codeVerifier;
        }
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        if ($this->clientSecret !== null) {
            $headers['Authorization'] = 'Basic '.base64_encode(rawurlencode($this->clientId).':'.rawurlencode($this->clientSecret));
        } else {
            $form['client_id'] = $this->clientId;
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
        if (strtolower((string) ($payload['token_type'] ?? 'bearer')) !== 'bearer') {
            throw new SdkException('OAuth token response uses an unsupported token type.');
        }
        if (is_array($payload['scope'] ?? null)) {
            $actualScopes = $payload['scope'];
        } elseif (is_string($payload['scope'] ?? null)) {
            $actualScopes = preg_split('/\s+/', trim($payload['scope']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            $actualScopes = $scopes;
        }
        if (array_diff($scopes, $actualScopes) !== []) {
            throw new SdkException('OAuth token response does not grant all requested scopes.');
        }
        $refreshToken = is_string($payload['refresh_token'] ?? null)
            ? $payload['refresh_token']
            : ($cached['refreshToken'] ?? null);
        $expiresAt = is_numeric($payload['expires_in'] ?? null)
            ? time() + max(0, (int) $payload['expires_in'] - $this->expiryLeewaySeconds)
            : time();
        $this->cache[$cacheKey] = ['token' => $payload['access_token'], 'refreshToken' => $refreshToken, 'expiresAt' => $expiresAt];
        return $payload['access_token'];
    }
}