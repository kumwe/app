<?php

declare(strict_types=1);

namespace Kumwe\App\OpenApi\Infrastructure;

use InvalidArgumentException;

/**
 * Builds the retained semantic digest fixture for one public REST contract generation.
 *
 * The fixture names every operation by method and path and fingerprints its complete declaration, then
 * fingerprints every reusable schema and the independent problem registry. A compiler refactor that preserves
 * semantics reproduces the fixture, while any wire change requires an explicit new accepted generation.
 *
 * @since  2.0.0
 */
final class RestMachineContractSnapshot
{
    /**
     * Capture one OpenAPI and problem-registry generation as stable semantic digests.
     *
     * @param   array<string, mixed>  $openApi          Compiled OpenAPI document.
     * @param   array<string, mixed>  $problemRegistry  Standalone problem-details registry document.
     * @param   string                $generation       Semantic generation label.
     *
     * @return  array{
     *              format: string,
     *              generation: string,
     *              openapi_semantic_sha256: string,
     *              problem_registry_sha256: string,
     *              operations: array<string, array{method: string, path: string, contract_sha256: string}>,
     *              schemas: array<string, string>
     *          }  Retained compatibility snapshot.
     *
     * @throws  InvalidArgumentException  When a document, generation, operation or schema registry is malformed.
     *
     * @since   2.0.0
     */
    public static function create(array $openApi, array $problemRegistry, string $generation = '1.0.0'): array
    {
        if (
            preg_match('/^[1-9][0-9]*\.[0-9]+\.[0-9]+$/D', $generation) !== 1
            || ($openApi['openapi'] ?? null) !== '3.1.0'
            || ($problemRegistry['format'] ?? null) !== 'kumwe-problem-details-registry-v1'
        ) {
            throw new InvalidArgumentException('The REST machine-contract generation is invalid.');
        }
        $paths = self::object($openApi['paths'] ?? null, 'The OpenAPI path registry is invalid.');
        $components = self::object($openApi['components'] ?? null, 'The OpenAPI component registry is invalid.');
        $schemas = self::object($components['schemas'] ?? null, 'The OpenAPI schema registry is invalid.');
        $operationSnapshots = [];
        foreach ($paths as $path => $pathItem) {
            if (!is_array($pathItem) || array_is_list($pathItem) || !str_starts_with($path, '/')) {
                throw new InvalidArgumentException('An OpenAPI path item is invalid.');
            }
            foreach (['get', 'put', 'post', 'patch', 'delete', 'head', 'options', 'trace'] as $method) {
                $operation = $pathItem[$method] ?? null;
                if ($operation === null) {
                    continue;
                }
                if (!is_array($operation) || array_is_list($operation)) {
                    throw new InvalidArgumentException('An OpenAPI operation is invalid.');
                }
                /** @var array<string, mixed> $operation */
                $operationId = $operation['operationId'] ?? null;
                if (!is_string($operationId) || $operationId === '' || isset($operationSnapshots[$operationId])) {
                    throw new InvalidArgumentException('An OpenAPI operation identifier is invalid or duplicated.');
                }
                $operationSnapshots[$operationId] = [
                    'contract_sha256' => hash('sha256', CanonicalOpenApiJson::encode($operation)),
                    'method' => strtoupper($method),
                    'path' => $path,
                ];
            }
        }
        ksort($operationSnapshots, SORT_STRING);
        $schemaSnapshots = [];
        foreach ($schemas as $name => $schema) {
            if (!is_array($schema) || array_is_list($schema) || $name === '') {
                throw new InvalidArgumentException('An OpenAPI component schema is invalid.');
            }
            /** @var array<string, mixed> $schema */
            $schemaSnapshots[$name] = hash('sha256', CanonicalOpenApiJson::encode($schema));
        }
        ksort($schemaSnapshots, SORT_STRING);

        return [
            'format' => 'kumwe-rest-machine-contract-snapshot-v1',
            'generation' => $generation,
            'openapi_semantic_sha256' => hash('sha256', CanonicalOpenApiJson::encode($openApi)),
            'operations' => $operationSnapshots,
            'problem_registry_sha256' => hash(
                'sha256',
                CanonicalOpenApiJson::encode(self::normalizeProblemRegistry($problemRegistry)),
            ),
            'schemas' => $schemaSnapshots,
        ];
    }

    /**
     * Preserve empty extension maps as JSON objects after associative decoding.
     *
     * PHP represents both an empty JSON object and array as `[]` after associative decoding. The registry
     * contract declares `extensions` as an object, so normalizing that one known member makes a retained digest
     * reproducible from either the runtime registry or its checked-in JSON bytes without blurring other arrays.
     *
     * @param   array<string, mixed>  $registry  Runtime or associatively decoded problem registry.
     *
     * @return  array<string, mixed>  Registry with empty extension maps restored to JSON objects.
     *
     * @since   2.0.0
     */
    private static function normalizeProblemRegistry(array $registry): array
    {
        $problems = $registry['problems'] ?? null;
        if (!is_array($problems) || !array_is_list($problems)) {
            return $registry;
        }
        foreach ($problems as $index => $problem) {
            if (!is_array($problem) || array_is_list($problem) || ($problem['extensions'] ?? null) !== []) {
                continue;
            }
            $problem['extensions'] = new \stdClass();
            $problems[$index] = $problem;
        }
        $registry['problems'] = $problems;

        return $registry;
    }

    /**
     * Narrow a decoded JSON member to the object representation used by contract registries.
     *
     * @param   mixed   $value    Candidate value.
     * @param   string  $message  Stable validation failure detail.
     *
     * @return  array<string, mixed>  Valid string-keyed JSON object.
     *
     * @throws  InvalidArgumentException  When the value is not an object.
     *
     * @since   2.0.0
     */
    private static function object(mixed $value, string $message): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException($message);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Prevent construction; snapshot creation is stateless.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
