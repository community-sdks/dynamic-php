<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\Developer\IdeStubGenerator;
use CommunitySDKs\Developer\DtoGenerator;
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
        self::assertStringNotContainsString('): \\CommunitySDKs\\Http\\Result<', $stub);
    }

    public function testRequiredBodyPrecedesOptionalParameters(): void
    {
        $document = new Document([
            'openapi' => '3.1.0', 'paths' => ['/items' => ['post' => [
                'operationId' => 'createItem',
                'parameters' => [['name' => 'locale', 'in' => 'query', 'schema' => ['type' => 'string']]],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                'responses' => ['201' => ['description' => 'created']],
            ]]],
        ]);

        $stub = (new IdeStubGenerator($document))->generate();

        self::assertStringContainsString('createItem(array<string, mixed> $body, ?string $locale = null)', $stub);
    }

    public function testDtoGeneratorPreservesJsonNamesAndUsesStrictProperties(): void
    {
        $document = new Document([
            'openapi' => '3.1.0', 'paths' => [],
            'components' => ['schemas' => ['User-Profile' => [
                'type' => 'object', 'required' => ['id'],
                'properties' => ['id' => ['type' => 'integer'], 'display-name' => ['type' => 'string']],
            ]]],
        ]);

        $dto = (new DtoGenerator($document))->generate();

        self::assertStringContainsString('final readonly class UserProfile implements \\JsonSerializable', $dto);
        self::assertStringContainsString('public int $id', $dto);
        self::assertStringContainsString('public ?string $display_name = null', $dto);
        self::assertStringContainsString("\$data['display-name'] = \$this->display_name;", $dto);
        self::assertStringContainsString("if (\$this->display_name !== null) \$data['display-name'] = \$this->display_name;", $dto);
    }

    public function testDtoReferencesGenerateModelTypesIncludingRecursiveCollections(): void
    {
        $document = new Document([
            'openapi' => '3.1.0', 'paths' => [],
            'components' => ['schemas' => [
                'Child' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
                'Parent' => ['type' => 'object', 'required' => ['child'], 'properties' => [
                    'child' => ['$ref' => '#/components/schemas/Child'],
                    'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Child']],
                ]],
                'Node' => ['type' => 'object', 'properties' => [
                    'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Node']],
                ]],
            ]],
        ]);

        $dto = (new DtoGenerator($document))->generate();

        self::assertStringContainsString('public Child $child', $dto);
        self::assertStringContainsString('@param list<Child>|null $children', $dto);
        self::assertStringContainsString('@param list<Node>|null $children', $dto);
    }

    public function testDtoGeneratorPreservesOneOfModelUnions(): void
    {
        $document = new Document([
            'openapi' => '3.1.0', 'paths' => [],
            'components' => ['schemas' => [
                'Cat' => ['type' => 'object', 'properties' => ['meows' => ['type' => 'boolean']]],
                'Dog' => ['type' => 'object', 'properties' => ['barks' => ['type' => 'boolean']]],
                'Owner' => ['type' => 'object', 'properties' => ['pet' => ['oneOf' => [
                    ['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog'],
                ]]]],
            ]],
        ]);

        $dto = (new DtoGenerator($document))->generate();

        self::assertStringContainsString('public Cat|Dog|null $pet = null', $dto);
        self::assertStringContainsString('@param Cat|Dog|null $pet', $dto);
    }

    public function testDtoGeneratorMergesAllOfPropertiesAndRequiredFields(): void
    {
        $document = new Document([
            'openapi' => '3.1.0', 'paths' => [],
            'components' => ['schemas' => [
                'Base' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
                'Record' => ['allOf' => [
                    ['$ref' => '#/components/schemas/Base'],
                    ['type' => 'object', 'required' => ['label'], 'properties' => ['label' => ['type' => 'string']]],
                ]],
            ]],
        ]);

        $dto = (new DtoGenerator($document))->generate();

        self::assertStringContainsString('final readonly class Record', $dto);
        self::assertStringContainsString('public int $id,', $dto);
        self::assertStringContainsString('public string $label', $dto);
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