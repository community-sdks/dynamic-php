<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\Exception\TransportException;
use CommunitySDKs\Http\{Request, StreamTransport};
use PHPUnit\Framework\TestCase;

final class HttpTransportTest extends TestCase
{
    public function testRejectsHeaderInjectionBeforeOpeningNetworkConnection(): void
    {
        $this->expectException(TransportException::class);
        (new StreamTransport())->send(new Request('GET', 'https://example.test/', ['X-Test' => "safe\r\nInjected: yes"]));
    }

    public function testRejectsInvalidHeaderNamesBeforeOpeningNetworkConnection(): void
    {
        $this->expectException(TransportException::class);
        (new StreamTransport())->stream(new Request('GET', 'https://example.test/', ["X-Test\r\nInjected" => 'value']), static function (string $chunk): void {});
    }
}