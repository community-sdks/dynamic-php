<?php

declare(strict_types=1);
namespace CommunitySDKs\OpenApi;

use CommunitySDKs\Exception\SdkException;

final readonly class Registry
{
    /** @var array<string,array<string,mixed>> */
    private array $operations;

    public function __construct(private Document $document)
    {
        $operations = [];
        foreach ($document->data['paths'] as $path => $pathItem) {
            $pathItem = $document->resolve($pathItem);
            foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'] as $verb) {
                if (!isset($pathItem[$verb])) {
                    continue;
                }
                $operation = $pathItem[$verb];
                $id = $operation['operationId'] ?? $this->fallbackName($verb, $path);
                if (isset($operations[$id])) {
                    throw new SdkException("Duplicate operationId: {$id}");
                }
                $parameters = [];
                foreach (array_merge($pathItem['parameters'] ?? [], $operation['parameters'] ?? []) as $parameter) {
                    $parameter = $document->resolve($parameter);
                    $parameters[$parameter['in'].':'.$parameter['name']] = $parameter;
                }
                $operations[$id] = $operation + [
                    'operationId' => $id,
                ];
                $operations[$id]['_method'] = strtoupper($verb);
                $operations[$id]['_path'] = $path;
                $operations[$id]['_parameters'] = array_values($parameters);
            }
        }
        $this->operations = $operations;
    }

    private function fallbackName(string $verb, string $path): string
    {
        $words = preg_split('/[^a-zA-Z0-9]+/', trim($path, '/')) ?: [];
        return $verb.implode('', array_map('ucfirst', array_filter($words)));
    }

    /** @return array<string,mixed> */
    public function get(string $operationId): array
    {
        return $this->operations[$operationId] ?? throw new SdkException("Unknown operation: {$operationId}");
    }

    /** @return array<string,array<string,mixed>> */
    public function all(?string $method = null, ?string $search = null): array
    {
        return array_filter($this->operations, static fn (array $op) =>
            ($method === null || $op['_method'] === strtoupper($method))
            && ($search === null || stripos($op['operationId'].' '.$op['_path'].' '.($op['summary'] ?? ''), $search) !== false)
        );
    }
}
