<?php

declare(strict_types=1);
namespace CommunitySDKs;

use CommunitySDKs\Exception\ApiException;
use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Exception\ValidationException;
use CommunitySDKs\Http\{HttpMiddleware, MiddlewareTransport, Request, Result, Response, RetryingTransport, StreamTransport, Transport};
use CommunitySDKs\OpenApi\{Document, ParameterSerializer, Registry, RequestBodySerializer, SecurityResolver, Validator};

/** Universal operationId client. No endpoint-specific PHP classes are created. */
readonly class Client
{
    private Registry $registry;
    private Validator $validator;
    private ParameterSerializer $parameterSerializer;
    private RequestBodySerializer $requestBodySerializer;
    private SecurityResolver $securityResolver;
    private Transport $transport;
    private string $baseUrl;

    /** @param array<string,string> $defaultHeaders */
    public function __construct(
        private Document $document,
        ?string $baseUrl = null,
        ?Transport $transport = null,
        private ?string $token = null,
        private array $defaultHeaders = [],
        private bool $validateResponses = true,
        /** @var array<string,string> credentials keyed by OpenAPI security scheme name */
        private array $credentials = [],
        /** @var list<HttpMiddleware> */
        array $middleware = [],
        int $maxRetries = 0,
    ) {
        $this->registry = new Registry($document);
        $this->validator = new Validator($document);
        $this->parameterSerializer = new ParameterSerializer();
        $this->requestBodySerializer = new RequestBodySerializer();
        $this->securityResolver = new SecurityResolver($document);
        $configuredTransport = $transport ?? new StreamTransport();
        if ($middleware !== []) $configuredTransport = new MiddlewareTransport($configuredTransport, $middleware);
        if ($maxRetries > 0) $configuredTransport = new RetryingTransport($configuredTransport, $maxRetries);
        $this->transport = $configuredTransport;
        $server = $baseUrl ?? ($document->data['servers'][0]['url'] ?? null);
        if (!is_string($server) || !preg_match('~^https?://~i', $server)) {
            throw new SdkException('Provide an absolute HTTP(S) base URL.');
        }
        $serverParts = parse_url($server);
        if (!is_array($serverParts) || !isset($serverParts['host']) || $serverParts['host'] === ''
            || isset($serverParts['user']) || isset($serverParts['pass']) || isset($serverParts['query']) || isset($serverParts['fragment'])
            || preg_match('/[\x00-\x20\\\\]/', $server)) {
            throw new SdkException('Base URL must not contain credentials, query, fragment, whitespace, or invalid characters.');
        }
        if ($maxRetries < 0) throw new \InvalidArgumentException('maxRetries must be non-negative.');
        $this->baseUrl = rtrim($server, '/');
    }

    /** @param array<string,string> $credentials */
    /** @param array<string,string> $credentials @param list<HttpMiddleware> $middleware */
    public static function fromFile(string $file, ?string $baseUrl = null, ?Transport $transport = null, ?string $token = null, array $credentials = [], array $middleware = [], int $maxRetries = 0): self
    {
        return new self(Document::fromFile($file), $baseUrl, $transport, $token, credentials: $credentials, middleware: $middleware, maxRetries: $maxRetries);
    }

    public function operations(): Registry { return $this->registry; }

    /** PHP named arguments are delivered as an associative array to __call. */
    public function __call(string $name, array $arguments): Result
    {
        return $this->call($name, $arguments);
    }

    /** @param array<string,mixed> $arguments */
    public function call(string $operationId, array $arguments = []): Result
    {
        $op = $this->registry->get($operationId);
        $path = $op['_path'];
        $query = [];
        $cookies = [];
        $headers = $this->defaultHeaders;
        $used = [];
        foreach ($op['_parameters'] as $parameter) {
            $name = $parameter['name'];
            $place = $parameter['in'];
            if (!array_key_exists($name, $arguments)) {
                if ($parameter['required'] ?? false) throw new ValidationException(["{$name}: required {$place} parameter"]);
                continue;
            }
            $used[$name] = true;
            $value = $arguments[$name];
            if (isset($parameter['schema'])) $this->validator->assert($value, $parameter['schema'], '$.'.$name);
            if ($place === 'path') {
                $path = str_replace('{'.$name.'}', $this->parameterSerializer->path($parameter, $value), $path);
            } elseif ($place === 'query') {
                array_push($query, ...$this->parameterSerializer->query($parameter, $value));
            } elseif ($place === 'header') {
                $headers[$name] = $this->parameterSerializer->header($parameter, $value);
            } elseif ($place === 'cookie') {
                array_push($cookies, ...$this->parameterSerializer->cookie($parameter, $value));
            }
        }
        if (str_contains($path, '{')) throw new SdkException('Unresolved path parameter.');
        $body = '';
        if (isset($op['requestBody'])) {
            $requestBody = $this->document->resolve($op['requestBody']);
            $hasBody = array_key_exists('body', $arguments);
            if (!$hasBody && ($requestBody['required'] ?? false)) throw new ValidationException(['body: required']);
            if ($hasBody) {
                $used['body'] = true;
                $contentTypes = $requestBody['content'] ?? [];
                $mediaType = $this->requestBodySerializer->selectMediaType($contentTypes, $arguments['body']);
                $media = $contentTypes[$mediaType];
                $this->validator->assert($arguments['body'], $media['schema'] ?? [], '$.body');
                $serialized = $this->requestBodySerializer->serialize($arguments['body'], $mediaType, $media);
                $body = $serialized['body'];
                $headers['Content-Type'] = $serialized['contentType'];
            }
        }
        $unknown = array_diff(array_keys($arguments), array_keys($used));
        if ($unknown !== []) throw new ValidationException(['Unknown arguments: '.implode(', ', $unknown)]);
        $this->securityResolver->apply($op, $this->token, $this->credentials, $headers, $query, $cookies);
        if ($cookies !== []) {
            $headers['Cookie'] = isset($headers['Cookie']) ? $headers['Cookie'].'; '.implode('; ', $cookies) : implode('; ', $cookies);
        }
        $url = $this->baseUrl.'/'.ltrim($path, '/');
        if ($query !== []) $url .= '?'.implode('&', $query);
        $response = $this->transport->send(new Request($op['_method'], $url, $headers, $body));
        return $this->resultFor($op, $response);
    }

    /** @param array<string,mixed> $arguments @return iterable<Result> */
    public function paginate(string $operationId, array $arguments = []): iterable
    {
        $op = $this->registry->get($operationId);
        if ($op['_method'] !== 'GET') throw new SdkException('Automatic Link-header pagination is only available for GET operations.');
        $result = $this->call($operationId, $arguments);
        while (true) {
            yield $result;
            $link = $this->nextLink($result->headers);
            if ($link === null) return;
            $url = $this->sameOriginNextUrl($link);
            $headers = $this->defaultHeaders;
            foreach ($op['_parameters'] as $parameter) {
                $name = $parameter['name'];
                if (!array_key_exists($name, $arguments)) continue;
                if ($parameter['in'] === 'header') $headers[$name] = $this->parameterSerializer->header($parameter, $arguments[$name]);
            }
            $query = $cookies = [];
            $this->securityResolver->apply($op, $this->token, $this->credentials, $headers, $query, $cookies);
            if ($query !== []) $url .= (str_contains($url, '?') ? '&' : '?').implode('&', $query);
            if ($cookies !== []) $headers['Cookie'] = isset($headers['Cookie']) ? $headers['Cookie'].'; '.implode('; ', $cookies) : implode('; ', $cookies);
            $result = $this->resultFor($op, $this->transport->send(new Request('GET', $url, $headers)));
        }
    }

    /** @param array<string,mixed> $operation */
    private function resultFor(array $operation, Response $response): Result
    {
        $decoded = $this->decode($response);
        $schema = $this->responseSchema($operation, $response);
        if ($this->validateResponses && $schema !== null && $decoded !== null) {
            $this->validator->assert($decoded, $schema, '$.response');
        }
        if ($response->status >= 400) {
            throw new ApiException($response->status, $decoded, $response->headers, $response->body);
        }
        return new Result($response->status, $response->headers, $decoded, $response->body);
    }

    /** @param array<string,string> $headers */
    private function nextLink(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Link') !== 0) continue;
            if (preg_match_all('/<([^>]+)>\s*;[^,]*\brel\s*=\s*"?[^";,]*\bnext\b[^";,]*"?/i', $value, $matches) > 0) {
                return $matches[1][0];
            }
        }
        return null;
    }

    private function sameOriginNextUrl(string $link): string
    {
        $base = parse_url($this->baseUrl);
        $parts = parse_url($link);
        if (!is_array($parts)) throw new SdkException('Invalid pagination next link.');
        if (!isset($parts['scheme'])) {
            if (str_starts_with($link, '//')) throw new SdkException('Pagination next links must be same-origin.');
            if (!str_starts_with($link, '/')) throw new SdkException('Pagination next links must be absolute or root-relative.');
            $link = $this->origin($base).$link;
            $parts = parse_url($link);
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !is_array($base) || !is_array($parts)) {
            throw new SdkException('Invalid pagination next link.');
        }
        $basePort = $base['port'] ?? (strtolower($base['scheme'] ?? '') === 'https' ? 443 : 80);
        $nextPort = $parts['port'] ?? (strtolower($parts['scheme'] ?? '') === 'https' ? 443 : 80);
        if (strtolower($parts['scheme'] ?? '') !== strtolower($base['scheme'] ?? '')
            || strtolower($parts['host'] ?? '') !== strtolower($base['host'] ?? '')
            || $nextPort !== $basePort) {
            throw new SdkException('Pagination next links must be same-origin.');
        }
        return $link;
    }

    /** @param array<string,mixed> $base */
    private function origin(array $base): string
    {
        return strtolower((string) $base['scheme']).'://'.strtolower((string) $base['host'])
            .(isset($base['port']) ? ':'.$base['port'] : '');
    }

    private function decode(Response $response): mixed
    {
        if ($response->body === '') return null;
        $type = $response->contentType();
        if ($type === 'application/json' || str_ends_with($type, '+json')) {
            return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        }
        return $response->body;
    }

    /** @param array<string,mixed> $operation @return array<string,mixed>|null */
    private function responseSchema(array $operation, Response $response): ?array
    {
        $responses = $operation['responses'] ?? [];
        $status = (string)$response->status;
        $entry = $responses[$status] ?? $responses[substr($status, 0, 1).'XX'] ?? $responses['default'] ?? null;
        if ($entry === null) return null;
        $entry = $this->document->resolve($entry);
        $type = $response->contentType();
        $content = $entry['content'] ?? [];
        return $content[$type]['schema'] ?? ($type === '' ? ($content['application/json']['schema'] ?? null) : null);
    }
}
