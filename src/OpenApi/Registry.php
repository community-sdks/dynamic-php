<?php

declare(strict_types=1);
namespace CommunitySDKs\OpenApi;

use CommunitySDKs\Exception\SdkException;

final readonly class Registry
{
    /** @var array<string,array<string,mixed>> */
    private array $operations;
    /** @var array<string,?string> */
    private array $operationSources;
    /** @var array<string,array<string,?string>> */
    private array $parameterSources;

    public function __construct(private Document $document)
    {
        $operations = [];
        $operationSources = [];
        $parameterSources = [];
        foreach ($document->data['paths'] as $path => $pathItem) {
            $resolvedPathItem = $document->resolveWithSource($pathItem);
            $pathItem = $resolvedPathItem['value'];
            foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'] as $verb) {
                if (!isset($pathItem[$verb])) {
                    continue;
                }
                $resolvedOperation = $document->resolveWithSource($pathItem[$verb], $resolvedPathItem['sourcePath']);
                $operation = $resolvedOperation['value'];
                $operationSource = $resolvedOperation['sourcePath'];
                $id = $operation['operationId'] ?? $this->fallbackName($verb, $path);
                if (isset($operations[$id])) {
                    throw new SdkException("Duplicate operationId: {$id}");
                }
                $parameters = [];
                $sources = [];
                foreach ([[$pathItem['parameters'] ?? [], $resolvedPathItem['sourcePath']], [$operation['parameters'] ?? [], $operationSource]] as [$parameterList, $parameterSource]) {
                    foreach ($parameterList as $parameter) {
                        $resolvedParameter = $document->resolveWithSource($parameter, $parameterSource);
                        $parameter = $resolvedParameter['value'];
                        $key = $parameter['in'].':'.$parameter['name'];
                        $parameters[$key] = $parameter;
                        $sources[$key] = $resolvedParameter['sourcePath'];
                    }
                }
                $operations[$id] = $operation + [
                    'operationId' => $id,
                ];
                $operations[$id]['_method'] = strtoupper($verb);
                $operations[$id]['_path'] = $path;
                $operations[$id]['_parameters'] = array_values($parameters);
                $operations[$id]['_servers'] = $operation['servers'] ?? $pathItem['servers'] ?? $document->data['servers'] ?? [];
                $operationSources[$id] = $operationSource;
                $parameterSources[$id] = $sources;
            }
        }
        $this->operations = $operations;
        $this->operationSources = $operationSources;
        $this->parameterSources = $parameterSources;
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

    public function sourcePath(string $operationId): ?string
    {
        $this->get($operationId);
        return $this->operationSources[$operationId] ?? null;
    }

    public function parameterSourcePath(string $operationId, string $place, string $name): ?string
    {
        $this->get($operationId);
        return $this->parameterSources[$operationId][$place.':'.$name] ?? null;
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
