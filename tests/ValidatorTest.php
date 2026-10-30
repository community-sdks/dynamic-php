<?php

declare(strict_types=1);
namespace CommunitySDKs\Tests;

use CommunitySDKs\Exception\{SdkException, ValidationException};
use CommunitySDKs\OpenApi\{Document, RemoteReferenceLoader, Validator};
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

    public function testTrustedSiblingFileReferencesAndRecursivePointers(): void
    {
        $document = Document::fromFile(__DIR__.'/fixtures/external-ref-api.json');
        $validator = new Validator($document);
        $schema = ['$ref' => 'schemas/recursive.json#/components/schemas/Envelope'];

        $validator->assert(['node' => ['value' => 'root', 'children' => [['value' => 'child']]]], $schema);
        $this->expectException(ValidationException::class);
        $validator->assert(['node' => ['value' => 42]], $schema);
    }

    public function testLocalReferenceCannotEscapeSpecificationDirectory(): void
    {
        $validator = new Validator(Document::fromFile(__DIR__.'/fixtures/external-ref-api.json'));

        $this->expectException(SdkException::class);
        $validator->assert(['name' => 'x'], ['$ref' => '../../composer.json']);
    }

    public function testAllowlistedRemoteReferenceLoadsRecursivelyWithinAllowedHosts(): void
    {
        $loader = new class implements RemoteReferenceLoader {
            /** @var list<string> */
            public array $urls = [];
            public function load(string $url, int $maxBytes): string
            {
                $this->urls[] = $url;
                return match ($url) {
                    'https://schemas.example.test/root.json' => '{"components":{"schemas":{"Envelope":{"type":"object","required":["node"],"properties":{"node":{"$ref":"https://schemas.example.test/node.json#/Node"}}}}}}',
                    'https://schemas.example.test/node.json' => '{"Node":{"type":"object","required":["id"],"properties":{"id":{"type":"integer"},"next":{"$ref":"#/Node"}}}}',
                    default => throw new SdkException('Unexpected test reference URL.'),
                };
            }
        };
        $document = Document::fromFile(__DIR__.'/fixtures/external-ref-api.json',
            allowedRemoteRefHosts: ['schemas.example.test'], remoteReferenceLoader: $loader);

        (new Validator($document))->assert(
            ['node' => ['id' => 1, 'next' => ['id' => 2]]],
            ['$ref' => 'https://schemas.example.test/root.json#/components/schemas/Envelope'],
        );

        self::assertSame(['https://schemas.example.test/root.json', 'https://schemas.example.test/node.json'], $loader->urls);
    }

    public function testRemoteReferenceRequiresAllowlistedHttpsHost(): void
    {
        $loader = new class implements RemoteReferenceLoader {
            public function load(string $url, int $maxBytes): string { throw new SdkException('Loader must not be called.'); }
        };
        $document = Document::fromFile(__DIR__.'/fixtures/external-ref-api.json',
            allowedRemoteRefHosts: ['schemas.example.test'], remoteReferenceLoader: $loader);

        $this->expectException(SdkException::class);
        (new Validator($document))->assert(['id' => 1], ['$ref' => 'https://evil.example.test/schema.json']);
    }

    public function testRemoteReferenceBodySizeIsEnforcedEvenForCustomLoaders(): void
    {
        $loader = new class implements RemoteReferenceLoader {
            public function load(string $url, int $maxBytes): string { return str_repeat(' ', $maxBytes + 1); }
        };
        $document = Document::fromFile(__DIR__.'/fixtures/external-ref-api.json',
            allowedRemoteRefHosts: ['schemas.example.test'], remoteReferenceLoader: $loader, maxRemoteReferenceBytes: 32);

        $this->expectException(SdkException::class);
        (new Validator($document))->assert(['id' => 1], ['$ref' => 'https://schemas.example.test/schema.json']);
    }
}