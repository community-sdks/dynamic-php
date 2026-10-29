<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\OpenApi\ParameterSerializer;
use PHPUnit\Framework\TestCase;

final class ParameterSerializerFixtureTest extends TestCase
{
    public function testPortableParameterSerializationFixtures(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/fixtures/parameter-serialization.json'), true, 512, JSON_THROW_ON_ERROR);
        $serializer = new ParameterSerializer();
        foreach ($fixture['cases'] as $case) {
            $actual = match ($case['location']) {
                'path' => $serializer->path($case['parameter'], $case['value']),
                'query' => $serializer->query($case['parameter'], $case['value']),
                'cookie' => $serializer->cookie($case['parameter'], $case['value']),
                'header' => $serializer->header($case['parameter'], $case['value']),
            };
            self::assertSame($case['expected'], $actual, $case['location'].' parameter fixture');
        }
    }
}