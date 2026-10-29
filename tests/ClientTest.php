<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\Client;
use CommunitySDKs\Exception\{ApiException, SdkException, ValidationException};
use CommunitySDKs\Http\Response;
use CommunitySDKs\OpenApi\Document;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function sdk(FakeTransport $transport): Client
    {
        return Client::fromFile(dirname(__DIR__).'/specs/jsonplaceholder.openapi.json', transport: $transport);
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
