<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\Client;
use CommunitySDKs\Exception\{ApiException, SdkException, ValidationException};
use CommunitySDKs\Http\{HttpMiddleware, MetricsMiddleware, MiddlewareTransport, Request, Response, Upload};
use CommunitySDKs\OpenApi\Registry;
use CommunitySDKs\Security\OAuthTokenProvider;
use CommunitySDKs\OpenApi\Document;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function sdk(FakeTransport $transport, int $maxRetries = 0): Client
    {
        return Client::fromFile(dirname(__DIR__).'/specs/jsonplaceholder.openapi.json', transport: $transport, maxRetries: $maxRetries);
    }

    /** @param list<array<string,mixed>> $parameters */
    private function parameterSdk(FakeTransport $transport, array $parameters, string $path = '/items/{id}'): Client
    {
        return new Client(new Document([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://example.test']],
            'paths' => [$path => ['get' => [
                'operationId' => 'inspect',
                'parameters' => $parameters,
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
        ]), transport: $transport);
    }

    /** @param array<string,mixed> $requestBody */
    private function bodySdk(FakeTransport $transport, array $requestBody): Client
    {
        return new Client(new Document([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://example.test']],
            'paths' => ['/upload' => ['post' => [
                'operationId' => 'sendBody',
                'requestBody' => $requestBody,
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
        ]), transport: $transport);
    }

    public function testQuerySerializationSupportsExplodedArraysAndDeepObjects(): void
    {
        $transport = new FakeTransport();
        $sdk = $this->parameterSdk($transport, [
            ['name' => 'tags', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ['name' => 'filter', 'in' => 'query', 'style' => 'deepObject', 'explode' => true, 'schema' => ['type' => 'object']],
        ], '/items');

        $sdk->inspect(tags: ['red', 'blue'], filter: ['active' => true]);

        self::assertSame('https://example.test/items?tags=red&tags=blue&filter%5Bactive%5D=1', $transport->lastRequest->url);
    }

    public function testPathStylesAndCookieParameters(): void
    {
        $transport = new FakeTransport();
        $sdk = $this->parameterSdk($transport, [
            ['name' => 'id', 'in' => 'path', 'required' => true, 'style' => 'matrix', 'schema' => ['type' => 'string']],
            ['name' => 'tag', 'in' => 'cookie', 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ]);

        $sdk->inspect(id: 'a/b', tag: ['one', 'two']);

        self::assertSame('https://example.test/items/;id=a%2Fb', $transport->lastRequest->url);
        self::assertSame('tag=one; tag=two', $transport->lastRequest->headers['Cookie']);
    }

    public function testDelimitedQueryStylesAndExplodedHeaderObject(): void
    {
        $transport = new FakeTransport();
        $sdk = $this->parameterSdk($transport, [
            ['name' => 'labels', 'in' => 'query', 'style' => 'spaceDelimited', 'explode' => false, 'schema' => ['type' => 'array']],
            ['name' => 'flags', 'in' => 'query', 'style' => 'pipeDelimited', 'explode' => false, 'schema' => ['type' => 'array']],
            ['name' => 'metadata', 'in' => 'header', 'explode' => true, 'schema' => ['type' => 'object']],
        ], '/items');

        $sdk->inspect(labels: ['red', 'blue'], flags: ['a', 'b'], metadata: ['role' => 'admin user']);

        self::assertSame('https://example.test/items?labels=red%20blue&flags=a%7Cb', $transport->lastRequest->url);
        self::assertSame('role=admin user', $transport->lastRequest->headers['metadata']);
    }

    public function testFormUrlEncodedRequestBodyUsesDeclaredEncoding(): void
    {
        $transport = new FakeTransport();
        $sdk = $this->bodySdk($transport, [
            'required' => true,
            'content' => ['application/x-www-form-urlencoded' => [
                'schema' => ['type' => 'object'],
                'encoding' => ['tags' => ['style' => 'form', 'explode' => true]],
            ]],
        ]);

        $sdk->sendBody(body: ['title' => 'Hello world', 'tags' => ['one', 'two']]);

        self::assertSame('application/x-www-form-urlencoded', $transport->lastRequest->headers['Content-Type']);
        self::assertSame('title=Hello%20world&tags=one&tags=two', $transport->lastRequest->body);
    }

    public function testMultipartRequestBodyEncodesUploadParts(): void
    {
        $transport = new FakeTransport();
        $sdk = $this->bodySdk($transport, [
            'content' => ['multipart/form-data' => ['schema' => ['type' => 'object']]],
        ]);

        $sdk->sendBody(body: ['caption' => 'A photo', 'file' => new Upload("\x00\xFFbytes", 'photo.bin', 'application/octet-stream')]);

        self::assertStringStartsWith('multipart/form-data; boundary=CommunitySDKs', $transport->lastRequest->headers['Content-Type']);
        self::assertStringContainsString('name="caption"', $transport->lastRequest->body);
        self::assertStringContainsString('filename="photo.bin"', $transport->lastRequest->body);
        self::assertStringContainsString("\x00\xFFbytes", $transport->lastRequest->body);
    }

    public function testBinaryRequestBodyPreservesBytes(): void
    {
        $transport = new FakeTransport();
        $sdk = $this->bodySdk($transport, [
            'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
        ]);

        $sdk->sendBody(body: new Upload("\x00\xFFbytes", 'payload.bin'));

        self::assertSame('application/octet-stream', $transport->lastRequest->headers['Content-Type']);
        self::assertSame("\x00\xFFbytes", $transport->lastRequest->body);
    }

    public function testSecurityInheritanceAndOperationOverride(): void
    {
        $transport = new FakeTransport();
        $sdk = new Client(new Document([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://example.test']],
            'security' => [['bearerAuth' => []]],
            'components' => ['securitySchemes' => [
                'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                'apiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            ]],
            'paths' => ['/secure' => ['get' => [
                'operationId' => 'secure',
                'security' => [['apiKeyAuth' => []]],
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
        ]), transport: $transport, token: 'ignored-bearer', credentials: ['apiKeyAuth' => 'secret-key']);

        $sdk->secure();

        self::assertSame('secret-key', $transport->lastRequest->headers['X-API-Key']);
        self::assertArrayNotHasKey('Authorization', $transport->lastRequest->headers);
    }

    public function testSecurityRequirementsSupportApiKeyAndAlternatives(): void
    {
        $transport = new FakeTransport();
        $sdk = new Client(new Document([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://example.test']],
            'security' => [['queryKey' => [], 'cookieKey' => []], ['bearerAuth' => []]],
            'components' => ['securitySchemes' => [
                'queryKey' => ['type' => 'apiKey', 'in' => 'query', 'name' => 'api_key'],
                'cookieKey' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'session'],
                'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
            ]],
            'paths' => ['/secure' => ['get' => [
                'operationId' => 'secure',
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
        ]), transport: $transport, token: 'access-token');

        $sdk->secure();

        self::assertSame('https://example.test/secure', $transport->lastRequest->url);
        self::assertSame('Bearer access-token', $transport->lastRequest->headers['Authorization']);
        self::assertArrayNotHasKey('Cookie', $transport->lastRequest->headers);
    }

    public function testApiKeySecuritySchemeAppliesQueryAndCookieTogether(): void
    {
        $transport = new FakeTransport();
        $sdk = new Client(new Document([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://example.test']],
            'security' => [['queryKey' => [], 'cookieKey' => []]],
            'components' => ['securitySchemes' => [
                'queryKey' => ['type' => 'apiKey', 'in' => 'query', 'name' => 'api_key'],
                'cookieKey' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'session'],
            ]],
            'paths' => ['/secure' => ['get' => [
                'operationId' => 'secure',
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
        ]), transport: $transport, credentials: ['queryKey' => 'query secret', 'cookieKey' => 'cookie secret']);

        $sdk->secure();

        self::assertSame('https://example.test/secure?api_key=query%20secret', $transport->lastRequest->url);
        self::assertSame('session=cookie%20secret', $transport->lastRequest->headers['Cookie']);
    }

    public function testPaginationFollowsSameOriginLinkHeader(): void
    {
        $transport = new FakeTransport(responses: [
            new Response(200, ['Content-Type' => 'application/json', 'Link' => '<https://example.test/items?page=2>; rel="next"'], '[1]'),
            new Response(200, ['Content-Type' => 'application/json'], '[2]'),
        ]);
        $sdk = new Client(new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://example.test']],
            'paths' => ['/items' => ['get' => ['operationId' => 'listItems', 'responses' => ['200' => ['description' => 'ok']]]]],
        ]), transport: $transport);

        $pages = iterator_to_array($sdk->paginate('listItems'));

        self::assertSame([[1], [2]], array_map(static fn ($page) => $page->data, $pages));
        self::assertSame('https://example.test/items?page=2', $transport->lastRequest->url);
    }

    public function testPaginationResolvesQueryRelativeLinksAndPreservesApiKey(): void
    {
        $transport = new FakeTransport(responses: [
            new Response(200, ['Content-Type' => 'application/json', 'Link' => '<?cursor=next&api_key=untrusted>; rel="next"'], '[]'),
            new Response(200, ['Content-Type' => 'application/json'], '[]'),
        ]);
        $sdk = new Client(new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://example.test']],
            'security' => [['apiKey' => []]],
            'components' => ['securitySchemes' => ['apiKey' => ['type' => 'apiKey', 'in' => 'query', 'name' => 'api_key']]],
            'paths' => ['/items' => ['get' => ['operationId' => 'listItems', 'responses' => ['200' => ['description' => 'ok']]]]],
        ]), transport: $transport, credentials: ['apiKey' => 'trusted']);

        iterator_to_array($sdk->paginate('listItems'));

        self::assertSame('https://example.test/items?cursor=next&api_key=trusted', $transport->lastRequest->url);
    }

    public function testVendorJsonResponseMatchesStructuredSuffixMediaRange(): void
    {
        $transport = new FakeTransport(new Response(200, ['Content-Type' => 'application/problem+json'], '{"code":42}'));
        $sdk = new Client(new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://example.test']],
            'paths' => ['/problem' => ['get' => ['operationId' => 'getProblem', 'responses' => ['200' => [
                'description' => 'ok',
                'content' => ['application/*+json' => ['schema' => [
                    'type' => 'object', 'properties' => ['code' => ['type' => 'string']], 'required' => ['code'],
                ]]],
            ]]]]],
        ]), transport: $transport);

        $this->expectException(ValidationException::class);
        $sdk->getProblem();
    }

    public function testBaseUrlAllowlistRejectsUnapprovedHosts(): void
    {
        $document = new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://api.example.test']], 'paths' => [],
        ]);

        $this->expectException(SdkException::class);
        new Client($document, baseUrl: 'https://attacker.example.test', allowedHosts: ['api.example.test']);
    }

    public function testParameterContentSerializesJsonQueryAndTextHeader(): void
    {
        $transport = new FakeTransport();
        $sdk = $this->parameterSdk($transport, [
            ['name' => 'filter', 'in' => 'query', 'content' => ['application/json' => [
                'schema' => ['type' => 'object', 'properties' => ['active' => ['type' => 'boolean']]],
            ]]],
            ['name' => 'X-Label', 'in' => 'header', 'content' => ['text/plain' => ['schema' => ['type' => 'string']]]],
        ], '/items');

        $sdk->inspect(...['filter' => ['active' => true], 'X-Label' => 'daily report']);

        self::assertSame('https://example.test/items?filter=%7B%22active%22%3Atrue%7D', $transport->lastRequest->url);
        self::assertSame('daily report', $transport->lastRequest->headers['X-Label']);
    }

    public function testOAuthProviderReceivesRequestedScopes(): void
    {
        $transport = new FakeTransport();
        $provider = new class implements OAuthTokenProvider {
            public string $schemeName = '';
            /** @var list<string> */
            public array $scopes = [];
            public function accessToken(string $schemeName, array $scopes): string
            {
                $this->schemeName = $schemeName;
                $this->scopes = $scopes;
                return 'fresh-access-token';
            }
        };
        $sdk = new Client(new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://example.test']],
            'security' => [['oauth' => ['items:read', 'profile:read']]],
            'components' => ['securitySchemes' => ['oauth' => ['type' => 'oauth2', 'flows' => [
                'clientCredentials' => ['tokenUrl' => 'https://auth.example.test/token', 'scopes' => ['items:read' => 'Read items', 'profile:read' => 'Read profile']],
            ]]]],
            'paths' => ['/secure' => ['get' => ['operationId' => 'secure', 'responses' => ['200' => ['description' => 'ok']]]]],
        ]), transport: $transport, oauthTokenProvider: $provider);

        $sdk->secure();

        self::assertSame('oauth', $provider->schemeName);
        self::assertSame(['items:read', 'profile:read'], $provider->scopes);
        self::assertSame('Bearer fresh-access-token', $transport->lastRequest->headers['Authorization']);
    }

    public function testMultipartResponseDecodesJsonAndBinaryParts(): void
    {
        $body = "--demo\r\nContent-Disposition: form-data; name=\"metadata\"\r\nContent-Type: application/json\r\n\r\n{\"ok\":true}\r\n"
            ."--demo\r\nContent-Disposition: form-data; name=\"file\"; filename=\"photo.bin\"\r\nContent-Type: application/octet-stream\r\n\r\n\x00\xFFraw\r\n--demo--\r\n";
        $transport = new FakeTransport(new Response(200, ['Content-Type' => 'multipart/form-data; boundary=demo'], $body));
        $sdk = new Client(new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://example.test']],
            'paths' => ['/parts' => ['get' => ['operationId' => 'getParts', 'responses' => ['200' => ['description' => 'ok']]]]],
        ]), transport: $transport);

        $result = $sdk->getParts();

        self::assertSame('metadata', $result->data[0]->name);
        self::assertSame(['ok' => true], $result->data[0]->data);
        self::assertSame('photo.bin', $result->data[1]->filename);
        self::assertSame("\x00\xFFraw", $result->data[1]->body);
    }

    public function testJsonSerializableDtoUsesJsonPropertyNamesForValidationAndEncoding(): void
    {
        $transport = new FakeTransport();
        $dto = new class implements \JsonSerializable {
            public function jsonSerialize(): array { return ['display-name' => 'Ada']; }
        };
        $sdk = new Client(new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://example.test']],
            'paths' => ['/users' => ['post' => [
                'operationId' => 'createUser',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'required' => ['display-name'], 'properties' => ['display-name' => ['type' => 'string']],
                ]]]],
                'responses' => ['201' => ['description' => 'created']],
            ]]],
        ]), transport: $transport);

        $sdk->createUser(body: $dto);

        self::assertSame('{"display-name":"Ada"}', $transport->lastRequest->body);
    }

    public function testServerVariableDefaultsOverridesAndOperationServerPrecedence(): void
    {
        $transport = new FakeTransport();
        $sdk = new Client(new Document([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://{region}.example.test/{version}', 'variables' => [
                'region' => ['default' => 'api', 'enum' => ['api', 'beta']],
                'version' => ['default' => 'v1'],
            ]]],
            'paths' => [
                '/global' => ['get' => ['operationId' => 'global', 'responses' => ['200' => ['description' => 'ok']]]],
                '/operation' => ['get' => [
                    'operationId' => 'operation',
                    'servers' => [['url' => 'https://edge.example.test/{version}', 'variables' => ['version' => ['default' => 'stable']]]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
            ],
        ]), transport: $transport, allowedHosts: ['beta.example.test', 'edge.example.test'], serverVariables: ['region' => 'beta', 'version' => 'v2']);

        $sdk->global();
        self::assertSame('https://beta.example.test/v2/global', $transport->lastRequest->url);
        $sdk->operation();
        self::assertSame('https://edge.example.test/v2/operation', $transport->lastRequest->url);
    }

    public function testOperationServerMustPassHostAllowlist(): void
    {
        $transport = new FakeTransport();
        $sdk = new Client(new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://api.example.test']],
            'paths' => ['/private' => ['get' => [
                'operationId' => 'private', 'servers' => [['url' => 'https://untrusted.example.test']],
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
        ]), transport: $transport, allowedHosts: ['api.example.test']);

        $this->expectException(SdkException::class);
        $sdk->private();
    }

    public function testPaginationRejectsCrossOriginNextLink(): void
    {
        $transport = new FakeTransport(new Response(200, ['Link' => '<https://other.example.test/items?page=2>; rel="next"'], '[]'));
        $sdk = new Client(new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://example.test']],
            'paths' => ['/items' => ['get' => ['operationId' => 'listItems', 'responses' => ['200' => ['description' => 'ok']]]]],
        ]), transport: $transport);

        $this->expectException(SdkException::class);
        iterator_to_array($sdk->paginate('listItems'));
    }

    public function testRetriesTransientErrorsForIdempotentOperations(): void
    {
        $transport = new FakeTransport(responses: [
            new Response(503, ['Retry-After' => '0']),
            new Response(200, ['Content-Type' => 'application/json'], '{"id":1,"title":"Hi","body":"Text","userId":2}'),
        ]);

        $result = $this->sdk($transport, 1)->getPost(id: 1);

        self::assertSame(200, $result->status);
        self::assertSame(2, $transport->sendCount);
    }

    public function testDoesNotRetryNonIdempotentPostByDefault(): void
    {
        $transport = new FakeTransport(responses: [
            new Response(503, ['Retry-After' => '0']),
            new Response(201, ['Content-Type' => 'application/json'], '{}'),
        ]);
        try {
            $this->sdk($transport, 2)->createPost(body: ['title' => 'Hi', 'body' => 'Text', 'userId' => 1]);
            self::fail('Expected the first POST failure to be returned without retry.');
        } catch (ApiException) {
            self::assertSame(1, $transport->sendCount);
        }
    }

    public function testMiddlewareCanWrapRequests(): void
    {
        $inner = new FakeTransport();
        $middleware = new class implements HttpMiddleware {
            public bool $called = false;
            public function process(Request $request, callable $next): Response
            {
                $this->called = true;
                $headers = $request->headers;
                $headers['X-Middleware'] = 'active';
                return $next(new Request($request->method, $request->url, $headers, $request->body));
            }
        };
        $transport = new MiddlewareTransport($inner, [$middleware]);

        $transport->send(new Request('GET', 'https://example.test/'));

        self::assertTrue($middleware->called);
        self::assertSame('active', $inner->lastRequest->headers['X-Middleware']);
    }

    public function testSseParserHandlesChunkBoundariesAndMultilineData(): void
    {
        $transport = new FakeStreamingTransport([
            "id: 42\nevent: update\ndata: hello",
            "\ndata: world\n\n:keepalive\n\n",
        ], new Response(200, ['Content-Type' => 'text/event-stream; charset=utf-8']));
        $sdk = new Client(new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://example.test']],
            'paths' => ['/events' => ['get' => ['operationId' => 'events', 'responses' => ['200' => ['description' => 'ok']]]]],
        ]), transport: $transport);
        $events = [];

        $sdk->streamEvents('events', static function (array $event) use (&$events): void { $events[] = $event; });

        self::assertSame([['data' => "hello\nworld", 'event' => 'update', 'id' => '42', 'retry' => null]], $events);
        self::assertSame('text/event-stream', $transport->requests[0]->headers['Accept']);
    }

    public function testSseParserDispatchesFinalEventWithoutBlankTerminator(): void
    {
        $transport = new FakeStreamingTransport(['data: final event'], new Response(200, ['Content-Type' => 'text/event-stream']));
        $sdk = new Client(new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://example.test']],
            'paths' => ['/events' => ['get' => ['operationId' => 'events', 'responses' => ['200' => ['description' => 'ok']]]]],
        ]), transport: $transport);
        $events = [];

        $sdk->streamEvents('events', static function (array $event) use (&$events): void { $events[] = $event; });

        self::assertCount(1, $events);
        self::assertSame('final event', $events[0]['data']);
    }

    public function testConfiguredMiddlewareRunsForSseRequests(): void
    {
        $inner = new FakeStreamingTransport(['data: event\n\n'], new Response(200, ['Content-Type' => 'text/event-stream']));
        $middleware = new class implements HttpMiddleware {
            public function process(Request $request, callable $next): Response
            {
                $headers = $request->headers;
                $headers['X-Middleware'] = 'streamed';
                return $next(new Request($request->method, $request->url, $headers, $request->body));
            }
        };
        $sdk = new Client(new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://example.test']],
            'paths' => ['/events' => ['get' => ['operationId' => 'events', 'responses' => ['200' => ['description' => 'ok']]]]],
        ]), transport: $inner, middleware: [$middleware], maxRetries: 1);

        $sdk->streamEvents('events', static function (array $event): void {});

        self::assertSame('streamed', $inner->requests[0]->headers['X-Middleware']);
    }

    public function testMetricsMiddlewareReportsResponseAndDuration(): void
    {
        $inner = new FakeTransport(new Response(204));
        $recorded = [];
        $middleware = new MetricsMiddleware(static function (Request $request, ?Response $response, float $duration, ?\Throwable $error) use (&$recorded): void {
            $recorded = [$request->method, $response?->status, $duration, $error];
        });
        $transport = new MiddlewareTransport($inner, [$middleware]);

        $transport->send(new Request('GET', 'https://example.test/'));

        self::assertSame('GET', $recorded[0]);
        self::assertSame(204, $recorded[1]);
        self::assertGreaterThanOrEqual(0, $recorded[2]);
        self::assertNull($recorded[3]);
    }

    public function testDownloadWritesChunksToTargetFile(): void
    {
        $transport = new FakeStreamingTransport(["\x00\xFF", 'payload'], new Response(200, ['Content-Type' => 'application/octet-stream']));
        $sdk = new Client(new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://example.test']],
            'paths' => ['/file' => ['get' => ['operationId' => 'file', 'responses' => ['200' => ['description' => 'ok']]]]],
        ]), transport: $transport);
        $target = sys_get_temp_dir().DIRECTORY_SEPARATOR.'community-sdk-download-'.bin2hex(random_bytes(6));

        try {
            $result = $sdk->download('file', $target);
            self::assertSame(200, $result->status);
            self::assertSame("\x00\xFFpayload", file_get_contents($target));
        } finally {
            if (is_file($target)) unlink($target);
        }
    }

    public function testResumableDownloadFetchesAndVerifiesByteRanges(): void
    {
        $transport = new FakeTransport(responses: [
            new Response(206, ['Content-Range' => 'bytes 0-3/8', 'ETag' => '"v1"'], 'ABCD'),
            new Response(206, ['Content-Range' => 'bytes 4-7/8', 'ETag' => '"v1"'], 'EFGH'),
        ]);
        $sdk = new Client(new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://example.test']],
            'paths' => ['/file' => ['get' => ['operationId' => 'file', 'responses' => ['206' => ['description' => 'partial']]]]],
        ]), transport: $transport);
        $target = sys_get_temp_dir().DIRECTORY_SEPARATOR.'community-sdk-resume-'.bin2hex(random_bytes(6));

        try {
            $sdk->downloadResumable('file', $target, rangeSize: 4);
            self::assertSame('ABCDEFGH', file_get_contents($target));
            self::assertSame('bytes=0-3', $transport->requests[0]->headers['Range']);
            self::assertSame('bytes=4-7', $transport->requests[1]->headers['Range']);
            self::assertSame('"v1"', $transport->requests[1]->headers['If-Range']);
        } finally {
            foreach ([$target, $target.'.part', $target.'.part.json'] as $path) if (is_file($path)) unlink($path);
        }
    }

    public function testResumableDownloadContinuesAfterRetryableApiError(): void
    {
        $transport = new FakeTransport(responses: [
            new Response(206, ['Content-Range' => 'bytes 0-3/8', 'ETag' => '"v1"'], 'ABCD'),
            new Response(503, ['Retry-After' => '0']),
        ]);
        $sdk = new Client(new Document([
            'openapi' => '3.1.0', 'servers' => [['url' => 'https://example.test']],
            'paths' => ['/file' => ['get' => ['operationId' => 'file', 'responses' => ['206' => ['description' => 'partial'], '503' => ['description' => 'retry']]]]],
        ]), transport: $transport);
        $target = sys_get_temp_dir().DIRECTORY_SEPARATOR.'community-sdk-resume-'.bin2hex(random_bytes(6));
        try {
            try {
                $sdk->downloadResumable('file', $target, rangeSize: 4);
                self::fail('Expected an API failure for the second range.');
            } catch (ApiException) {
                self::assertSame('ABCD', file_get_contents($target.'.part'));
            }
            $transport = new FakeTransport(responses: [new Response(206, ['Content-Range' => 'bytes 4-7/8', 'ETag' => '"v1"'], 'EFGH')]);
            $sdk = new Client(new Document([
                'openapi' => '3.1.0', 'servers' => [['url' => 'https://example.test']],
                'paths' => ['/file' => ['get' => ['operationId' => 'file', 'responses' => ['206' => ['description' => 'partial']]]]],
            ]), transport: $transport);
            $sdk->downloadResumable('file', $target, rangeSize: 4);
            self::assertSame('bytes=4-7', $transport->lastRequest->headers['Range']);
            self::assertSame('ABCDEFGH', file_get_contents($target));
        } finally {
            foreach ([$target, $target.'.part', $target.'.part.json'] as $path) if (is_file($path)) unlink($path);
        }
    }

    public function testNamedArgumentsAndPathEncoding(): void
    {
        $transport = new FakeTransport(new Response(200, ['Content-Type'=>'application/json'], '{"id":1,"title":"Hi","body":"Text","userId":2}'));
        $result = $this->sdk($transport)->getPost(id: 1);
        self::assertSame(1, $result->data['id']);
        self::assertSame('https://jsonplaceholder.typicode.com/posts/1', $transport->lastRequest->url);
    }

    public function testRequestBodyAndRequiredFields(): void
    {
        $transport = new FakeTransport(new Response(201, ['Content-Type'=>'application/json'], '{"id":101,"title":"Hi","body":"Text","userId":2}'));
        $sdk = $this->sdk($transport);
        $result = $sdk->createPost(body: ['title'=>'Hi','body'=>'Text','userId'=>2]);
        self::assertSame(201, $result->status);
        self::assertSame('POST', $transport->lastRequest->method);
        self::assertStringContainsString('"title":"Hi"', $transport->lastRequest->body);
        $this->expectException(ValidationException::class);
        $sdk->createPost(body: ['title'=>'Hi']);
    }

    public function testQueryEncodingAndOperationSearch(): void
    {
        $transport = new FakeTransport(new Response(200, ['Content-Type'=>'application/json'], '[]'));
        $sdk = $this->sdk($transport);
        $sdk->getPosts(userId: 3);
        self::assertStringEndsWith('/posts?userId=3', $transport->lastRequest->url);
        self::assertArrayHasKey('getPost', $sdk->operations()->all(method: 'GET', search: 'post'));
    }

    public function testUnknownAndInvalidArguments(): void
    {
        $sdk = $this->sdk(new FakeTransport());
        try {
            $sdk->getPost(id: 'wrong');
            self::fail('Expected validation failure');
        } catch (ValidationException $e) {
            self::assertNotEmpty($e->errors);
        }
        $this->expectException(ValidationException::class);
        $sdk->getPosts(random: true);
    }

    public function testApiErrorPreservesPayload(): void
    {
        $transport = new FakeTransport(new Response(404, ['Content-Type'=>'application/json'], '{"message":"Missing"}'));
        try {
            $this->sdk($transport)->getPost(id: 99);
            self::fail('Expected API exception');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
            self::assertSame('Missing', $e->getMessage());
            self::assertSame(['message'=>'Missing'], $e->data);
        }
    }

    public function testResponseValidation(): void
    {
        $transport = new FakeTransport(new Response(200, ['Content-Type'=>'application/json'], '{"id":"bad","title":"Hi","body":"T","userId":2}'));
        $this->expectException(ValidationException::class);
        $this->sdk($transport)->getPost(id: 1);
    }

    public function testUnknownOperation(): void
    {
        $this->expectException(SdkException::class);
        $this->sdk(new FakeTransport())->doesNotExist();
    }
}
