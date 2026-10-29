<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\Developer\IdeStubGenerator;
use CommunitySDKs\OpenApi\Document;
use PHPUnit\Framework\TestCase;

final class IdeStubGeneratorTest extends TestCase
{
    public function testGeneratesNullableArgumentsAndNestedResultShapes(): void
    {
        $document = new Document([
            'openapi' => '3.1.0', 'paths' => [
                '/pets' => ['get' => [
                    'operationId' => 'findPets',
                    'parameters' => [
                        ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ['name' => 'filter', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'nullable' => true]],
                    ],
                    'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                        'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id'], 'properties' => [
                            'id' => ['type' => 'integer'], 'display-name' => ['type' => 'string'],
                        ]]],
                    ]]]],
                ]],
            ],
        ]);

        $stub = (new IdeStubGenerator($document))->generate();

        self::assertStringContainsString('findPets(?string $filter, ?int $limit = null)', $stub);
        self::assertStringContainsString("list<array{id: int, 'display-name'?: string}>", $stub);
    }

    public function testGeneratesMixedFallbackForRecursiveModelsAndInvalidOperationNames(): void
    {
        $document = new Document([
            'openapi' => '3.1.0',
            'paths' => [
                '/nodes' => ['get' => [
                    'operationId' => 'getNodes',
                    'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/Node'],
                    ]]]],
                ]],
                '/bad' => ['get' => ['operationId' => 'not-valid', 'responses' => ['204' => ['description' => 'ok']]]],
            ],
            'components' => ['schemas' => ['Node' => ['type' => 'object', 'properties' => [
                'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Node']],
            ]]]],
        ]);

        $stub = (new IdeStubGenerator($document))->generate();

        self::assertStringContainsString('array{children?: list<array<string, mixed>>}', $stub);
        self::assertStringNotContainsString(' not-valid(', $stub);
        self::assertStringContainsString('@method \\CommunitySDKs\\Http\\Result<mixed> call(', $stub);
    }
}