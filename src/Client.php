<?php

declare(strict_types=1);
namespace CommunitySDKs;

use CommunitySDKs\Exception\ApiException;
use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Exception\ValidationException;
use CommunitySDKs\Http\{HttpMiddleware, MiddlewareTransport, Request, Result, Response, RetryingTransport, StreamTransport, StreamingTransport, Transport};
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
    private ?StreamingTransport $streamingTransport;
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
        $this->streamingTransport = $configuredTransport instanceof StreamingTransport ? $configuredTransport : null;
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
        [$op, $request] = $this->buildRequest($operationId, $arguments);
        return $this->resultFor($op, $this->transport->send($request));
    }

    /** @param array<string,mixed> $arguments @return array{0:array<string,mixed>,1:Request} */
    private function buildRequest(string $operationId, array $arguments): array
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
        $this->mergeCookies($headers, $cookies);
        $url = $this->baseUrl.'/'.ltrim($path, '/');
        if ($query !== []) $url .= '?'.implode('&', $query);
        return [$op, new Request($op['_method'], $url, $headers, $body)];
    }

    /** @param array<string,mixed> $arguments @return Result */
    public function download(string $operationId, string $target, array $arguments = [], int $chunkSize = 16384): Result
    {
        if ($this->streamingTransport === null) throw new SdkException('Download requires a StreamingTransport.');
        if ($chunkSize < 1) throw new \InvalidArgumentException('Chunk size must be positive.');
        [$op, $request] = $this->buildRequest($operationId, $arguments);
        if ($op['_method'] !== 'GET') throw new SdkException('Downloads are only supported for GET operations.');
        $directory = dirname($target);
        if (!is_dir($directory) || !is_writable($directory)) throw new SdkException('Download target directory must exist and be writable.');
        $temporary = tempnam($directory, '.community-sdk-');
        if ($temporary === false) throw new SdkException('Could not create temporary download file.');
        $file = fopen($temporary, 'wb');
        if ($file === false) {
            unlink($temporary);
            throw new SdkException('Could not open temporary download file.');
        }
        try {
            $response = $this->streamingTransport->stream($request, static function (string $chunk) use ($file): void {
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($file, substr($chunk, $offset));
                    if ($written === false || $written === 0) throw new SdkException('Could not write downloaded data.');
                    $offset += $written;
                }
            }, $chunkSize);
            fclose($file);
            $result = $this->resultFor($op, $response);
            if (!rename($temporary, $target)) throw new SdkException('Could not move completed download into place.');
            return $result;
        } catch (\Throwable $e) {
            if (is_resource($file)) fclose($file);
            if (is_file($temporary)) unlink($temporary);
            throw $e;
        }
    }

    /** @param array<string,mixed> $arguments @param callable(array{data:string,event:string,id:?string,retry:?int}):void $onEvent */
    public function streamEvents(string $operationId, callable $onEvent, array $arguments = [], int $chunkSize = 8192): Result
    {
        if ($this->streamingTransport === null) throw new SdkException('SSE requires a StreamingTransport.');
        [$op, $request] = $this->buildRequest($operationId, $arguments);
        if ($op['_method'] !== 'GET') throw new SdkException('Server-sent events require a GET operation.');
        $headers = $request->headers;
        $hasAccept = false;
        foreach (array_keys($headers) as $name) if (strcasecmp($name, 'Accept') === 0) $hasAccept = true;
        if (!$hasAccept) $headers['Accept'] = 'text/event-stream';
        $request = new Request($request->method, $request->url, $headers, $request->body);
        $buffer = '';
        /** @var array{data:list<string>,event:string,id:?string,retry:?int} $event */
        $event = ['data' => [], 'event' => 'message', 'id' => null, 'retry' => null];
        $dispatch = static function () use (&$event, $onEvent): void {
            if ($event['data'] !== []) $onEvent([
                'data' => implode("\n", $event['data']),
                'event' => $event['event'],
                'id' => $event['id'],
                'retry' => $event['retry'],
            ]);
            $event = ['data' => [], 'event' => 'message', 'id' => null, 'retry' => null];
        };
        $response = $this->streamingTransport->stream($request, static function (string $chunk) use (&$buffer, &$event, $dispatch): void {
            $buffer .= $chunk;
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $newline), "\r");
                $buffer = substr($buffer, $newline + 1);
                if ($line === '') {
                    $dispatch();
                } elseif (!str_starts_with($line, ':')) {
                    [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
                    if (str_starts_with($value, ' ')) $value = substr($value, 1);
                    if ($field === 'data') $event['data'][] = $value;
                    elseif ($field === 'event') $event['event'] = $value;
                    elseif ($field === 'id' && !str_contains($value, "\0")) $event['id'] = $value;
                    elseif ($field === 'retry' && ctype_digit($value)) $event['retry'] = (int) $value;
                }
            }
        }, $chunkSize);
        if ($buffer !== '') {
            $buffer .= "\n\n";
            $line = $buffer;
            $buffer = '';
            foreach (explode("\n", str_replace("\r", '', $line)) as $fieldLine) {
                if (str_starts_with($fieldLine, 'data:')) $event['data'][] = ltrim(substr($fieldLine, 5), ' ');
                elseif (str_starts_with($fieldLine, 'event:')) $event['event'] = ltrim(substr($fieldLine, 6), ' ');
                elseif (str_starts_with($fieldLine, 'id:')) $event['id'] = ltrim(substr($fieldLine, 3), ' ');
                elseif (str_starts_with($fieldLine, 'retry:') && ctype_digit(trim(substr($fieldLine, 6)))) $event['retry'] = (int) trim(substr($fieldLine, 6));
            }
            $dispatch();
        }
        if ($response->status < 400 && $response->contentType() !== 'text/event-stream') {
            throw new SdkException('Expected a text/event-stream response.');
        }
        return $this->resultFor($op, $response);
    }

    /** @param array<string,mixed> $arguments @return iterable<Result> */
    public function paginate(string $operationId, array $arguments = []): iterable
    {
        $op = $this->registry->get($operationId);
        if ($op['_method'] !== 'GET') throw new SdkException('Automatic Link-header pagination is only available for GET operations.');
        [$op, $request] = $this->buildRequest($operationId, $arguments);
        $currentUrl = $request->url;
        $result = $this->resultFor($op, $this->transport->send($request));
        while (true) {
            yield $result;
            $link = $this->nextLink($result->headers);
            if ($link === null) return;
            $url = $this->sameOriginNextUrl($link, $currentUrl);
            $headers = $this->defaultHeaders;
            foreach ($op['_parameters'] as $parameter) {
                $name = $parameter['name'];
                if (!array_key_exists($name, $arguments)) continue;
                if ($parameter['in'] === 'header') $headers[$name] = $this->parameterSerializer->header($parameter, $arguments[$name]);
            }
            $query = $cookies = [];
            $this->securityResolver->apply($op, $this->token, $this->credentials, $headers, $query, $cookies);
            if ($query !== []) $url = $this->appendSecurityQuery($url, $query);
            $this->mergeCookies($headers, $cookies);
            $currentUrl = $url;
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

    private function sameOriginNextUrl(string $link, string $currentUrl): string
    {
        $base = parse_url($this->baseUrl);
        if (preg_match('/[\x00-\x20\\\\]/', $link)) throw new SdkException('Invalid pagination next link.');
        $parts = parse_url($link);
        if (!is_array($parts)) throw new SdkException('Invalid pagination next link.');
        if (!isset($parts['scheme'])) {
            if (str_starts_with($link, '//')) throw new SdkException('Pagination next links must be same-origin.');
            $current = parse_url($currentUrl);
            if (!is_array($current)) throw new SdkException('Invalid current pagination URL.');
            if (str_starts_with($link, '/')) {
                $link = $this->origin($base).$link;
            } elseif (str_starts_with($link, '?')) {
                $link = $this->origin($current).($current['path'] ?? '/').$link;
            } else {
                $path = $current['path'] ?? '/';
                $directory = substr($path, 0, (int) strrpos($path, '/') + 1);
                $relative = explode('?', $link, 2)[0];
                $segments = [];
                foreach (explode('/', $directory.$relative) as $segment) {
                    if ($segment === '' || $segment === '.') continue;
                    if ($segment === '..') array_pop($segments);
                    else $segments[] = $segment;
                }
                $suffix = str_contains($link, '?') ? '?'.explode('?', $link, 2)[1] : '';
                $link = $this->origin($current).'/'.implode('/', $segments).$suffix;
            }
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

    /** @param list<string> $query */
    private function appendSecurityQuery(string $url, array $query): string
    {
        $question = strpos($url, '?');
        $path = $question === false ? $url : substr($url, 0, $question);
        $existing = $question === false ? [] : explode('&', substr($url, $question + 1));
        foreach ($query as $pair) {
            [$name] = explode('=', $pair, 2);
            $decodedName = rawurldecode($name);
            $existing = array_values(array_filter($existing, static function (string $existingPair) use ($decodedName): bool {
                [$existingName] = explode('=', $existingPair, 2);
                return rawurldecode($existingName) !== $decodedName;
            }));
            $existing[] = $pair;
        }
        return $existing === [] ? $path : $path.'?'.implode('&', $existing);
    }

    /** @param array<string,string> $headers @param list<string> $cookies */
    private function mergeCookies(array &$headers, array $cookies): void
    {
        if ($cookies === []) return;
        $existing = '';
        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, 'Cookie') === 0) {
                $existing = $headers[$name];
                unset($headers[$name]);
            }
        }
        $headers['Cookie'] = $existing === '' ? implode('; ', $cookies) : $existing.'; '.implode('; ', $cookies);
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
        if ($type === '') return $content['application/json']['schema'] ?? null;
        if (isset($content[$type]['schema'])) return $content[$type]['schema'];
        foreach ($content as $mediaType => $media) {
            if (is_string($mediaType) && is_array($media) && isset($media['schema']) && $this->mediaTypeMatches($mediaType, $type)) {
                return $media['schema'];
            }
        }
        return null;
    }

    private function mediaTypeMatches(string $pattern, string $actual): bool
    {
        $pattern = strtolower(trim(explode(';', $pattern, 2)[0]));
        $actual = strtolower(trim(explode(';', $actual, 2)[0]));
        if ($pattern === '*/*' || $pattern === $actual) return true;
        [$patternType, $patternSubtype] = array_pad(explode('/', $pattern, 2), 2, '');
        [$actualType, $actualSubtype] = array_pad(explode('/', $actual, 2), 2, '');
        if ($patternType !== '*' && $patternType !== $actualType) return false;
        if ($patternSubtype === '*') return true;
        if (str_starts_with($patternSubtype, '*+')) {
            return str_ends_with($actualSubtype, substr($patternSubtype, 1));
        }
        return false;
    }
}
