<?php

declare(strict_types=1);
namespace CommunitySDKs\OpenApi;

use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Exception\ValidationException;

final readonly class SecurityResolver
{
    public function __construct(private Document $document) {}

    /** @param array<string,mixed> $operation @param array<string,string> $credentials @param array<string,string> $headers @param list<string> $query @param list<string> $cookies */
    public function apply(array $operation, ?string $token, array $credentials, array &$headers, array &$query, array &$cookies): void
    {
        $requirements = array_key_exists('security', $operation)
            ? $operation['security']
            : ($this->document->data['security'] ?? null);
        if ($requirements === null) {
            if ($token !== null) $this->setHeader($headers, 'Authorization', 'Bearer '.$token);
            return;
        }
        if ($requirements === []) return;
        if (!is_array($requirements)) throw new SdkException('OpenAPI security requirements must be an array.');

        $missingByAlternative = [];
        foreach ($requirements as $requirement) {
            if (!is_array($requirement)) throw new SdkException('Invalid OpenAPI security requirement.');
            $candidateHeaders = $headers;
            $candidateQuery = $query;
            $candidateCookies = $cookies;
            $missing = [];
            foreach ($requirement as $name => $scopes) {
                $schemeNode = $this->document->data['components']['securitySchemes'][$name] ?? null;
                if (!is_array($schemeNode)) throw new SdkException("Unknown security scheme: {$name}");
                $scheme = $this->document->resolve($schemeNode);
                $credential = $credentials[$name] ?? null;
                if ($credential === null && in_array($scheme['type'] ?? '', ['http', 'oauth2', 'openIdConnect'], true)) {
                    $credential = $token;
                }
                if ($credential === null) {
                    $missing[] = $name;
                    continue;
                }
                if (!is_string($credential) || preg_match('/[\r\n]/', $credential)) {
                    throw new ValidationException(["{$name}: invalid credential value"]);
                }
                $this->applyScheme($name, $scheme, $credential, $candidateHeaders, $candidateQuery, $candidateCookies);
            }
            if ($missing === []) {
                $headers = $candidateHeaders;
                $query = $candidateQuery;
                $cookies = $candidateCookies;
                return;
            }
            $missingByAlternative[] = $missing;
        }

        $missing = array_unique(array_merge(...$missingByAlternative));
        throw new ValidationException(['Missing credentials for security schemes: '.implode(', ', $missing)]);
    }

    /** @param array<string,mixed> $scheme @param array<string,string> $headers @param list<string> $query @param list<string> $cookies */
    private function applyScheme(string $name, array $scheme, string $credential, array &$headers, array &$query, array &$cookies): void
    {
        $type = $scheme['type'] ?? null;
        if ($type === 'apiKey') {
            $keyName = $scheme['name'] ?? '';
            $location = $scheme['in'] ?? '';
            if (!is_string($keyName) || $keyName === '') throw new SdkException("Security scheme {$name} has no API key name.");
            if ($location === 'header') {
                $this->setHeader($headers, $keyName, $credential);
            } elseif ($location === 'query') {
                $prefix = rawurlencode($keyName).'=';
                $query = array_values(array_filter($query, static fn (string $pair): bool => !str_starts_with($pair, $prefix)));
                $query[] = $prefix.rawurlencode($credential);
            } elseif ($location === 'cookie') {
                $prefix = rawurlencode($keyName).'=';
                $cookies = array_values(array_filter($cookies, static fn (string $pair): bool => !str_starts_with($pair, $prefix)));
                $cookies[] = $prefix.rawurlencode($credential);
            } else {
                throw new SdkException("Unsupported API key location: {$location}");
            }
            return;
        }

        if ($type === 'http') {
            $httpScheme = strtolower((string) ($scheme['scheme'] ?? ''));
            if ($httpScheme === 'bearer') {
                $this->setHeader($headers, 'Authorization', 'Bearer '.$credential);
            } elseif ($httpScheme === 'basic') {
                if (!str_contains($credential, ':')) throw new ValidationException(["{$name}: basic credentials must be username:password"]);
                $this->setHeader($headers, 'Authorization', 'Basic '.base64_encode($credential));
            } else {
                throw new SdkException("Unsupported HTTP authentication scheme: {$httpScheme}");
            }
            return;
        }

        if (in_array($type, ['oauth2', 'openIdConnect'], true)) {
            $this->setHeader($headers, 'Authorization', 'Bearer '.$credential);
            return;
        }
        throw new SdkException("Unsupported security scheme type: ".(string) $type);
    }

    /** @param array<string,string> $headers */
    private function setHeader(array &$headers, string $name, string $value): void
    {
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1 || preg_match('/[\r\n]/', $value)) {
            throw new ValidationException(["{$name}: invalid security header"]);
        }
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) unset($headers[$existing]);
        }
        $headers[$name] = $value;
    }
}