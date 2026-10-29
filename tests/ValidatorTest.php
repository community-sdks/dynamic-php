<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\Exception\{SdkException, ValidationException};
use CommunitySDKs\OpenApi\{Document, Validator};
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    /** @param array<string,mixed> $schemas */
    private function validator(array $schemas = []): Validator
    {
        return new Validator(new Document([
            'openapi' => '3.1.0',
            'paths' => [],
            'components' => ['schemas' => $schemas],
        ]));
    }

    public function testUnevaluatedPropertiesAcrossAllOf(): void
    {
        $validator = $this->validator();
        $schema = [
            'type' => 'object',
            'allOf' => [
                ['properties' => ['name' => ['type' => 'string']]],
                ['properties' => ['age' => ['type' => 'integer']]],
            ],
            'unevaluatedProperties' => false,
        ];

        $validator->assert(['name' => 'Ada', 'age' => 37], $schema);
        $this->expectException(ValidationException::class);
        $validator->assert(['name' => 'Ada', 'age' => 37, 'extra' => true], $schema);
    }

    public function testDependentSchemasAndNot(): void
    {
        $validator = $this->validator();
        $schema = [
            'type' => 'object',
            'properties' => ['card' => ['type' => 'string']],
            'dependentSchemas' => ['card' => ['required' => ['billingAddress']]],
            'not' => ['properties' => ['card' => ['const' => 'blocked']]],
        ];

        $validator->assert(['card' => 'active', 'billingAddress' => '10 Main Street'], $schema);
        $this->expectException(ValidationException::class);
        $validator->assert(['card' => 'active'], $schema);
    }

    public function testRecursiveComponentReferenceAndFormats(): void
    {
        $validator = $this->validator([
            'Node' => [
                'type' => 'object',
                'properties' => [
                    'value' => ['type' => 'integer'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'next' => ['anyOf' => [
                        ['$ref' => '#/components/schemas/Node'],
                        ['type' => 'null'],
                    ]],
                ],
                'required' => ['value', 'email'],
            ],
        ]);

        $validator->assert(['value' => 1, 'email' => 'a@example.test', 'next' => ['value' => 2, 'email' => 'b@example.test', 'next' => null]], ['$ref' => '#/components/schemas/Node']);
        $this->expectException(ValidationException::class);
        $validator->assert(['value' => 1, 'email' => 'invalid'], ['$ref' => '#/components/schemas/Node']);
    }

    public function testOpenApiDiscriminatorMappingSelectsVariant(): void
    {
        $validator = $this->validator([
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'meows' => ['type' => 'boolean']], 'required' => ['kind', 'meows']],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'barks' => ['type' => 'boolean']], 'required' => ['kind', 'barks']],
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['kitty' => '#/components/schemas/Cat']],
            ],
        ]);

        $validator->assert(['kind' => 'kitty', 'meows' => true], ['$ref' => '#/components/schemas/Pet']);
        $this->expectException(ValidationException::class);
        $validator->assert(['kind' => 'kitty', 'barks' => true], ['$ref' => '#/components/schemas/Pet']);
    }

    public function testExternalReferencesAreRejected(): void
    {
        $this->expectException(SdkException::class);
        $this->validator()->assert(['value' => 1], ['$ref' => 'https://schemas.example.test/value.json']);
    }
}