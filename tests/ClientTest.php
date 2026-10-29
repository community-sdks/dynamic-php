<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\Client;
use CommunitySDKs\Exception\{ApiException, SdkException, ValidationException};
use CommunitySDKs\Http\Response;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function sdk(FakeTransport $transport): Client
    {
        return Client::fromFile(dirname(__DIR__).'/specs/jsonplaceholder.openapi.json', transport: $transport);
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
