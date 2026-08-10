<?php

declare(strict_types=1);

namespace Kumwe\CMS\OpenApi\Application;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessSchema;
use Kumwe\CMS\OpenApi\Infrastructure\CanonicalOpenApiJson;

/**
 * Deterministically assembles the OpenAPI 3.1 contract for generated business resources.
 *
 * The compiler receives the checked-in core contract and policy-filtered metadata produced by the same
 * catalog as every other adapter. It rejects path, component and operation-identifier collisions before
 * emitting bytes, validates every local reference, and builds separate read/create/update schemas so
 * server-only, computed, read-only, write-only and denied fields cannot drift between runtime and contract.
 * No extension code executes here; contributed definitions have already crossed trusted runtime and
 * publication validation before they become catalog documents.
 *
 * @since  2.0.0
 */
final readonly class OpenApiContractCompiler
{
    /**
     * Compile and verify one generated business contract.
     *
     * @param   array<string, mixed>        $core         Validated checked-in core OpenAPI document.
     * @param   list<array<string, mixed>>  $definitions  Safe merged metadata for the authenticated context.
     * @param   string                      $generation   Runtime/definition/policy generation digest.
     *
     * @return  CompiledOpenApiContract  Canonical bytes and checksums.
     *
     * @throws  InvalidArgumentException  On invalid OpenAPI shape, collision, unsafe schema, or broken reference.
     *
     * @since   2.0.0
     */
    public function compile(array $core, array $definitions, string $generation): CompiledOpenApiContract
    {
        if (($core['openapi'] ?? null) !== '3.1.0') {
            throw new InvalidArgumentException('The core contract must use OpenAPI 3.1.0.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $generation) !== 1) {
            throw new InvalidArgumentException('The OpenAPI generation digest is invalid.');
        }
        $this->validateDefinitionInput($definitions);
        $core = $this->stripPriorGeneratedContract($core);
        $paths = $this->objectArray(
            $core['paths'] ?? null,
            'The core OpenAPI paths or components are invalid.',
        );
        $components = $this->objectArray(
            $core['components'] ?? null,
            'The core OpenAPI paths or components are invalid.',
        );
        $schemas = $this->objectArray(
            $components['schemas'] ?? null,
            'The core OpenAPI schema registry is invalid.',
        );

        $generatedSchemas = [];
        $readReferences = [];
        $createReferences = [];
        $updateReferences = [];
        $customViewQueryReferences = [];
        $customViewResultReferences = [];
        $customActionCommandReferences = [];
        $customActionResultReferences = [];
        foreach ($definitions as $definition) {
            $name = $this->componentName($definition);
            foreach (['Record', 'Create', 'Update'] as $suffix) {
                $component = $name . $suffix;
                if (isset($schemas[$component]) || isset($generatedSchemas[$component])) {
                    throw new InvalidArgumentException('A generated OpenAPI component name collides.');
                }
            }
            $generatedSchemas[$name . 'Record'] = $this->entitySchema($definition, 'detail');
            $generatedSchemas[$name . 'Create'] = $this->entitySchema($definition, 'create');
            $generatedSchemas[$name . 'Update'] = $this->entitySchema($definition, 'update');
            $readReferences[] = ['$ref' => '#/components/schemas/' . $name . 'Record'];
            $createReferences[] = ['$ref' => '#/components/schemas/' . $name . 'Create'];
            $updateReferences[] = ['$ref' => '#/components/schemas/' . $name . 'Update'];
            foreach ($this->customContracts($definition, 'views', 'query_schema') as $contract) {
                $query = $name . 'View_' . $contract['handle'] . '_Query';
                $result = $name . 'View_' . $contract['handle'] . '_Result';
                $this->addSchema($schemas, $generatedSchemas, $query, $contract['input']);
                $this->addSchema($schemas, $generatedSchemas, $result, $contract['result']);
                $customViewQueryReferences[] = ['$ref' => '#/components/schemas/' . $query];
                $customViewResultReferences[] = ['$ref' => '#/components/schemas/' . $result];
            }
            foreach ($this->customContracts($definition, 'actions', 'command_schema') as $contract) {
                $command = $name . 'Action_' . $contract['handle'] . '_Command';
                $result = $name . 'Action_' . $contract['handle'] . '_Result';
                $this->addSchema($schemas, $generatedSchemas, $command, $contract['input']);
                $this->addSchema($schemas, $generatedSchemas, $result, $contract['result']);
                $customActionCommandReferences[] = ['$ref' => '#/components/schemas/' . $command];
                $customActionResultReferences[] = ['$ref' => '#/components/schemas/' . $result];
            }
        }
        ksort($generatedSchemas, SORT_STRING);
        $commonSchemas = $this->commonSchemas(
            $schemas,
            $readReferences,
            $createReferences,
            $updateReferences,
            $customViewQueryReferences,
            $customViewResultReferences,
            $customActionCommandReferences,
            $customActionResultReferences,
        );
        $generatedComponentNames = array_keys(array_merge($commonSchemas, $generatedSchemas));
        sort($generatedComponentNames, SORT_STRING);
        $schemas = array_merge($schemas, $commonSchemas, $generatedSchemas);
        ksort($schemas, SORT_STRING);
        $components['schemas'] = $schemas;
        $core['components'] = $components;

        $generatedPaths = $this->paths();
        foreach ($generatedPaths as $path => $pathItem) {
            if (isset($paths[$path])) {
                throw new InvalidArgumentException('A generated business path collides with a core route.');
            }
            $paths[$path] = $pathItem;
        }
        ksort($paths, SORT_STRING);
        $core['paths'] = $paths;
        $core['x-kumwe-business-generation'] = $generation;
        $core['x-kumwe-generated-components'] = $generatedComponentNames;
        $generatedPathNames = array_keys($generatedPaths);
        sort($generatedPathNames, SORT_STRING);
        $core['x-kumwe-generated-paths'] = $generatedPathNames;
        $this->validateOperations($paths);
        $this->validateReferences($core);

        $json = CanonicalOpenApiJson::encode($core);
        if (strlen($json) > OpenApiContractLimits::MAX_CONTRACT_BYTES) {
            throw new InvalidArgumentException('The generated OpenAPI contract exceeds its safe byte bound.');
        }

        return new CompiledOpenApiContract($generation, hash('sha256', $json), $json);
    }

    /**
     * Reject malformed or oversized caller-visible metadata before schema expansion.
     *
     * @param   array<mixed>  $definitions  Candidate policy-filtered definition documents.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the collection or its encoded representation is unsafe.
     *
     * @since   2.0.0
     */
    private function validateDefinitionInput(array $definitions): void
    {
        if (!array_is_list($definitions) || count($definitions) > OpenApiContractLimits::MAX_DEFINITIONS) {
            throw new InvalidArgumentException('Generated OpenAPI definitions are invalid or unbounded.');
        }
        foreach ($definitions as $definition) {
            if (!is_array($definition) || array_is_list($definition)) {
                throw new InvalidArgumentException('Generated OpenAPI definition metadata is invalid.');
            }
        }
        try {
            $encoded = json_encode($definitions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Generated OpenAPI definition metadata is not encodable.',
                0,
                $exception,
            );
        }
        if (strlen($encoded) > OpenApiContractLimits::MAX_DEFINITION_INPUT_BYTES) {
            throw new InvalidArgumentException('Generated OpenAPI definition metadata exceeds its safe byte bound.');
        }
    }

    /**
     * Recover the immutable core portion when recompiling the checked-in generated golden artifact.
     *
     * A generated artifact records every component and path it added. Only a document carrying valid
     * generation/component markers is stripped; an unmarked core document that occupies a reserved member
     * still fails as a collision. Artifacts created before the path marker existed are migrated by matching
     * the compiler-owned operation IDs they already contain. This keeps the build command idempotent across
     * route-family upgrades without treating an unmarked core path as generated.
     *
     * @param   array<string, mixed>  $document  Core contract or a prior compiler output.
     *
     * @return  array<string, mixed>  Contract with only compiler-owned members removed.
     *
     * @throws  InvalidArgumentException  When a generated marker is malformed or names an unsafe member.
     *
     * @since   2.0.0
     */
    private function stripPriorGeneratedContract(array $document): array
    {
        $generation = $document['x-kumwe-business-generation'] ?? null;
        $generatedComponents = $document['x-kumwe-generated-components'] ?? null;
        $priorPaths = $document['x-kumwe-generated-paths'] ?? null;
        if ($generation === null && $generatedComponents === null && $priorPaths === null) {
            return $document;
        }
        if (
            !is_string($generation) || preg_match('/^[a-f0-9]{64}$/D', $generation) !== 1
            || !is_array($generatedComponents) || !array_is_list($generatedComponents)
            || count($generatedComponents) > 1024
            || ($priorPaths !== null && (!is_array($priorPaths) || !array_is_list($priorPaths)))
            || (is_array($priorPaths) && count($priorPaths) > 256)
        ) {
            throw new InvalidArgumentException('The prior generated OpenAPI markers are invalid.');
        }
        $componentRegistry = $this->objectArray(
            $document['components'] ?? null,
            'The prior generated OpenAPI component registry is invalid.',
        );
        $schemas = $this->objectArray(
            $componentRegistry['schemas'] ?? null,
            'The prior generated OpenAPI component registry is invalid.',
        );
        foreach ($generatedComponents as $component) {
            if (!is_string($component) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,190}$/D', $component) !== 1) {
                throw new InvalidArgumentException('A prior generated OpenAPI component marker is invalid.');
            }
            unset($schemas[$component]);
        }
        $componentRegistry['schemas'] = $schemas;
        $document['components'] = $componentRegistry;
        $paths = $this->objectArray(
            $document['paths'] ?? null,
            'The prior generated OpenAPI path registry is invalid.',
        );
        if ($priorPaths === null) {
            $operationIds = [];
            foreach ($this->paths() as $pathItem) {
                foreach ($pathItem as $member) {
                    if (is_array($member) && is_string($member['operationId'] ?? null)) {
                        $operationIds[$member['operationId']] = true;
                    }
                }
            }
            foreach ($paths as $path => $pathItem) {
                if (!is_array($pathItem)) {
                    continue;
                }
                foreach ($pathItem as $member) {
                    $operationId = is_array($member) ? ($member['operationId'] ?? null) : null;
                    if (is_string($operationId) && isset($operationIds[$operationId])) {
                        unset($paths[$path]);
                        break;
                    }
                }
            }
            $priorPaths = array_keys($this->paths());
        }
        foreach ($priorPaths as $path) {
            if (!is_string($path) || strlen($path) > 512 || !str_starts_with($path, '/')) {
                throw new InvalidArgumentException('A prior generated OpenAPI path marker is invalid.');
            }
            unset($paths[$path]);
        }
        $document['paths'] = $paths;
        unset(
            $document['x-kumwe-business-generation'],
            $document['x-kumwe-generated-components'],
            $document['x-kumwe-generated-paths'],
        );

        return $document;
    }

    /**
     * Build a collision-resistant component prefix from one namespaced definition handle.
     *
     * @param   array<string, mixed>  $definition  Safe entity metadata.
     *
     * @return  string  Component prefix beginning `Business_`.
     *
     * @throws  InvalidArgumentException  When the handle is absent or malformed.
     *
     * @since   2.0.0
     */
    private function componentName(array $definition): string
    {
        $handle = $definition['handle'] ?? null;
        if (!is_string($handle) || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $handle) !== 1) {
            throw new InvalidArgumentException('A generated OpenAPI definition handle is invalid.');
        }

        return 'Business_' . str_replace(['.', '-'], '_', $handle) . '_';
    }

    /**
     * Compile an entity schema for one read or write use.
     *
     * @param   array<string, mixed>  $definition  Safe metadata from the shared catalog.
     * @param   string                $use         `detail`, `create`, or `update`.
     *
     * @return  array<string, mixed>  Closed JSON Schema object.
     *
     * @throws  InvalidArgumentException  When field metadata is malformed or unbounded.
     *
     * @since   2.0.0
     */
    private function entitySchema(array $definition, string $use): array
    {
        $fields = $definition['fields'] ?? null;
        if (!is_array($fields) || !array_is_list($fields) || count($fields) > 256) {
            throw new InvalidArgumentException('Generated OpenAPI fields are invalid or unbounded.');
        }
        $properties = [];
        $required = [];
        foreach ($fields as $field) {
            if (!is_array($field) || array_is_list($field)) {
                throw new InvalidArgumentException('Generated OpenAPI field metadata is invalid.');
            }
            $handle = $field['handle'] ?? null;
            $uses = $field['uses'] ?? null;
            $schema = $field['schema'] ?? null;
            if (
                !is_string($handle) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1
                || !is_array($uses) || array_is_list($uses)
                || !is_array($schema) || array_is_list($schema)
            ) {
                throw new InvalidArgumentException('Generated OpenAPI field metadata is invalid.');
            }
            if (($uses[$use] ?? false) !== true) {
                continue;
            }
            if ($use !== 'detail' && ($schema['readOnly'] ?? false) === true) {
                continue;
            }
            if ($use === 'detail' && ($schema['writeOnly'] ?? false) === true) {
                continue;
            }
            $properties[$handle] = $schema;
            if (($field['required'] ?? false) === true && $use === 'create') {
                $required[] = $handle;
            }
        }
        ksort($properties, SORT_STRING);
        sort($required, SORT_STRING);
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'maxProperties' => 256,
            'properties' => $properties,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Read and revalidate caller-visible custom contracts from one safe catalog document.
     *
     * The catalog deliberately omits executable handler and private schema-reference identifiers. This
     * compiler accepts only the two public schema members for the requested contract family and runs the
     * bounded schema validator again before those bytes enter an externally served OpenAPI document.
     *
     * @param   array<string, mixed>  $definition  Safe merged definition metadata.
     * @param   string                $collection  `views` or `actions`.
     * @param   string                $inputKey    `query_schema` or `command_schema`.
     *
     * @return  list<array{handle: string, input: array<string, mixed>, result: array<string, mixed>}>
     *          Canonical custom contracts in catalog order.
     *
     * @throws  InvalidArgumentException  When a collection, handle, or public contract is malformed or unsafe.
     *
     * @since   2.0.0
     */
    private function customContracts(array $definition, string $collection, string $inputKey): array
    {
        $items = $definition[$collection] ?? [];
        if (!is_array($items) || !array_is_list($items) || count($items) > 128) {
            throw new InvalidArgumentException('Generated OpenAPI custom contract metadata is invalid.');
        }
        $contracts = [];
        $handles = [];
        foreach ($items as $item) {
            if (!is_array($item) || array_is_list($item) || !array_key_exists('custom_contract', $item)) {
                continue;
            }
            $handle = $item['handle'] ?? null;
            $contract = $item['custom_contract'];
            if (
                !is_string($handle)
                || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1
                || isset($handles[$handle])
                || !is_array($contract)
                || array_is_list($contract)
                || array_diff(array_keys($contract), [$inputKey, 'result_schema']) !== []
                || count($contract) !== 2
            ) {
                throw new InvalidArgumentException('Generated OpenAPI custom contract metadata is invalid.');
            }
            $handles[$handle] = true;
            $contracts[] = [
                'handle' => $handle,
                'input' => CustomBusinessSchema::fromArray($contract[$inputKey] ?? null)->toArray(),
                'result' => CustomBusinessSchema::fromArray($contract['result_schema'] ?? null)->toArray(),
            ];
        }

        return $contracts;
    }

    /**
     * Add one generated component only when neither core nor another definition has claimed its name.
     *
     * @param   array<string, mixed>  $existing   Immutable core component registry.
     * @param   array<string, mixed>  $generated  Components assembled during this compilation.
     * @param   string                $name       Deterministic caller-visible component name.
     * @param   array<string, mixed>  $schema     Canonical bounded contract schema.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the component name is unsafe or already occupied.
     *
     * @since   2.0.0
     */
    private function addSchema(array $existing, array &$generated, string $name, array $schema): void
    {
        if (
            preg_match('/^[A-Za-z][A-Za-z0-9_]{0,190}$/D', $name) !== 1
            || isset($existing[$name])
            || isset($generated[$name])
        ) {
            throw new InvalidArgumentException('A generated OpenAPI component name collides or is unsafe.');
        }
        $generated[$name] = $schema;
    }

    /**
     * Add shared business envelopes without shadowing core components.
     *
     * @param   array<string, mixed>         $existing          Existing core schema registry.
     * @param   list<array<string, string>>  $readReferences    Entity read schema references.
     * @param   list<array<string, string>>  $createReferences  Entity create schema references.
     * @param   list<array<string, string>>  $updateReferences  Entity update schema references.
     * @param   list<array<string, string>>  $viewQueries       Custom view query-contract references.
     * @param   list<array<string, string>>  $viewResults       Custom view result-contract references.
     * @param   list<array<string, string>>  $actionCommands    Custom action command-contract references.
     * @param   list<array<string, string>>  $actionResults     Custom action result-contract references.
     *
     * @return  array<string, array<string, mixed>>  Shared generated schemas.
     *
     * @throws  InvalidArgumentException  When a core component uses a generated reserved name.
     *
     * @since   2.0.0
     */
    private function commonSchemas(
        array $existing,
        array $readReferences,
        array $createReferences,
        array $updateReferences,
        array $viewQueries,
        array $viewResults,
        array $actionCommands,
        array $actionResults,
    ): array {
        $recordValues = $this->union($readReferences);
        $createValues = $this->union($createReferences);
        $updateValues = $this->union($updateReferences);
        $relationRecord = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['record_id', 'definition_version', 'version', 'position', 'values'],
            'properties' => [
                'record_id' => ['type' => 'string', 'maxLength' => 191],
                'definition_version' => ['type' => 'integer', 'minimum' => 1],
                'version' => ['type' => 'integer', 'minimum' => 1],
                'position' => ['type' => ['integer', 'null'], 'minimum' => 0],
                'values' => $recordValues,
            ],
        ];
        $record = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['record_id', 'definition_version', 'version', 'values', 'includes'],
            'properties' => [
                'record_id' => ['type' => 'string', 'maxLength' => 191],
                'definition_version' => ['type' => 'integer', 'minimum' => 1],
                'version' => ['type' => 'integer', 'minimum' => 1],
                'workflow_state' => ['type' => ['string', 'null'], 'maxLength' => 63],
                'values' => $recordValues,
                'includes' => [
                    'type' => 'object',
                    'maxProperties' => 4,
                    'additionalProperties' => [
                        'type' => 'array',
                        'maxItems' => 1000,
                        'items' => ['$ref' => '#/components/schemas/GeneratedBusinessRelationRecord'],
                    ],
                ],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                'archived_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'deleted_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ],
        ];
        $revision = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'definition_version',
                'record_version',
                'revision_number',
                'operation',
                'snapshot',
                'changed_fields',
                'occurred_at',
            ],
            'properties' => [
                'definition_version' => ['type' => 'integer', 'minimum' => 1],
                'record_version' => ['type' => 'integer', 'minimum' => 1],
                'revision_number' => ['type' => 'integer', 'minimum' => 1],
                'operation' => [
                    'type' => 'string',
                    'maxLength' => 96,
                    'pattern' => '^[a-z][a-z0-9._:-]{0,95}$',
                ],
                'snapshot' => $recordValues,
                'changed_fields' => $this->identifierList(256),
                'occurred_at' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];
        $schemas = array_merge([
            'GeneratedBusinessRelationRecord' => $relationRecord,
            'GeneratedBusinessRecord' => $record,
            'GeneratedBusinessRevision' => $revision,
            'GeneratedBusinessBrowse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['items', 'next_cursor', 'aggregates'],
                'properties' => [
                    'items' => ['type' => 'array', 'maxItems' => 200, 'items' => $record],
                    'next_cursor' => ['type' => ['string', 'null'], 'maxLength' => 65_536],
                    'aggregates' => ['type' => 'object', 'maxProperties' => 16],
                ],
            ],
            'GeneratedBusinessCreate' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['values'],
                'properties' => [
                    'values' => $createValues,
                    'record_id' => ['type' => ['string', 'null'], 'maxLength' => 191],
                ],
            ],
            'GeneratedBusinessUpdate' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['values'],
                'properties' => ['values' => $updateValues],
            ],
            'GeneratedBusinessMutation' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'record_id',
                    'definition_version',
                    'version',
                    'operation',
                    'deleted',
                    'replayed',
                ],
                'properties' => [
                    'record_id' => ['type' => 'string', 'maxLength' => 191],
                    'definition_version' => ['type' => 'integer', 'minimum' => 1],
                    'version' => ['type' => 'integer', 'minimum' => 1],
                    'workflow_state' => ['type' => ['string', 'null'], 'maxLength' => 63],
                    'operation' => ['type' => 'string', 'maxLength' => 63],
                    'deleted' => ['type' => 'boolean'],
                    'replayed' => ['type' => 'boolean'],
                    'result' => $this->contractUnion($actionResults),
                ],
            ],
            'GeneratedBusinessApprovalOperationResult' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['approval_request_id'],
                'properties' => [
                    'approval_request_id' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                ],
            ],
            'GeneratedBusinessOperationStatus' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'operation_id',
                    'state',
                    'operation',
                    'created_at',
                    'completed_at',
                    'expires_at',
                    'result',
                ],
                'properties' => [
                    'operation_id' => [
                        'type' => 'string',
                        'minLength' => 8,
                        'maxLength' => 128,
                        'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$',
                    ],
                    'state' => ['type' => 'string', 'enum' => ['completed']],
                    'operation' => [
                        'type' => 'string',
                        'maxLength' => 96,
                        'pattern' => '^[a-z][a-z0-9._:-]{0,95}$',
                    ],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'completed_at' => ['type' => 'string', 'format' => 'date-time'],
                    'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                    'result' => ['oneOf' => [
                        ['$ref' => '#/components/schemas/GeneratedBusinessMutation'],
                        ['$ref' => '#/components/schemas/GeneratedBusinessApprovalOperationResult'],
                    ]],
                ],
            ],
            'GeneratedBusinessAction' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'input' => $this->contractUnion($actionCommands, true),
                    'approval_request_id' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                ],
            ],
            'GeneratedBusinessActionApproval' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'input' => $this->contractUnion($actionCommands, true),
                ],
            ],
            'GeneratedBusinessCustomViewRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'query' => ['$ref' => '#/components/schemas/GeneratedBusinessQuery'],
                    'parameters' => $this->contractUnion($viewQueries),
                ],
            ],
            'GeneratedBusinessCustomViewResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['view', 'data'],
                'properties' => [
                    'view' => ['$ref' => '#/components/schemas/GeneratedBusinessCustomViewMetadata'],
                    'data' => $this->contractUnion($viewResults),
                ],
            ],
            'GeneratedBusinessCustomViewMetadata' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['handle', 'label', 'kind', 'fields', 'filters', 'sorts'],
                'properties' => [
                    'handle' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,62}$'],
                    'label' => ['type' => 'string', 'maxLength' => 120],
                    'kind' => ['type' => 'string', 'enum' => ['list', 'detail', 'form', 'history', 'relation']],
                    'fields' => $this->identifierList(128),
                    'filters' => $this->identifierList(128),
                    'sorts' => $this->identifierList(128),
                ],
            ],
            'GeneratedBusinessApproval' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['required', 'approval_request_id'],
                'properties' => [
                    'required' => ['type' => 'boolean'],
                    'approval_request_id' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                ],
            ],
            'GeneratedBusinessApprovalVote' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['decision', 'reason', 'decided_at'],
                'properties' => [
                    'decision' => ['type' => 'string', 'enum' => ['approve', 'reject']],
                    'reason' => ['type' => ['string', 'null'], 'maxLength' => 500],
                    'decided_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'GeneratedBusinessApprovalRequest' => $this->approvalRequestSchema(),
            'GeneratedBusinessApprovalCollection' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['items'],
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'maxItems' => 100,
                        'items' => ['$ref' => '#/components/schemas/GeneratedBusinessApprovalRequest'],
                    ],
                ],
            ],
            'GeneratedBusinessRelate' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['target_record_id'],
                'properties' => [
                    'target_record_id' => ['type' => 'string', 'maxLength' => 191],
                    'position' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 1_000_000],
                    'target_values' => ['type' => 'object', 'maxProperties' => 256],
                ],
            ],
            'GeneratedBusinessReorder' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['ordered_record_ids'],
                'properties' => [
                    'ordered_record_ids' => [
                        'type' => 'array',
                        'maxItems' => 1000,
                        'uniqueItems' => true,
                        'items' => ['type' => 'string', 'maxLength' => 191],
                    ],
                ],
            ],
            'GeneratedBusinessHistory' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['items', 'has_more', 'next_before_version'],
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'maxItems' => 200,
                        'items' => ['$ref' => '#/components/schemas/GeneratedBusinessRevision'],
                    ],
                    'has_more' => ['type' => 'boolean'],
                    'next_before_version' => ['type' => ['integer', 'null'], 'minimum' => 1],
                ],
            ],
            'GeneratedBusinessReportParameters' => [
                'type' => 'object',
                'maxProperties' => 32,
                'propertyNames' => ['pattern' => '^[a-z][a-z0-9_]{0,62}$'],
                'additionalProperties' => [
                    'oneOf' => [
                        ['type' => 'string', 'maxLength' => 4096],
                        ['type' => 'integer'],
                        ['type' => 'boolean'],
                        ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => [
                            'oneOf' => [
                                ['type' => 'string', 'maxLength' => 4096],
                                ['type' => 'integer'],
                                ['type' => 'boolean'],
                            ],
                        ]],
                    ],
                ],
            ],
            'GeneratedBusinessReportRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'parameters' => ['$ref' => '#/components/schemas/GeneratedBusinessReportParameters'],
                ],
            ],
            'GeneratedBusinessReportColumn' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['alias', 'label', 'type'],
                'properties' => [
                    'alias' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,62}$'],
                    'label' => ['type' => 'string', 'maxLength' => 191],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['string', 'integer', 'decimal', 'boolean', 'date', 'date_time', 'identifier'],
                    ],
                ],
            ],
            'GeneratedBusinessReportDrillDown' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['record_alias', 'definition', 'view', 'url'],
                'properties' => [
                    'record_alias' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,62}$'],
                    'definition' => ['type' => 'string', 'maxLength' => 191],
                    'view' => ['type' => 'string', 'maxLength' => 191],
                    'url' => ['type' => 'string', 'maxLength' => 1024, 'pattern' => '^/api/v1/business/views/'],
                ],
            ],
            'GeneratedBusinessReportResult' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'report', 'definition_checksum', 'query_digest', 'columns', 'rows', 'row_count',
                    'drill_downs', 'has_drill_downs',
                ],
                'properties' => [
                    'report' => ['type' => 'string', 'maxLength' => 191],
                    'definition_checksum' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                    'query_digest' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                    'columns' => [
                        'type' => 'array',
                        'maxItems' => 96,
                        'items' => ['$ref' => '#/components/schemas/GeneratedBusinessReportColumn'],
                    ],
                    'rows' => [
                        'type' => 'array',
                        'maxItems' => 1000,
                        'items' => [
                            'type' => 'object',
                            'maxProperties' => 96,
                            'additionalProperties' => [
                                'oneOf' => [
                                    ['type' => 'string', 'maxLength' => 65_536],
                                    ['type' => 'integer'],
                                    ['type' => 'boolean'],
                                    ['type' => 'null'],
                                ],
                            ],
                        ],
                    ],
                    'row_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000],
                    'drill_downs' => [
                        'type' => 'array',
                        'maxItems' => 1000,
                        'items' => [
                            'type' => 'array',
                            'maxItems' => 8,
                            'items' => ['$ref' => '#/components/schemas/GeneratedBusinessReportDrillDown'],
                        ],
                    ],
                    'has_drill_downs' => ['type' => 'boolean'],
                ],
            ],
            'GeneratedBusinessReportExportRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'parameters' => ['$ref' => '#/components/schemas/GeneratedBusinessReportParameters'],
                    'retention_seconds' => ['type' => 'integer', 'minimum' => 60, 'maximum' => 604_800],
                ],
            ],
            'GeneratedBusinessReportExport' => $this->reportExportSchema(),
            'GeneratedBusinessReportDefinition' => $this->reportDefinitionSchema(),
            'GeneratedBusinessReportCollection' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['items'],
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'maxItems' => 256,
                        'items' => ['$ref' => '#/components/schemas/GeneratedBusinessReportDefinition'],
                    ],
                ],
            ],
            'GeneratedBusinessQuery' => $this->querySchema(),
        ], $this->definitionMetadataSchemas());
        if (array_intersect(array_keys($existing), array_keys($schemas)) !== []) {
            throw new InvalidArgumentException('A core OpenAPI component occupies a generated reserved name.');
        }

        return $schemas;
    }

    /**
     * Describe safe report discovery metadata generated for REST clients.
     *
     * @return  array<string, mixed>  Closed report definition JSON Schema.
     *
     * @since   2.0.0
     */
    private function reportDefinitionSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id', 'title', 'parameters', 'execute_url', 'export_url'],
            'properties' => [
                'id' => ['type' => 'string', 'maxLength' => 191],
                'title' => ['type' => 'string', 'maxLength' => 191],
                'parameters' => [
                    'type' => 'array',
                    'maxItems' => 32,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'type', 'required', 'multiple', 'default'],
                        'properties' => [
                            'name' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,62}$'],
                            'type' => [
                                'type' => 'string',
                                'enum' => [
                                    'string', 'integer', 'decimal', 'boolean', 'date', 'date_time', 'identifier',
                                ],
                            ],
                            'required' => ['type' => 'boolean'],
                            'multiple' => ['type' => 'boolean'],
                            'default' => [
                                'oneOf' => [
                                    ['type' => 'string', 'maxLength' => 4096],
                                    ['type' => 'integer'],
                                    ['type' => 'boolean'],
                                    ['type' => 'array', 'maxItems' => 100, 'items' => [
                                        'oneOf' => [
                                            ['type' => 'string', 'maxLength' => 4096],
                                            ['type' => 'integer'],
                                            ['type' => 'boolean'],
                                        ],
                                    ]],
                                    ['type' => 'null'],
                                ],
                            ],
                        ],
                    ],
                ],
                'execute_url' => ['type' => 'string', 'maxLength' => 512],
                'export_url' => ['type' => 'string', 'maxLength' => 512],
            ],
        ];
    }

    /**
     * Describe omission-safe durable export lifecycle metadata.
     *
     * @return  array<string, mixed>  Closed export artifact JSON Schema.
     *
     * @since   2.0.0
     */
    private function reportExportSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'id', 'report', 'status', 'created_at', 'expires_at', 'started_at', 'completed_at',
                'filename', 'size', 'row_count', 'checksum', 'failure_code', 'version',
            ],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'report' => ['type' => 'string', 'maxLength' => 191],
                'status' => ['type' => 'string', 'enum' => ['queued', 'running', 'completed', 'failed']],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                'started_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'completed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'filename' => ['type' => ['string', 'null'], 'maxLength' => 127],
                'size' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'row_count' => ['type' => ['integer', 'null'], 'minimum' => 0],
                'checksum' => ['type' => ['string', 'null'], 'pattern' => '^[a-f0-9]{64}$'],
                'failure_code' => ['type' => ['string', 'null'], 'maxLength' => 63],
                'version' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 16],
            ],
        ];
    }

    /**
     * Describe the policy-filtered definition discovery documents emitted by the shared catalog.
     *
     * Executable references, storage names, policies, and expressions are absent by construction. Embedded
     * field and custom-contract schemas remain bounded JSON objects because they are schema documents rather
     * than business values; every surrounding metadata object and collection is otherwise closed and bounded.
     *
     * @return  array<string, array<string, mixed>>  Discovery metadata and envelope components.
     *
     * @since   2.0.0
     */
    private function definitionMetadataSchemas(): array
    {
        $identifier = ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,62}$'];
        $schemaDocument = ['type' => 'object', 'maxProperties' => 32];
        $schemaReference = ['$ref' => '#/components/schemas/GeneratedBusinessSchemaDocument'];
        $uses = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['create', 'update', 'detail', 'list', 'filter', 'search', 'sort', 'report', 'export'],
            'properties' => array_fill_keys(
                ['create', 'update', 'detail', 'list', 'filter', 'search', 'sort', 'report', 'export'],
                ['type' => 'boolean'],
            ),
        ];
        $customContract = [
            'oneOf' => [[
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['query_schema', 'result_schema'],
                'properties' => [
                    'query_schema' => $schemaReference,
                    'result_schema' => $schemaReference,
                ],
            ], [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['command_schema', 'result_schema'],
                'properties' => [
                    'command_schema' => $schemaReference,
                    'result_schema' => $schemaReference,
                ],
            ]],
        ];
        $field = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'handle', 'label', 'description', 'help_text', 'type', 'value_type', 'required', 'nullable',
                'read_only', 'write_only', 'immutable_after_create', 'conditional', 'form_group', 'order',
                'placements', 'uses', 'schema',
            ],
            'properties' => [
                'handle' => $identifier,
                'label' => ['type' => 'string', 'maxLength' => 120],
                'description' => ['type' => 'string', 'maxLength' => 1000],
                'help_text' => ['type' => 'string', 'maxLength' => 1000],
                'type' => ['type' => 'string', 'maxLength' => 191],
                'value_type' => ['type' => 'string', 'maxLength' => 32],
                'required' => ['type' => 'boolean'],
                'nullable' => ['type' => 'boolean'],
                'read_only' => ['type' => 'boolean'],
                'write_only' => ['type' => 'boolean'],
                'immutable_after_create' => ['type' => 'boolean'],
                'conditional' => ['type' => 'boolean'],
                'form_group' => ['type' => 'string', 'maxLength' => 63],
                'order' => ['type' => 'integer'],
                'placements' => [
                    'type' => 'array',
                    'maxItems' => 8,
                    'uniqueItems' => true,
                    'items' => ['type' => 'string', 'maxLength' => 32],
                ],
                'uses' => $uses,
                'schema' => $schemaReference,
            ],
        ];
        $view = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['handle', 'label', 'kind', 'custom', 'fields', 'filters', 'sorts'],
            'properties' => [
                'handle' => $identifier,
                'label' => ['type' => 'string', 'maxLength' => 120],
                'kind' => ['type' => 'string', 'enum' => ['list', 'detail', 'form', 'history', 'relation']],
                'custom' => ['type' => 'boolean'],
                'fields' => $this->identifierList(128),
                'filters' => $this->identifierList(128),
                'sorts' => $this->identifierList(128),
                'custom_contract' => ['$ref' => '#/components/schemas/GeneratedBusinessCustomContract'],
            ],
        ];
        $action = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['handle', 'label', 'bulk', 'high_impact', 'transition', 'conditional'],
            'properties' => [
                'handle' => $identifier,
                'label' => ['type' => 'string', 'maxLength' => 120],
                'bulk' => ['type' => 'boolean'],
                'high_impact' => ['type' => 'boolean'],
                'transition' => ['type' => ['string', 'null'], 'maxLength' => 63],
                'conditional' => ['type' => 'boolean'],
                'custom_contract' => ['$ref' => '#/components/schemas/GeneratedBusinessCustomContract'],
            ],
        ];
        $relationship = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['handle', 'label', 'kind', 'target', 'required', 'ordered'],
            'properties' => [
                'handle' => $identifier,
                'label' => ['type' => 'string', 'maxLength' => 120],
                'kind' => [
                    'type' => 'string',
                    'enum' => [
                        'one_to_one', 'many_to_one', 'one_to_many', 'many_to_many', 'owned_line_collection',
                    ],
                ],
                'target' => ['type' => 'string', 'maxLength' => 191],
                'required' => ['type' => 'boolean'],
                'ordered' => ['type' => 'boolean'],
            ],
        ];
        $metadata = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'id', 'handle', 'singular_label', 'plural_label', 'version', 'checksum', 'owner', 'scope',
                'soft_delete', 'workflow', 'operation', 'fields', 'views', 'actions', 'relationships',
            ],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'handle' => ['type' => 'string', 'maxLength' => 191],
                'singular_label' => ['type' => 'string', 'maxLength' => 120],
                'plural_label' => ['type' => 'string', 'maxLength' => 120],
                'version' => ['type' => 'integer', 'minimum' => 1],
                'checksum' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'owner' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['type', 'identifier'],
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['core', 'extension', 'site']],
                        'identifier' => ['type' => 'string', 'maxLength' => 191],
                    ],
                ],
                'scope' => [
                    'type' => 'string',
                    'enum' => ['installation', 'site', 'organization', 'site_organization'],
                ],
                'soft_delete' => ['type' => 'boolean'],
                'workflow' => $this->workflowMetadataSchema(),
                'operation' => ['type' => 'string', 'maxLength' => 32],
                'fields' => [
                    'type' => 'array',
                    'maxItems' => 256,
                    'items' => ['$ref' => '#/components/schemas/GeneratedBusinessFieldMetadata'],
                ],
                'views' => [
                    'type' => 'array',
                    'maxItems' => 128,
                    'items' => ['$ref' => '#/components/schemas/GeneratedBusinessViewMetadata'],
                ],
                'actions' => [
                    'type' => 'array',
                    'maxItems' => 128,
                    'items' => ['$ref' => '#/components/schemas/GeneratedBusinessActionMetadata'],
                ],
                'relationships' => [
                    'type' => 'array',
                    'maxItems' => 128,
                    'items' => ['$ref' => '#/components/schemas/GeneratedBusinessRelationshipMetadata'],
                ],
            ],
        ];

        return [
            'GeneratedBusinessSchemaDocument' => $schemaDocument,
            'GeneratedBusinessCustomContract' => $customContract,
            'GeneratedBusinessFieldMetadata' => $field,
            'GeneratedBusinessViewMetadata' => $view,
            'GeneratedBusinessActionMetadata' => $action,
            'GeneratedBusinessRelationshipMetadata' => $relationship,
            'GeneratedBusinessDefinitionMetadata' => $metadata,
            'GeneratedBusinessDefinitionCollection' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'maxItems' => 256,
                        'items' => ['$ref' => '#/components/schemas/GeneratedBusinessDefinitionMetadata'],
                    ],
                ],
            ],
            'GeneratedBusinessDefinitionDocument' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['data'],
                'properties' => [
                    'data' => ['$ref' => '#/components/schemas/GeneratedBusinessDefinitionMetadata'],
                ],
            ],
        ];
    }

    /**
     * Describe optional workflow metadata without leaking executable transition conditions.
     *
     * @return  array<string, mixed>  Nullable closed workflow summary schema.
     *
     * @since   2.0.0
     */
    private function workflowMetadataSchema(): array
    {
        return ['oneOf' => [[
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['initial_state', 'states'],
            'properties' => [
                'initial_state' => ['type' => 'string', 'maxLength' => 63],
                'states' => [
                    'type' => 'array',
                    'maxItems' => 128,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['handle', 'label'],
                        'properties' => [
                            'handle' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,62}$'],
                            'label' => ['type' => 'string', 'maxLength' => 120],
                        ],
                    ],
                ],
            ],
        ], ['type' => 'null']]];
    }

    /**
     * Build an entity union, falling back to a closed empty object before any entities are installed.
     *
     * @param   list<array<string, string>>  $references  Local component references.
     *
     * @return  array<string, mixed>  JSON Schema union.
     *
     * @since   2.0.0
     */
    private function union(array $references): array
    {
        return $references === []
            ? ['type' => 'object', 'additionalProperties' => false]
            : ['oneOf' => $references];
    }

    /**
     * Build a custom-contract union without making overlapping valid contracts mutually exclusive.
     *
     * @param   list<array<string, string>>  $references    Local contract component references.
     * @param   bool                         $includeEmpty  Whether ordinary generated behavior also accepts `{}`.
     *
     * @return  array<string, mixed>  Closed empty object or bounded contract union.
     *
     * @since   2.0.0
     */
    private function contractUnion(array $references, bool $includeEmpty = false): array
    {
        $empty = ['type' => 'object', 'additionalProperties' => false];
        if ($references === []) {
            return $empty;
        }

        return ['anyOf' => $includeEmpty ? [$empty, ...$references] : $references];
    }

    /**
     * Build one bounded list of definition-local field identifiers.
     *
     * @param   int  $maximum  Maximum list entries.
     *
     * @return  array<string, mixed>  Bounded unique string-list schema.
     *
     * @since   2.0.0
     */
    private function identifierList(int $maximum): array
    {
        return [
            'type' => 'array',
            'maxItems' => $maximum,
            'uniqueItems' => true,
            'items' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,62}$'],
        ];
    }

    /**
     * Declare the actor- and evidence-omitting approval detail projection.
     *
     * @return  array<string, mixed>  Closed approval request schema.
     *
     * @since   2.0.0
     */
    private function approvalRequestSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'approval_request_id', 'action', 'resource_type', 'resource_version',
                'required_quorum', 'approval_count', 'status', 'version', 'created_at', 'expires_at',
                'can_approve', 'can_cancel', 'can_revoke',
            ],
            'properties' => [
                'approval_request_id' => ['type' => 'string', 'format' => 'uuid'],
                'action' => ['type' => 'string', 'maxLength' => 127],
                'resource_type' => ['type' => 'string', 'maxLength' => 63],
                'resource_version' => ['type' => 'integer', 'minimum' => 1],
                'required_quorum' => ['type' => 'integer', 'minimum' => 1],
                'approval_count' => ['type' => 'integer', 'minimum' => 0],
                'status' => [
                    'type' => 'string',
                    'enum' => ['pending', 'approved', 'rejected', 'cancelled', 'revoked', 'consumed'],
                ],
                'version' => ['type' => 'integer', 'minimum' => 1],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                'can_approve' => ['type' => 'boolean'],
                'can_cancel' => ['type' => 'boolean'],
                'can_revoke' => ['type' => 'boolean'],
                'votes' => [
                    'type' => 'array',
                    'maxItems' => 100,
                    'items' => ['$ref' => '#/components/schemas/GeneratedBusinessApprovalVote'],
                ],
            ],
        ];
    }

    /**
     * Declare the bounded generic record-query wire grammar.
     *
     * @return  array<string, mixed>  Closed query schema aligned with BusinessRecordQueryFactory.
     *
     * @since   2.0.0
     */
    private function querySchema(): array
    {
        $identifier = ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,62}$'];
        $literal = ['oneOf' => [
            ['type' => 'boolean'],
            ['type' => 'integer'],
            ['type' => 'string', 'maxLength' => 4096],
        ]];
        $filterReference = ['$ref' => '#/components/schemas/GeneratedBusinessQuery/$defs/Filter'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'maxProperties' => 8,
            'properties' => [
                'filter' => ['oneOf' => [$filterReference, ['type' => 'null']]],
                'search' => [
                    'oneOf' => [[
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['term', 'fields'],
                        'properties' => [
                            'term' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 256],
                            'fields' => [
                                'type' => 'array', 'minItems' => 1, 'maxItems' => 16,
                                'uniqueItems' => true, 'items' => $identifier,
                            ],
                        ],
                    ], ['type' => 'null']],
                ],
                'sorts' => [
                    'type' => 'array',
                    'maxItems' => 5,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['field'],
                        'properties' => [
                            'field' => $identifier,
                            'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                            'nulls_last' => ['type' => 'boolean'],
                        ],
                    ],
                ],
                'after' => ['type' => ['string', 'null'], 'maxLength' => 65_536],
                'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                'projection' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'maxProperties' => 3,
                    'properties' => [
                        'fields' => [
                            'type' => 'array', 'maxItems' => 64, 'uniqueItems' => true,
                            'items' => $identifier,
                        ],
                        'includes' => [
                            'type' => 'array', 'maxItems' => 4, 'uniqueItems' => true,
                            'items' => $identifier,
                        ],
                        'aggregates' => [
                            'type' => 'array',
                            'maxItems' => 16,
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['alias', 'function'],
                                'properties' => [
                                    'alias' => $identifier,
                                    'function' => [
                                        'type' => 'string', 'enum' => ['count', 'sum', 'min', 'max', 'avg'],
                                    ],
                                    'field' => ['oneOf' => [$identifier, ['type' => 'null']]],
                                ],
                            ],
                        ],
                    ],
                ],
                'include_archived' => ['type' => 'boolean'],
                'include_deleted' => ['type' => 'boolean'],
            ],
            '$defs' => [
                'Filter' => [
                    'oneOf' => [
                        $this->filterSchema('comparison', [
                            'field' => $identifier,
                            'operator' => ['type' => 'string', 'enum' => ['eq', 'ne', 'lt', 'lte', 'gt', 'gte']],
                            'value' => $literal,
                        ], ['field', 'operator', 'value']),
                        $this->filterSchema('text', [
                            'field' => $identifier,
                            'operator' => [
                                'type' => 'string', 'enum' => ['contains', 'starts_with', 'ends_with'],
                            ],
                            'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 256],
                        ], ['field', 'operator', 'text']),
                        $this->filterSchema('set', [
                            'field' => $identifier,
                            'values' => [
                                'type' => 'array', 'minItems' => 1, 'maxItems' => 100,
                                'items' => $literal,
                            ],
                            'negated' => ['type' => 'boolean'],
                        ], ['field', 'values']),
                        $this->filterSchema('null', [
                            'field' => $identifier,
                            'is_null' => ['type' => 'boolean'],
                        ], ['field']),
                        $this->filterSchema('boolean', [
                            'operator' => ['type' => 'string', 'enum' => ['all', 'any', 'not']],
                            'children' => [
                                'type' => 'array', 'minItems' => 1, 'maxItems' => 16,
                                'items' => $filterReference,
                            ],
                        ], ['operator', 'children']),
                        $this->filterSchema('relation', [
                            'relationship' => $identifier,
                            'quantifier' => ['type' => 'string', 'enum' => ['any', 'none', 'all']],
                            'target' => $filterReference,
                        ], ['relationship', 'quantifier', 'target']),
                    ],
                ],
            ],
        ];
    }

    /**
     * Build one closed discriminated filter-node schema.
     *
     * @param   string                $type        Literal filter discriminator.
     * @param   array<string, mixed>  $properties  Node-specific property schemas.
     * @param   list<string>          $required    Node-specific required member names.
     *
     * @return  array<string, mixed>  Closed recursive filter-node schema.
     *
     * @since   2.0.0
     */
    private function filterSchema(string $type, array $properties, array $required): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['type', ...$required],
            'properties' => ['type' => ['const' => $type], ...$properties],
        ];
    }

    /**
     * Declare the projection subset accepted by a single-record read.
     *
     * @return  array<string, mixed>  Closed fields/includes projection schema.
     *
     * @since   2.0.0
     */
    private function readProjectionSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'maxProperties' => 2,
            'properties' => [
                'fields' => $this->identifierList(64),
                'includes' => $this->identifierList(4),
            ],
        ];
    }

    /**
     * Add supported query parameters to an operation without replacing its required mutation headers.
     *
     * @param   array<string, mixed>        $operation   OpenAPI operation being extended.
     * @param   list<array<string, mixed>>  $parameters  Query parameter declarations in wire order.
     *
     * @return  array<string, mixed>  Operation carrying both existing and supplied parameters.
     *
     * @since   2.0.0
     */
    private function withParameters(array $operation, array $parameters): array
    {
        $existing = $operation['parameters'] ?? [];
        if (!is_array($existing) || !array_is_list($existing)) {
            throw new InvalidArgumentException('A generated OpenAPI operation parameter list is invalid.');
        }
        $operation['parameters'] = [...$existing, ...$parameters];

        return $operation;
    }

    /**
     * Describe one bracket-notation structured query parameter.
     *
     * @param   string                $name    Root query parameter name.
     * @param   array<string, mixed>  $schema  Bounded schema parsed by the REST request adapter.
     *
     * @return  array<string, mixed>  Exploded deep-object parameter.
     *
     * @since   2.0.0
     */
    private function structuredQueryParameter(string $name, array $schema): array
    {
        return [
            ...$this->parameter($name, 'query', false, $schema),
            'style' => 'deepObject',
            'explode' => true,
        ];
    }

    /**
     * Read one compiler-owned schema property through the same object validation as external input.
     *
     * @param   array<string, mixed>  $properties  Query-schema property registry.
     * @param   string                $member      Required schema member.
     *
     * @return  array<string, mixed>  Validated schema object.
     *
     * @throws  InvalidArgumentException  When a compiler-owned query schema is malformed.
     *
     * @since   2.0.0
     */
    private function schemaMember(array $properties, string $member): array
    {
        return $this->objectArray(
            $properties[$member] ?? null,
            'The generated OpenAPI query schema is invalid.',
        );
    }

    /**
     * Declare every bounded generic REST path once.
     *
     * @return  array<string, array<string, mixed>>  Path items keyed by literal OpenAPI path.
     *
     * @since   2.0.0
     */
    private function paths(): array
    {
        $definition = $this->parameter('definition', 'path', true, ['type' => 'string', 'maxLength' => 191]);
        $record = $this->parameter('record', 'path', true, ['type' => 'string', 'maxLength' => 191]);
        $relation = $this->parameter('relation', 'path', true, [
            'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,62}$',
        ]);
        $target = $this->parameter('target', 'path', true, ['type' => 'string', 'maxLength' => 191]);
        $action = $this->parameter('action', 'path', true, [
            'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,62}$',
        ]);
        $view = $this->parameter('view', 'path', true, [
            'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,62}$',
        ]);
        $operation = $this->parameter('operation', 'path', true, [
            'type' => 'string',
            'minLength' => 8,
            'maxLength' => 128,
            'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$',
        ]);
        $approval = $this->parameter('approval', 'path', true, ['type' => 'string', 'format' => 'uuid']);
        $report = $this->parameter('report', 'path', true, [
            'type' => 'string', 'maxLength' => 191,
            'pattern' => '^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$',
        ]);
        $artifact = $this->parameter('artifact', 'path', true, ['type' => 'string', 'format' => 'uuid']);
        $queryDocument = $this->querySchema();
        $query = $this->objectArray(
            $queryDocument['properties'] ?? null,
            'The generated OpenAPI query schema is invalid.',
        );
        $browseParameters = [
            $this->structuredQueryParameter('filter', $this->schemaMember($query, 'filter')),
            $this->structuredQueryParameter('search', $this->schemaMember($query, 'search')),
            $this->structuredQueryParameter('sorts', $this->schemaMember($query, 'sorts')),
            $this->parameter('after', 'query', false, $this->schemaMember($query, 'after')),
            $this->parameter('page_size', 'query', false, $this->schemaMember($query, 'page_size')),
            $this->structuredQueryParameter('projection', $this->schemaMember($query, 'projection')),
            $this->parameter(
                'include_archived',
                'query',
                false,
                $this->schemaMember($query, 'include_archived'),
            ),
            $this->parameter(
                'include_deleted',
                'query',
                false,
                $this->schemaMember($query, 'include_deleted'),
            ),
        ];
        $readParameters = [
            $this->structuredQueryParameter('projection', $this->readProjectionSchema()),
            $this->parameter(
                'include_archived',
                'query',
                false,
                $this->schemaMember($query, 'include_archived'),
            ),
            $this->parameter(
                'include_deleted',
                'query',
                false,
                $this->schemaMember($query, 'include_deleted'),
            ),
        ];
        $historyParameters = [
            $this->parameter('limit', 'query', false, ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]),
            $this->parameter('before_version', 'query', false, ['type' => 'integer', 'minimum' => 1]),
        ];
        $customView = $this->withOptionalRequestBody($this->operation(
            'businessRecordCustomView',
            'Execute a declared collection custom view',
            '200',
            'GeneratedBusinessCustomViewRequest',
            'GeneratedBusinessCustomViewResponse',
        ));
        $customRecordView = $this->withOptionalRequestBody($this->operation(
            'businessRecordCustomRecordView',
            'Execute a declared record custom view',
            '200',
            'GeneratedBusinessCustomViewRequest',
            'GeneratedBusinessCustomViewResponse',
        ));
        $actionOperation = $this->withOptionalRequestBody($this->operation(
            'businessRecordAction',
            'Execute a declared business action',
            '200',
            'GeneratedBusinessAction',
            'GeneratedBusinessMutation',
        ));
        $approvalOperation = $this->withOptionalRequestBody($this->operation(
            'businessRecordActionApproval',
            'Request exact action approval',
            '201',
            'GeneratedBusinessActionApproval',
            'GeneratedBusinessApproval',
        ));
        $approvalOperation = $this->withResponseAlias($approvalOperation, '201', '200');
        $downloadOperation = $this->operation(
            'businessReportExportDownload',
            'Download a verified business report CSV export',
            '200',
        );
        $downloadResponses = $this->responseRegistry(
            $downloadOperation['responses'] ?? null,
            'The generated report download response registry is invalid.',
        );
        $downloadResponses[200] = [
            'description' => 'Verified CSV export.',
            'headers' => [
                'ETag' => ['schema' => ['type' => 'string', 'pattern' => '^"sha256-[a-f0-9]{64}"$']],
                'Content-Disposition' => ['schema' => ['type' => 'string', 'maxLength' => 512]],
                'X-Content-Type-Options' => ['schema' => ['type' => 'string', 'const' => 'nosniff']],
            ],
            'content' => [
                'text/csv' => ['schema' => ['type' => 'string', 'format' => 'binary']],
            ],
        ];
        $downloadOperation['responses'] = $downloadResponses;

        return [
            '/api/v1/business/reports' => [
                'get' => $this->operation(
                    'businessReportList',
                    'List active reports visible to the API credential',
                    '200',
                    null,
                    'GeneratedBusinessReportCollection',
                ),
            ],
            '/api/v1/business/reports/{report}' => [
                'parameters' => [$report],
                'post' => $this->operation(
                    'businessReportExecute',
                    'Execute a contributed business report',
                    '200',
                    'GeneratedBusinessReportRequest',
                    'GeneratedBusinessReportResult',
                ),
            ],
            '/api/v1/business/reports/{report}/exports' => [
                'parameters' => [$report],
                'post' => $this->operation(
                    'businessReportExportRequest',
                    'Queue a policy-bound CSV business report export',
                    '202',
                    'GeneratedBusinessReportExportRequest',
                    'GeneratedBusinessReportExport',
                ),
            ],
            '/api/v1/business/report-exports/{artifact}' => [
                'parameters' => [$artifact],
                'get' => $this->operation(
                    'businessReportExportStatus',
                    'Read business report export status',
                    '200',
                    null,
                    'GeneratedBusinessReportExport',
                ),
            ],
            '/api/v1/business/report-exports/{artifact}/download' => [
                'parameters' => [$artifact],
                'get' => $downloadOperation,
            ],
            '/api/v1/business/approvals' => [
                'get' => $this->operation(
                    'businessApprovalList',
                    'List visible business approval requests',
                    '200',
                    null,
                    'GeneratedBusinessApprovalCollection',
                ),
            ],
            '/api/v1/business/approvals/{approval}' => [
                'parameters' => [$approval],
                'get' => $this->operation(
                    'businessApprovalRead',
                    'Read a visible business approval request',
                    '200',
                    null,
                    'GeneratedBusinessApprovalRequest',
                ),
            ],
            '/api/v1/business/definitions' => [
                'get' => $this->operation(
                    'businessDefinitionDiscover',
                    'Discover business definitions',
                    '200',
                    null,
                    'GeneratedBusinessDefinitionCollection',
                ),
            ],
            '/api/v1/business/definitions/{definition}' => [
                'parameters' => [$definition],
                'get' => $this->operation(
                    'businessDefinitionInspect',
                    'Inspect a business definition',
                    '200',
                    null,
                    'GeneratedBusinessDefinitionDocument',
                ),
            ],
            '/api/v1/business/operations/{operation}' => [
                'parameters' => [$operation],
                'get' => $this->operation(
                    'businessOperationStatusRead',
                    'Read a caller-bound business operation result',
                    '200',
                    null,
                    'GeneratedBusinessOperationStatus',
                ),
            ],
            '/api/v1/business/records/{definition}' => [
                'parameters' => [$definition],
                'get' => $this->withParameters($this->operation(
                    'businessRecordBrowse',
                    'Browse business records',
                    '200',
                    null,
                    'GeneratedBusinessBrowse',
                ), $browseParameters),
                'post' => $this->operation(
                    'businessRecordCreate',
                    'Create a business record',
                    '201',
                    'GeneratedBusinessCreate',
                    'GeneratedBusinessMutation',
                ),
            ],
            '/api/v1/business/views/{definition}/{view}' => [
                'parameters' => [$definition, $view],
                'post' => $customView,
            ],
            '/api/v1/business/records/{definition}/search' => [
                'parameters' => [$definition],
                'post' => $this->operation(
                    'businessRecordSearch',
                    'Search business records with the bounded query AST',
                    '200',
                    'GeneratedBusinessQuery',
                    'GeneratedBusinessBrowse',
                ),
            ],
            '/api/v1/business/records/{definition}/{record}' => [
                'parameters' => [$definition, $record],
                'get' => $this->withParameters($this->operation(
                    'businessRecordRead',
                    'Read a business record',
                    '200',
                    null,
                    'GeneratedBusinessRecord',
                ), $readParameters),
                'patch' => $this->operation(
                    'businessRecordUpdate',
                    'Update a business record',
                    '200',
                    'GeneratedBusinessUpdate',
                    'GeneratedBusinessMutation',
                ),
                'delete' => $this->operation(
                    'businessRecordDelete',
                    'Delete a business record',
                    '200',
                    null,
                    'GeneratedBusinessMutation',
                ),
            ],
            '/api/v1/business/views/{definition}/{record}/{view}' => [
                'parameters' => [$definition, $record, $view],
                'post' => $customRecordView,
            ],
            '/api/v1/business/records/{definition}/{record}/archive' => [
                'parameters' => [$definition, $record],
                'post' => $this->operation(
                    'businessRecordArchive',
                    'Archive a business record',
                    '200',
                    null,
                    'GeneratedBusinessMutation',
                ),
            ],
            '/api/v1/business/records/{definition}/{record}/restore' => [
                'parameters' => [$definition, $record],
                'post' => $this->operation(
                    'businessRecordRestore',
                    'Restore a business record',
                    '200',
                    null,
                    'GeneratedBusinessMutation',
                ),
            ],
            '/api/v1/business/records/{definition}/{record}/history' => [
                'parameters' => [$definition, $record],
                'get' => $this->withParameters($this->operation(
                    'businessRecordHistory',
                    'Read business record history',
                    '200',
                    null,
                    'GeneratedBusinessHistory',
                ), $historyParameters),
            ],
            '/api/v1/business/records/{definition}/{record}/actions/{action}' => [
                'parameters' => [$definition, $record, $action],
                'post' => $actionOperation,
            ],
            '/api/v1/business/records/{definition}/{record}/actions/{action}/approval' => [
                'parameters' => [$definition, $record, $action],
                'post' => $approvalOperation,
            ],
            '/api/v1/business/records/{definition}/{record}/relations/{relation}' => [
                'parameters' => [$definition, $record, $relation],
                'get' => $this->operation(
                    'businessRecordRelationRead',
                    'Read a bounded business-record relationship',
                    '200',
                    null,
                    'GeneratedBusinessRecord',
                ),
                'post' => $this->operation(
                    'businessRecordRelate',
                    'Relate a business record',
                    '200',
                    'GeneratedBusinessRelate',
                    'GeneratedBusinessMutation',
                ),
            ],
            '/api/v1/business/records/{definition}/{record}/relations/{relation}/{target}' => [
                'parameters' => [$definition, $record, $relation, $target],
                'delete' => $this->operation(
                    'businessRecordUnrelate',
                    'Unrelate a business record',
                    '200',
                    null,
                    'GeneratedBusinessMutation',
                ),
            ],
            '/api/v1/business/records/{definition}/{record}/relations/{relation}/order' => [
                'parameters' => [$definition, $record, $relation],
                'put' => $this->operation(
                    'businessRecordReorder',
                    'Reorder an ordered business relation',
                    '200',
                    'GeneratedBusinessReorder',
                    'GeneratedBusinessMutation',
                ),
            ],
        ];
    }

    /**
     * Build one secured operation with standard problem responses.
     *
     * @param   string   $operationId     Globally unique operation identifier.
     * @param   string   $summary         Short operation summary.
     * @param   string   $successStatus   HTTP success status.
     * @param   ?string  $requestSchema   Optional component name for the JSON body.
     * @param   ?string  $responseSchema  Optional component name for the JSON response.
     *
     * @return  array<string, mixed>  OpenAPI operation object.
     *
     * @since   2.0.0
     */
    private function operation(
        string $operationId,
        string $summary,
        string $successStatus,
        ?string $requestSchema = null,
        ?string $responseSchema = null,
    ): array {
        $success = ['description' => 'Successful operation.'];
        if ($responseSchema !== null) {
            $success['content'] = [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/' . $responseSchema],
                ],
            ];
        }
        $operation = [
            'operationId' => $operationId,
            'summary' => $summary,
            'security' => [['bearerAuth' => [], 'siteContext' => []]],
            'responses' => [
                $successStatus => $success,
                '404' => $this->problem('Resource not found or not visible.'),
                '409' => $this->problem('The operation conflicts with current state.'),
                '412' => $this->problem('The optimistic concurrency precondition failed.'),
                '422' => $this->problem('The submitted operation failed validation.'),
                '503' => $this->problem('The business runtime is temporarily unavailable.'),
            ],
        ];
        $mutations = [
            'businessRecordCreate',
            'businessRecordUpdate',
            'businessRecordDelete',
            'businessRecordArchive',
            'businessRecordRestore',
            'businessRecordAction',
            'businessRecordActionApproval',
            'businessRecordRelate',
            'businessRecordUnrelate',
            'businessRecordReorder',
            'businessReportExportRequest',
        ];
        if (in_array($operationId, $mutations, true)) {
            $operation['parameters'] = [[
                'name' => 'Idempotency-Key',
                'in' => 'header',
                'required' => true,
                'schema' => [
                    'type' => 'string',
                    'minLength' => 8,
                    'maxLength' => 128,
                    'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$',
                ],
            ]];
            if ($operationId !== 'businessRecordCreate' && $operationId !== 'businessReportExportRequest') {
                $operation['parameters'][] = [
                    'name' => 'If-Match',
                    'in' => 'header',
                    'required' => true,
                    'schema' => ['type' => 'string', 'pattern' => '^"v[1-9][0-9]*"$'],
                ];
            }
        }
        if ($requestSchema !== null) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/' . $requestSchema],
                    ],
                ],
            ];
        }

        return $operation;
    }

    /**
     * Make an operation's generated request body optional after proving its expected shape.
     *
     * @param   array<string, mixed>  $operation  Generated operation with a request body.
     *
     * @return  array<string, mixed>  Operation with an optional request body.
     *
     * @throws  InvalidArgumentException  When the compiler-owned request body is malformed.
     *
     * @since   2.0.0
     */
    private function withOptionalRequestBody(array $operation): array
    {
        $requestBody = $this->objectArray(
            $operation['requestBody'] ?? null,
            'A generated OpenAPI request body is invalid.',
        );
        $requestBody['required'] = false;
        $operation['requestBody'] = $requestBody;

        return $operation;
    }

    /**
     * Add an identical response under another status after validating the response registry.
     *
     * @param   array<string, mixed>  $operation  Generated operation whose response is aliased.
     * @param   string                $source     Existing response status.
     * @param   string                $alias      Additional response status.
     *
     * @return  array<string, mixed>  Operation containing both response statuses.
     *
     * @throws  InvalidArgumentException  When the compiler-owned response registry is malformed.
     *
     * @since   2.0.0
     */
    private function withResponseAlias(array $operation, string $source, string $alias): array
    {
        $responses = $this->responseRegistry(
            $operation['responses'] ?? null,
            'A generated OpenAPI response registry is invalid.',
        );
        $response = $this->objectArray(
            $responses[$source] ?? null,
            'A generated OpenAPI response is invalid.',
        );
        $responses[$alias] = $response;
        $operation['responses'] = $responses;

        return $operation;
    }

    /**
     * Validate an OpenAPI response registry while preserving PHP's numeric-string key coercion.
     *
     * HTTP status keys such as `201` become integer array keys in PHP even though JSON emits object-member
     * names. This validator accepts exactly three-digit status keys and leaves their representation intact,
     * which preserves the compiler's canonical bytes.
     *
     * @param   mixed   $value    Candidate response registry.
     * @param   string  $message  Stable validation failure detail.
     *
     * @return  array<int|string, mixed>  Validated response registry.
     *
     * @throws  InvalidArgumentException  When the value is not a response registry.
     *
     * @since   2.0.0
     */
    private function responseRegistry(mixed $value, string $message): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException($message);
        }
        foreach (array_keys($value) as $status) {
            if (preg_match('/^[1-5][0-9]{2}$/D', (string) $status) !== 1) {
                throw new InvalidArgumentException($message);
            }
        }

        return $value;
    }

    /**
     * Build a standard Problem Details response.
     *
     * @param   string  $description  Status-specific summary.
     *
     * @return  array<string, mixed>  OpenAPI response object.
     *
     * @since   2.0.0
     */
    private function problem(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/problem+json' => [
                    'schema' => ['$ref' => '#/components/schemas/ProblemDetails'],
                ],
            ],
        ];
    }

    /**
     * Build one reusable path parameter.
     *
     * @param   string                $name      Parameter name.
     * @param   string                $in        Parameter location.
     * @param   bool                  $required  Required flag.
     * @param   array<string, mixed>  $schema    Bounded parameter schema.
     *
     * @return  array<string, mixed>  OpenAPI parameter object.
     *
     * @since   2.0.0
     */
    private function parameter(string $name, string $in, bool $required, array $schema): array
    {
        return ['name' => $name, 'in' => $in, 'required' => $required, 'schema' => $schema];
    }

    /**
     * Narrow a decoded or derived JSON object to a string-keyed PHP array.
     *
     * Empty JSON objects decode to an empty PHP array, so emptiness is accepted while any integer key is
     * rejected. The explicit key proof lets callers safely traverse nested OpenAPI objects without trusting
     * an unchecked PHPDoc cast.
     *
     * @param   mixed   $value    Candidate decoded object.
     * @param   string  $message  Stable validation failure detail.
     *
     * @return  array<string, mixed>  Validated string-keyed object representation.
     *
     * @throws  InvalidArgumentException  When the value is not an object representation.
     *
     * @since   2.0.0
     */
    private function objectArray(mixed $value, string $message): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException($message);
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException($message);
            }
        }
        /** @var array<string, mixed> $value */

        return $value;
    }

    /**
     * Prove operation identifiers are present and unique after assembly.
     *
     * @param   array<string, mixed>  $paths  Complete path registry.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  On a malformed operation or duplicate identifier.
     *
     * @since   2.0.0
     */
    private function validateOperations(array $paths): void
    {
        $seen = [];
        foreach ($paths as $path => $pathItem) {
            if (!is_string($path)) {
                throw new InvalidArgumentException('An OpenAPI path item is invalid.');
            }
            $pathItem = $this->objectArray($pathItem, 'An OpenAPI path item is invalid.');
            foreach (['get', 'put', 'post', 'patch', 'delete'] as $method) {
                if (!isset($pathItem[$method])) {
                    continue;
                }
                $operation = $this->objectArray(
                    $pathItem[$method],
                    'An OpenAPI operation is invalid.',
                );
                $operationId = $operation['operationId'] ?? null;
                if (!is_string($operationId) || $operationId === '' || isset($seen[$operationId])) {
                    throw new InvalidArgumentException('An OpenAPI operation identifier is missing or duplicated.');
                }
                $seen[$operationId] = true;
            }
        }
    }

    /**
     * Resolve every local component reference in the compiled document.
     *
     * @param   array<string, mixed>  $document  Complete OpenAPI document.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a local reference does not resolve.
     *
     * @since   2.0.0
     */
    private function validateReferences(array $document): void
    {
        $walk = function (mixed $value) use (&$walk, $document): void {
            if (!is_array($value)) {
                return;
            }
            if (isset($value['$ref']) && is_string($value['$ref']) && str_starts_with($value['$ref'], '#/')) {
                $node = $document;
                foreach (explode('/', substr($value['$ref'], 2)) as $segment) {
                    if (!is_array($node) || !array_key_exists($segment, $node)) {
                        throw new InvalidArgumentException('A generated OpenAPI reference does not resolve.');
                    }
                    $node = $node[$segment];
                }
            }
            foreach ($value as $member) {
                $walk($member);
            }
        };
        $walk($document);
    }
}
