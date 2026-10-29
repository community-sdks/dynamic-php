<?php

declare(strict_types=1);
namespace CommunitySDKs;

use CommunitySDKs\Exception\ApiException;
use CommunitySDKs\Exception\SdkException;
use CommunitySDKs\Exception\ValidationException;
use CommunitySDKs\Http\{Request, Result, Response, StreamTransport, Transport};
use CommunitySDKs\OpenApi\{Document, ParameterSerializer, Registry, RequestBodySerializer, Validator};

/** Universal operationId client. No endpoint-specific PHP classes are created. */
readonly class Client
{
    private Registry $registry;
    private Validator $validator;
    private ParameterSerializer $parameterSerializer;
    private RequestBodySerializer $requestBodySerializer;
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
    ) {
        $this->registry = new Registry($document);
        $this->validator = new Validator($document);
        $this->parameterSerializer = new ParameterSerializer();
        $this->requestBodySerializer = new RequestBodySerializer();
        $this->transport = $transport ?? new StreamTransport();
        $server = $baseUrl ?? ($document->data['servers'][0]['url'] ?? null);
        if (!is_string($server) || !preg_match('~^https?://~i', $server)) {
            throw new SdkException('Provide an absolute HTTP(S) base URL.');
        }
        $this->baseUrl = rtrim($server, '/');
    }

    public static function fromFile(string $file, ?string $baseUrl = null, ?Transport $transport = null, ?string $token = null): self
    {
        return new self(Document::fromFile($file), $baseUrl, $transport, $token);
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
        if ($cookies !== []) {
            $headers['Cookie'] = isset($headers['Cookie']) ? $headers['Cookie'].'; '.implode('; ', $cookies) : implode('; ', $cookies);
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
        if ($this->token !== null) {
            // Explicit Bearer auth only; do not silently handle different security schemes.
            $headers['Authorization'] = 'Bearer '.$this->token;
        }
        $url = $this->baseUrl.'/'.ltrim($path, '/');
        if ($query !== []) $url .= '?'.implode('&', $query);
        $response = $this->transport->send(new Request($op['_method'], $url, $headers, $body));
        $decoded = $this->decode($response);
        $schema = $this->responseSchema($op, $response);
        if ($this->validateResponses && $schema !== null && $decoded !== null) {
            $this->validator->assert($decoded, $schema, '$.response');
        }
        if ($response->status >= 400) {
            throw new ApiException($response->status, $decoded, $response->headers, $response->body);
        }
        return new Result($response->status, $response->headers, $decoded, $response->body);
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
