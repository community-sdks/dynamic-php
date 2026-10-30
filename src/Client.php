<?php

declare(strict_types=1);
namespace CommunitySDKs;

use CommunitySDKs\Exception\ApiException;
use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Exception\ValidationException;
use CommunitySDKs\Http\{HttpMiddleware, MiddlewareTransport, MultipartDecoder, PaginationStrategy, Request, Result, Response, RetryingTransport, StreamTransport, StreamingTransport, Transport};
use CommunitySDKs\OpenApi\{Document, ParameterSerializer, Registry, RequestBodySerializer, SecurityResolver, Validator};
use CommunitySDKs\Security\OAuthTokenProvider;
use CommunitySDKs\Security\SecurityCredentialProvider;

/** Universal operationId client. No endpoint-specific PHP classes are created. */
readonly class Client
{
    private Registry $registry;
    private Validator $validator;
    private ParameterSerializer $parameterSerializer;
    private RequestBodySerializer $requestBodySerializer;
    private MultipartDecoder $multipartDecoder;
    private SecurityResolver $securityResolver;
    private Transport $transport;
    private ?StreamingTransport $streamingTransport;
    private string $baseUrl;
    private ?string $baseUrlOverride;

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
        /** @var list<string> exact lowercased or case-insensitive allowed hostnames */
        private array $allowedHosts = [],
        private ?OAuthTokenProvider $oauthTokenProvider = null,
        /** @var array<string,string> server variable overrides */
        private array $serverVariables = [],
        private ?SecurityCredentialProvider $credentialProvider = null,
    ) {
        $this->registry = new Registry($document);
        $this->validator = new Validator($document);
        $this->parameterSerializer = new ParameterSerializer();
        $this->requestBodySerializer = new RequestBodySerializer();
        $this->multipartDecoder = new MultipartDecoder();
        $this->securityResolver = new SecurityResolver($document);
        $configuredTransport = $transport ?? new StreamTransport();
        if ($middleware !== []) $configuredTransport = new MiddlewareTransport($configuredTransport, $middleware);
        $this->streamingTransport = $configuredTransport instanceof StreamingTransport ? $configuredTransport : null;
        if ($maxRetries > 0) $configuredTransport = new RetryingTransport($configuredTransport, $maxRetries);
        $this->transport = $configuredTransport;
        $this->baseUrlOverride = $baseUrl === null ? null : $this->validateBaseUrl($baseUrl);
        $serverList = $document->data['servers'] ?? [];
        $serverDefinition = is_array($serverList[0] ?? null) ? $serverList[0] : null;
        if ($serverDefinition === null) {
            foreach ($this->registry->all() as $operation) {
                if (is_array($operation['_servers'][0] ?? null)) {
                    $serverDefinition = $operation['_servers'][0];
                    break;
                }
            }
        }
        $server = $serverDefinition === null
            ? $this->baseUrlOverride
            : $this->initialServerUrl($serverDefinition);
        if ($server === null) throw new SdkException('Provide an absolute HTTP(S) base URL.');
        if ($maxRetries < 0) throw new \InvalidArgumentException('maxRetries must be non-negative.');
        $this->baseUrl = rtrim($this->validateBaseUrl($server), '/');
    }

    /** @param array<string,string> $credentials @param list<HttpMiddleware> $middleware @param list<string> $allowedHosts @param array<string,string> $serverVariables */
    public static function fromFile(string $file, ?string $baseUrl = null, ?Transport $transport = null, ?string $token = null, array $credentials = [], array $middleware = [], int $maxRetries = 0, array $allowedHosts = [], ?OAuthTokenProvider $oauthTokenProvider = null, array $serverVariables = [], ?SecurityCredentialProvider $credentialProvider = null): self
    {
        return new self(Document::fromFile($file), $baseUrl, $transport, $token, credentials: $credentials, middleware: $middleware, maxRetries: $maxRetries, allowedHosts: $allowedHosts, oauthTokenProvider: $oauthTokenProvider, serverVariables: $serverVariables, credentialProvider: $credentialProvider);
    }

    private function validateBaseUrl(string $server): string
    {
        if (!preg_match('~^https?://~i', $server)) throw new SdkException('Provide an absolute HTTP(S) base URL.');
        $parts = parse_url($server);
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\\\\]/', $server)) {
            throw new SdkException('Base URL must not contain credentials, query, fragment, whitespace, or invalid characters.');
        }
        if ($this->allowedHosts !== [] && !in_array(strtolower($parts['host']), array_map('strtolower', $this->allowedHosts), true)) {
            throw new SdkException('Base URL host is not in the configured allowlist.');
        }
        return rtrim($server, '/');
    }

    /** @param array<string,mixed> $server */
    private function serverUrl(array $server, ?string $relativeBase = null): string
    {
        $url = $server['url'] ?? null;
        if (!is_string($url)) throw new SdkException('OpenAPI server URL must be a string.');
        $variables = $server['variables'] ?? [];
        if (!is_array($variables)) throw new SdkException('OpenAPI server variables must be an object.');
        if (preg_match_all('/\{([A-Za-z0-9._-]+)\}/', $url, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $definition = $variables[$name] ?? null;
                if (!is_array($definition)) throw new SdkException("No definition for server variable {$name}.");
                $value = $this->serverVariables[$name] ?? $definition['default'] ?? null;
                if (!is_string($value)) throw new SdkException("No string value for server variable {$name}.");
                if (isset($definition['enum']) && is_array($definition['enum']) && !in_array($value, $definition['enum'], true)) {
                    throw new SdkException("Invalid value for server variable {$name}.");
                }
                $url = str_replace('{'.$name.'}', rawurlencode($value), $url);
            }
        }
        if (str_contains($url, '{') || str_contains($url, '}')) throw new SdkException('Unresolved OpenAPI server variable.');
        if (!preg_match('~^https?://~i', $url)) {
            $relativeBase ??= $this->baseUrlOverride;
            if ($relativeBase === null) throw new SdkException('Relative OpenAPI server URLs require a trusted absolute baseUrl or an absolute document server.');
            $baseParts = parse_url($relativeBase);
            if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) throw new SdkException('Cannot resolve relative server URL.');
            $origin = strtolower($baseParts['scheme']).'://'.strtolower($baseParts['host']).(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');
            if (str_starts_with($url, '/')) {
                $url = $origin.$url;
            } else {
                $basePath = $baseParts['path'] ?? '/';
                $directory = str_ends_with($basePath, '/') ? $basePath : substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
                $segments = [];
                foreach (explode('/', $directory.$url) as $segment) {
                    if ($segment === '' || $segment === '.') continue;
                    if ($segment === '..') array_pop($segments);
                    else $segments[] = $segment;
                }
                $url = $origin.'/'.implode('/', $segments);
            }
        }
        return $this->validateBaseUrl($url);
    }

    /** @param array<string,mixed> $operation */
    private function baseUrlForOperation(array $operation): string
    {
        $servers = $operation['_servers'] ?? [];
        if (is_array($servers) && is_array($servers[0] ?? null)) {
            $server = $servers[0];
            $url = $server['url'] ?? '';
            if ($this->baseUrlOverride !== null && is_string($url) && preg_match('~^https?://~i', $url) === 1) {
                return $this->baseUrlOverride;
            }
            return $this->serverUrl($server, $this->baseUrlOverride ?? $this->baseUrl);
        }
        return $this->baseUrlOverride ?? $this->baseUrl;
    }

    /** @param array<string,mixed> $server */
    private function initialServerUrl(array $server): string
    {
        $url = $server['url'] ?? null;
        if ($this->baseUrlOverride !== null && is_string($url) && preg_match('~^https?://~i', $url) === 1) {
            return $this->baseUrlOverride;
        }
        return $this->serverUrl($server, $this->baseUrlOverride);
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
        $op['_sourcePath'] = $this->registry->sourcePath($operationId);
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
            if ($place === 'query' && $value === '' && !($parameter['allowEmptyValue'] ?? false)) {
                throw new ValidationException(["{$name}: empty query value requires allowEmptyValue=true"]);
            }
            if (isset($parameter['content'])) {
                $content = $parameter['content'];
                $media = is_array($content) ? reset($content) : null;
                $schema = is_array($media) ? ($media['schema'] ?? []) : [];
                if ($schema !== []) $this->validator->assert($value, $schema, '$.'.$name, $this->registry->parameterSourcePath($operationId, $place, $name));
                $serialized = $this->parameterSerializer->contentValue($parameter, $value);
                if ($place === 'path') {
                    $path = str_replace('{'.$name.'}', rawurlencode($serialized), $path);
                } elseif ($place === 'query') {
                    $query[] = $this->parameterSerializer->contentQuery($name, $serialized);
                } elseif ($place === 'header') {
                    $headers[$name] = $this->parameterSerializer->header($parameter, $serialized);
                } elseif ($place === 'cookie') {
                    $cookies[] = $this->parameterSerializer->contentCookie($name, $serialized);
                }
            } else {
                if (isset($parameter['schema'])) {
                    $this->validator->assert(
                        $value,
                        $parameter['schema'],
                        '$.'.$name,
                        $this->registry->parameterSourcePath($operationId, $place, $name),
                    );
                }
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
        }
        if (str_contains($path, '{')) throw new SdkException('Unresolved path parameter.');
        $body = '';
        if (isset($op['requestBody'])) {
            $resolvedBody = $this->document->resolveWithSource($op['requestBody'], $op['_sourcePath']);
            $requestBody = $resolvedBody['value'];
            $hasBody = array_key_exists('body', $arguments);
            if (!$hasBody && ($requestBody['required'] ?? false)) throw new ValidationException(['body: required']);
            if ($hasBody) {
                $used['body'] = true;
                $contentTypes = $requestBody['content'] ?? [];
                $mediaType = $this->requestBodySerializer->selectMediaType($contentTypes, $arguments['body']);
                $media = $contentTypes[$mediaType];
                $this->validator->assert($arguments['body'], $media['schema'] ?? [], '$.body', $resolvedBody['sourcePath']);
                $serialized = $this->requestBodySerializer->serialize($arguments['body'], $mediaType, $media);
                $body = $serialized['body'];
                $headers['Content-Type'] = $serialized['contentType'];
            }
        }
        $unknown = array_diff(array_keys($arguments), array_keys($used));
        if ($unknown !== []) throw new ValidationException(['Unknown arguments: '.implode(', ', $unknown)]);
        $this->securityResolver->apply($op, $this->token, $this->credentials, $headers, $query, $cookies, $this->oauthTokenProvider, $this->credentialProvider);
        $this->mergeCookies($headers, $cookies);
        $url = $this->baseUrlForOperation($op).'/'.ltrim($path, '/');
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
            $result = $this->downloadResult($op, $response);
            if (!rename($temporary, $target)) throw new SdkException('Could not move completed download into place.');
            return $result;
        } catch (\Throwable $e) {
            if (is_resource($file)) fclose($file);
            if (is_file($temporary)) unlink($temporary);
            throw $e;
        }
    }

    /** @param array<string,mixed> $arguments */
    public function downloadResumable(string $operationId, string $target, array $arguments = [], int $rangeSize = 1_048_576): Result
    {
        if ($rangeSize < 1) throw new \InvalidArgumentException('Range size must be positive.');
        [$op, $request] = $this->buildRequest($operationId, $arguments);
        if ($op['_method'] !== 'GET') throw new SdkException('Downloads are only supported for GET operations.');
        $directory = dirname($target);
        if (!is_dir($directory) || !is_writable($directory)) throw new SdkException('Download target directory must exist and be writable.');

        $partial = $target.'.part';
        $metadataPath = $partial.'.json';
        $urlHash = hash('sha256', $request->url);
        $metadata = $this->readDownloadMetadata($metadataPath, $urlHash);
        if ($metadata === null) {
            if (is_file($partial)) unlink($partial);
            $metadata = ['url' => $urlHash, 'total' => null, 'etag' => null];
            $this->writeDownloadMetadata($metadataPath, $metadata);
        }
        $offset = is_file($partial) ? filesize($partial) : 0;
        if (!is_int($offset)) $offset = 0;
        $lastResult = null;
        if (is_file($partial) && $metadata['total'] !== null && $offset === $metadata['total']) {
            $headers = ['Content-Range' => $offset > 0 ? 'bytes 0-'.($offset - 1).'/'.$offset : 'bytes */0'];
            if (is_string($metadata['etag'])) $headers['ETag'] = $metadata['etag'];
            $this->finishResumableDownload($partial, $metadataPath, $target);
            return new Result(206, $headers, null, '');
        }

        while (true) {
            $headers = $request->headers;
            $headers['Range'] = 'bytes='.$offset.'-'.($offset + $rangeSize - 1);
            if (is_string($metadata['etag'] ?? null) && $metadata['etag'] !== '') $headers['If-Range'] = $metadata['etag'];
            $response = $this->transport->send(new Request('GET', $request->url, $headers));

            if ($response->status === 200) {
                if (file_put_contents($partial, $response->body, LOCK_EX) !== strlen($response->body)) {
                    throw new SdkException('Could not write complete download response.');
                }
                $lastResult = $this->downloadResult($op, $response);
                $this->finishResumableDownload($partial, $metadataPath, $target);
                return $lastResult;
            }
            if ($response->status === 416) {
                $range = $response->header('Content-Range') ?? '';
                if ($metadata['total'] !== null && $offset === $metadata['total'] && preg_match('/^bytes \*\/(\d+)$/i', $range, $match) === 1 && (int) $match[1] === $offset) {
                    if ($lastResult === null) throw new SdkException('A completed partial download has no response result.');
                    $this->finishResumableDownload($partial, $metadataPath, $target);
                    return $lastResult;
                }
                return $this->downloadResult($op, $response);
            }
            if ($response->status !== 206) return $this->downloadResult($op, $response);
            $contentRange = $response->header('Content-Range') ?? '';
            if (preg_match('/^bytes (\d+)-(\d+)\/(\d+)$/i', $contentRange, $match) !== 1) {
                throw new SdkException('Range response has an invalid Content-Range header.');
            }
            $start = (int) $match[1];
            $end = (int) $match[2];
            $total = (int) $match[3];
            if ($start !== $offset || $end < $start || $end >= $total || strlen($response->body) !== $end - $start + 1) {
                throw new SdkException('Range response does not match the requested byte interval.');
            }
            if ($metadata['total'] !== null && $metadata['total'] !== $total) {
                throw new SdkException('Download size changed while resuming.');
            }
            $responseEtag = $response->header('ETag');
            if ($offset > 0 && is_string($metadata['etag']) && $metadata['etag'] !== ''
                && ($responseEtag === null || !hash_equals($metadata['etag'], $responseEtag))) {
                if (is_file($partial)) unlink($partial);
                if (is_file($metadataPath)) unlink($metadataPath);
                throw new SdkException('Download ETag changed while resuming; the partial file was discarded.');
            }
            if ($offset === 0 && is_string($response->header('ETag'))) $metadata['etag'] = $response->header('ETag');
            $metadata['total'] = $total;
            if (file_put_contents($partial, $response->body, FILE_APPEND | LOCK_EX) !== strlen($response->body)) {
                throw new SdkException('Could not append download range.');
            }
            $offset = $end + 1;
            $this->writeDownloadMetadata($metadataPath, $metadata);
            $lastResult = $this->downloadResult($op, $response);
            if ($offset === $total) {
                $this->finishResumableDownload($partial, $metadataPath, $target);
                return $lastResult;
            }
        }
    }

    /** @return array{url:string,total:?int,etag:?string}|null */
    private function readDownloadMetadata(string $path, string $urlHash): ?array
    {
        if (!is_file($path) || !is_readable($path)) return null;
        $raw = file_get_contents($path);
        if (!is_string($raw)) return null;
        try {
            $metadata = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($metadata) || ($metadata['url'] ?? null) !== $urlHash) return null;
        $total = $metadata['total'] ?? null;
        $etag = $metadata['etag'] ?? null;
        if ($total !== null && (!is_int($total) || $total < 0)) return null;
        if ($etag !== null && !is_string($etag)) return null;
        return ['url' => $urlHash, 'total' => $total, 'etag' => $etag];
    }

    /** @param array{url:string,total:?int,etag:?string} $metadata */
    private function writeDownloadMetadata(string $path, array $metadata): void
    {
        $temporary = $path.'.tmp';
        $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $path)) {
            if (is_file($temporary)) unlink($temporary);
            throw new SdkException('Could not persist resumable download metadata.');
        }
    }

    private function finishResumableDownload(string $partial, string $metadata, string $target): void
    {
        if (!rename($partial, $target)) throw new SdkException('Could not move completed download into place.');
        if (is_file($metadata)) unlink($metadata);
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
        $dispatch();
        if ($response->status < 400 && $response->contentType() !== 'text/event-stream') {
            throw new SdkException('Expected a text/event-stream response.');
        }
        return $this->resultFor($op, $response);
    }

    /** @param array<string,mixed> $arguments @return iterable<Result> */
    public function paginate(string $operationId, array $arguments = [], ?PaginationStrategy $strategy = null, int $maxPages = 1000): iterable
    {
        if ($maxPages < 1) throw new \InvalidArgumentException('Maximum page count must be positive.');
        $op = $this->registry->get($operationId);
        if ($op['_method'] !== 'GET') throw new SdkException('Automatic Link-header pagination is only available for GET operations.');
        [$op, $request] = $this->buildRequest($operationId, $arguments);
        $currentUrl = $request->url;
        $result = $this->resultFor($op, $this->transport->send($request));
        $pageCount = 0;
        while (true) {
            $pageCount++;
            yield $result;
            $link = $this->nextLink($result->headers);
            if ($link === null) {
                $nextArguments = $strategy?->nextArguments($result, $arguments);
                if ($nextArguments === null) return;
                if ($pageCount >= $maxPages) throw new SdkException("Pagination exceeded the maximum page count ({$maxPages}).");
                $arguments = $nextArguments;
                [$op, $request] = $this->buildRequest($operationId, $arguments);
                $currentUrl = $request->url;
                $result = $this->resultFor($op, $this->transport->send($request));
                continue;
            }
            if ($pageCount >= $maxPages) throw new SdkException("Pagination exceeded the maximum page count ({$maxPages}).");
            $url = $this->sameOriginNextUrl($link, $currentUrl);
            $headers = $this->defaultHeaders;
            foreach ($op['_parameters'] as $parameter) {
                $name = $parameter['name'];
                if (!array_key_exists($name, $arguments)) continue;
                if ($parameter['in'] === 'header') $headers[$name] = $this->parameterSerializer->header($parameter, $arguments[$name]);
            }
            $query = $cookies = [];
            $this->securityResolver->apply($op, $this->token, $this->credentials, $headers, $query, $cookies, $this->oauthTokenProvider, $this->credentialProvider);
            if ($query !== []) $url = $this->appendSecurityQuery($url, $query);
            $this->mergeCookies($headers, $cookies);
            $currentUrl = $url;
            $result = $this->resultFor($op, $this->transport->send(new Request('GET', $url, $headers)));
        }
    }

    /** @param array<string,mixed> $arguments @param callable(Result):iterable<mixed> $items @param callable(mixed):int|float|string $identity @return iterable<mixed> */
    public function paginateItems(
        string $operationId,
        callable $items,
        callable $identity,
        array $arguments = [],
        ?PaginationStrategy $strategy = null,
        int $maxPages = 1000,
    ): iterable {
        $seen = [];
        foreach ($this->paginate($operationId, $arguments, $strategy, $maxPages) as $page) {
            foreach ($items($page) as $item) {
                $key = $identity($item);
                if (!is_int($key) && !is_float($key) && !is_string($key)) {
                    throw new SdkException('Pagination item identity must be a scalar key.');
                }
                $normalizedKey = get_debug_type($key).':'.(string) $key;
                if (isset($seen[$normalizedKey])) continue;
                $seen[$normalizedKey] = true;
                yield $item;
            }
        }
    }

    /** @param array<string,mixed> $operation */
    private function resultFor(array $operation, Response $response): Result
    {
        $decoded = $this->decode($response);
        $schema = $this->responseSchema($operation, $response);
        if ($this->validateResponses && $schema !== null && $decoded !== null) {
            $this->validator->assert($decoded, $schema['schema'], '$.response', $schema['sourcePath']);
        }
        if ($response->status >= 400) {
            throw new ApiException($response->status, $decoded, $response->headers, $response->body);
        }
        return new Result($response->status, $response->headers, $decoded, $response->body);
    }

    /** @param array<string,mixed> $operation */
    private function downloadResult(array $operation, Response $response): Result
    {
        if ($response->status >= 400) return $this->resultFor($operation, $response);
        return new Result($response->status, $response->headers, null, $response->body);
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
        $current = parse_url($currentUrl);
        if (!is_array($current)) throw new SdkException('Invalid current pagination URL.');
        $base = $current;
        if (preg_match('/[\x00-\x20\\\\]/', $link)) throw new SdkException('Invalid pagination next link.');
        $parts = parse_url($link);
        if (!is_array($parts)) throw new SdkException('Invalid pagination next link.');
        if (!isset($parts['scheme'])) {
            if (str_starts_with($link, '//')) throw new SdkException('Pagination next links must be same-origin.');
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
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !is_array($parts)) {
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
        if (str_starts_with($type, 'multipart/')) return $this->multipartDecoder->decode($response);
        if ($type === 'application/json' || str_ends_with($type, '+json')) {
            return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        }
        return $response->body;
    }

    /** @param array<string,mixed> $operation @return array{schema:array<string,mixed>,sourcePath:?string}|null */
    private function responseSchema(array $operation, Response $response): ?array
    {
        $responses = $operation['responses'] ?? [];
        $status = (string)$response->status;
        $entry = $responses[$status] ?? $responses[substr($status, 0, 1).'XX'] ?? $responses['default'] ?? null;
        if ($entry === null) return null;
        $resolvedEntry = $this->document->resolveWithSource($entry, $operation['_sourcePath'] ?? null);
        $entry = $resolvedEntry['value'];
        $type = $response->contentType();
        $content = $entry['content'] ?? [];
        if ($type === '') {
            $schema = $content['application/json']['schema'] ?? null;
            return is_array($schema) ? ['schema' => $schema, 'sourcePath' => $resolvedEntry['sourcePath']] : null;
        }
        if (isset($content[$type]['schema']) && is_array($content[$type]['schema'])) {
            return ['schema' => $content[$type]['schema'], 'sourcePath' => $resolvedEntry['sourcePath']];
        }
        foreach ($content as $mediaType => $media) {
            if (is_string($mediaType) && is_array($media) && isset($media['schema']) && $this->mediaTypeMatches($mediaType, $type)) {
                return is_array($media['schema']) ? ['schema' => $media['schema'], 'sourcePath' => $resolvedEntry['sourcePath']] : null;
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
