<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\OpenApi\Application;

use InvalidArgumentException;
use Kumwe\CMS\OpenApi\Application\OpenApiContractCompiler;
use Kumwe\CMS\OpenApi\Application\OpenApiContractLimits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenApiContractCompiler::class)]
/**
 * Verifies deterministic, collision-safe generated business OpenAPI assembly.
 *
 * @since  2.0.0
 */
final class OpenApiContractCompilerTest extends TestCase
{
    /**
     * Declare the caller-bound operation-status route and its exact result alternatives.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeclaresTheCallerBoundOperationStatusPathAndExactProjectedResult(): void
    {
        $compiled = (new OpenApiContractCompiler())->compile($this->core(), [], str_repeat('a', 64));
        /** @var array<string, mixed> $document */
        $document = json_decode($compiled->json, true, 64, JSON_THROW_ON_ERROR);

        $path = $document['paths']['/api/v1/business/operations/{operation}'];
        self::assertSame('operation', $path['parameters'][0]['name']);
        self::assertSame(8, $path['parameters'][0]['schema']['minLength']);
        self::assertSame(128, $path['parameters'][0]['schema']['maxLength']);
        self::assertSame('businessOperationStatusRead', $path['get']['operationId']);
        self::assertSame(
            '#/components/schemas/GeneratedBusinessOperationStatus',
            $path['get']['responses']['200']['content']['application/json']['schema']['$ref'],
        );

        $status = $document['components']['schemas']['GeneratedBusinessOperationStatus'];
        self::assertFalse($status['additionalProperties']);
        self::assertSame(['completed'], $status['properties']['state']['enum']);
        self::assertContains('result', $status['required']);
        self::assertSame([
            ['$ref' => '#/components/schemas/GeneratedBusinessMutation'],
            ['$ref' => '#/components/schemas/GeneratedBusinessApprovalOperationResult'],
        ], $status['properties']['result']['oneOf']);
        $approvalResult = $document['components']['schemas']['GeneratedBusinessApprovalOperationResult'];
        self::assertArrayNotHasKey('definition_id', $approvalResult['properties']);
        self::assertArrayNotHasKey('record_key', $approvalResult['properties']);
        $mutation = $document['components']['schemas']['GeneratedBusinessMutation'];
        self::assertArrayNotHasKey('definition_id', $mutation['properties']);
        self::assertArrayNotHasKey('record_key', $mutation['properties']);
    }

    /**
     * Keep query, relationship, and history resources closed and bounded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testQueryAndRelationshipSchemasAreClosedAndBounded(): void
    {
        $compiled = (new OpenApiContractCompiler())->compile($this->core(), [], str_repeat('b', 64));
        /** @var array<string, mixed> $document */
        $document = json_decode($compiled->json, true, 64, JSON_THROW_ON_ERROR);
        $schemas = $document['components']['schemas'];

        $query = $schemas['GeneratedBusinessQuery'];
        self::assertFalse($query['additionalProperties']);
        self::assertSame(5, $query['properties']['sorts']['maxItems']);
        self::assertSame(4, $query['properties']['projection']['properties']['includes']['maxItems']);
        self::assertSame(16, $query['properties']['projection']['properties']['aggregates']['maxItems']);
        self::assertCount(6, $query['$defs']['Filter']['oneOf']);
        foreach ($query['$defs']['Filter']['oneOf'] as $node) {
            self::assertFalse($node['additionalProperties']);
        }

        $related = $schemas['GeneratedBusinessRelationRecord'];
        self::assertFalse($related['additionalProperties']);
        self::assertArrayNotHasKey('definition_id', $related['properties']);
        self::assertArrayNotHasKey('record_key', $related['properties']);
        self::assertSame(
            '#/components/schemas/GeneratedBusinessRelationRecord',
            $schemas['GeneratedBusinessRecord']['properties']['includes']['additionalProperties']['items']['$ref'],
        );
        $relationPath = $document['paths'][
            '/api/v1/business/records/{definition}/{record}/relations/{relation}'
        ];
        self::assertSame('businessRecordRelationRead', $relationPath['get']['operationId']);
        self::assertSame('businessRecordRelate', $relationPath['post']['operationId']);
        $revision = $schemas['GeneratedBusinessRevision'];
        self::assertFalse($revision['additionalProperties']);
        self::assertSame([
            'definition_version',
            'record_version',
            'revision_number',
            'operation',
            'snapshot',
            'changed_fields',
            'occurred_at',
        ], $revision['required']);
        self::assertSame(
            '#/components/schemas/GeneratedBusinessRevision',
            $schemas['GeneratedBusinessHistory']['properties']['items']['items']['$ref'],
        );
    }

    /**
     * Compile custom contracts into caller-visible components and fixed generic routes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompilesOnlySafeCustomContractSchemasAndRestParity(): void
    {
        $compiled = (new OpenApiContractCompiler())->compile(
            $this->core(),
            [$this->customDefinition()],
            str_repeat('d', 64),
        );
        /** @var array<string, mixed> $document */
        $document = json_decode($compiled->json, true, 64, JSON_THROW_ON_ERROR);
        $schemas = $document['components']['schemas'];
        $queryName = 'Business_acme_invoice_View_overdue_Query';
        $viewResultName = 'Business_acme_invoice_View_overdue_Result';
        $commandName = 'Business_acme_invoice_Action_send_Command';
        $actionResultName = 'Business_acme_invoice_Action_send_Result';

        self::assertSame(['currency'], $schemas[$queryName]['required']);
        self::assertSame(['count'], $schemas[$viewResultName]['required']);
        self::assertSame(['channel'], $schemas[$commandName]['required']);
        self::assertSame(['receipt'], $schemas[$actionResultName]['required']);
        self::assertSame(
            ['$ref' => '#/components/schemas/' . $commandName],
            $schemas['GeneratedBusinessAction']['properties']['input']['anyOf'][1],
        );
        self::assertSame(
            ['$ref' => '#/components/schemas/' . $commandName],
            $schemas['GeneratedBusinessActionApproval']['properties']['input']['anyOf'][1],
        );
        self::assertSame(
            ['$ref' => '#/components/schemas/' . $actionResultName],
            $schemas['GeneratedBusinessMutation']['properties']['result']['anyOf'][0],
        );
        self::assertContains('custom', $schemas['GeneratedBusinessViewMetadata']['required']);
        self::assertSame(['type' => 'boolean'], $schemas['GeneratedBusinessViewMetadata']['properties']['custom']);
        self::assertArrayNotHasKey('handler', $schemas[$queryName]);
        self::assertArrayNotHasKey('schema', $schemas[$queryName]);

        $collectionView = $document['paths']['/api/v1/business/views/{definition}/{view}']['post'];
        self::assertSame('businessRecordCustomView', $collectionView['operationId']);
        self::assertFalse($collectionView['requestBody']['required']);
        self::assertSame(
            '#/components/schemas/GeneratedBusinessCustomViewResponse',
            $collectionView['responses']['200']['content']['application/json']['schema']['$ref'],
        );
        $recordView = $document['paths'][
            '/api/v1/business/views/{definition}/{record}/{view}'
        ]['post'];
        self::assertSame('businessRecordCustomRecordView', $recordView['operationId']);
        $approval = $document['paths'][
            '/api/v1/business/records/{definition}/{record}/actions/{action}/approval'
        ]['post'];
        self::assertArrayHasKey('200', $approval['responses']);
        self::assertArrayHasKey('201', $approval['responses']);
        self::assertFalse($approval['requestBody']['required']);
        self::assertSame(
            '#/components/schemas/GeneratedBusinessActionApproval',
            $approval['requestBody']['content']['application/json']['schema']['$ref'],
        );

        $browse = $document['paths']['/api/v1/business/records/{definition}']['get'];
        self::assertContains('projection', array_column($browse['parameters'], 'name'));
        self::assertContains('page_size', array_column($browse['parameters'], 'name'));
        $read = $document['paths']['/api/v1/business/records/{definition}/{record}']['get'];
        self::assertContains('include_deleted', array_column($read['parameters'], 'name'));
        $history = $document['paths'][
            '/api/v1/business/records/{definition}/{record}/history'
        ]['get'];
        self::assertSame(['limit', 'before_version'], array_column($history['parameters'], 'name'));
        self::assertSame(
            '#/components/schemas/GeneratedBusinessDefinitionCollection',
            $document['paths']['/api/v1/business/definitions']['get']['responses']['200']
                ['content']['application/json']['schema']['$ref'],
        );
    }

    /**
     * Reject unsafe custom schemas even when malformed metadata bypasses the catalog boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsUnsafeCustomContractMetadata(): void
    {
        $definition = $this->customDefinition();
        $definition['views'][0]['custom_contract']['query_schema'] = [
            '$ref' => 'https://attacker.test/schema.json',
        ];

        $this->expectException(InvalidArgumentException::class);
        (new OpenApiContractCompiler())->compile($this->core(), [$definition], str_repeat('e', 64));
    }

    /**
     * Recompile a generated artifact to byte-identical canonical output.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCheckedInGeneratedArtifactCanBeRecompiledByteForByte(): void
    {
        $compiler = new OpenApiContractCompiler();
        $generation = str_repeat('c', 64);
        $first = $compiler->compile($this->core(), [], $generation);
        /** @var array<string, mixed> $prior */
        $prior = json_decode($first->json, true, 64, JSON_THROW_ON_ERROR);
        $second = $compiler->compile($prior, [], $generation);

        self::assertSame($first->json, $second->json);
        self::assertSame($first->checksum, $second->checksum);
    }

    /**
     * Preserve deterministic regeneration when a compiler-owned route family changes between releases.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedPathMarkersMigrateLegacyArtifactsAcrossRouteChanges(): void
    {
        $compiler = new OpenApiContractCompiler();
        $generation = str_repeat('f', 64);
        $current = $compiler->compile($this->core(), [], $generation);
        /** @var array<string, mixed> $prior */
        $prior = json_decode($current->json, true, 64, JSON_THROW_ON_ERROR);
        $newPath = '/api/v1/business/views/{definition}/{view}';
        $oldPath = '/api/v1/business/records/{definition}/views/{view}';
        self::assertContains($newPath, $prior['x-kumwe-generated-paths']);

        unset($prior['x-kumwe-generated-paths']);
        $prior['paths'][$oldPath] = $prior['paths'][$newPath];
        unset($prior['paths'][$newPath]);
        $migrated = $compiler->compile($prior, [], $generation);
        /** @var array<string, mixed> $document */
        $document = json_decode($migrated->json, true, 64, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey($oldPath, $document['paths']);
        self::assertArrayHasKey($newPath, $document['paths']);
        self::assertContains($newPath, $document['x-kumwe-generated-paths']);
    }

    /**
     * Reject malformed and over-wide metadata before generating any component families.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsMalformedAndOverWideDefinitionCollectionsAtEntry(): void
    {
        $compiler = new OpenApiContractCompiler();
        $generation = str_repeat('1', 64);

        try {
            $compiler->compile($this->core(), ['named' => $this->customDefinition()], $generation);
            self::fail('A non-list definition collection was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Generated OpenAPI definitions are invalid or unbounded.', $exception->getMessage());
        }

        $definitions = array_fill(
            0,
            OpenApiContractLimits::MAX_DEFINITIONS + 1,
            $this->customDefinition(),
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Generated OpenAPI definitions are invalid or unbounded.');
        $compiler->compile($this->core(), $definitions, $generation);
    }

    /**
     * Reject encoded metadata that exceeds the pre-expansion memory budget.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsOversizedDefinitionMetadataBeforeCompilation(): void
    {
        $definition = $this->customDefinition();
        $definition['padding'] = str_repeat('x', OpenApiContractLimits::MAX_DEFINITION_INPUT_BYTES);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Generated OpenAPI definition metadata exceeds its safe byte bound.');
        (new OpenApiContractCompiler())->compile($this->core(), [$definition], str_repeat('2', 64));
    }

    /**
     * Refuse canonical bytes that cannot fit the shared verified-contract budget.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsOversizedCanonicalContractBeforeConstruction(): void
    {
        $core = $this->core();
        $core['info']['description'] = str_repeat('x', OpenApiContractLimits::MAX_CONTRACT_BYTES);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The generated OpenAPI contract exceeds its safe byte bound.');
        (new OpenApiContractCompiler())->compile($core, [], str_repeat('3', 64));
    }

    /**
     * Publish report execution and the complete durable export lifecycle as bounded deterministic operations.
     *
     * @since  2.0.0
     */
    public function testDeclaresBusinessReportAndExportDeliveryContracts(): void
    {
        $compiled = (new OpenApiContractCompiler())->compile($this->core(), [], str_repeat('4', 64));
        /** @var array<string, mixed> $document */
        $document = json_decode($compiled->json, true, 64, JSON_THROW_ON_ERROR);

        self::assertSame(
            'businessReportList',
            $document['paths']['/api/v1/business/reports']['get']['operationId'],
        );
        self::assertSame(
            'businessReportExecute',
            $document['paths']['/api/v1/business/reports/{report}']['post']['operationId'],
        );
        $request = $document['paths']['/api/v1/business/reports/{report}/exports']['post'];
        self::assertSame('businessReportExportRequest', $request['operationId']);
        self::assertSame('Idempotency-Key', $request['parameters'][0]['name']);
        self::assertArrayNotHasKey('If-Match', array_column($request['parameters'], null, 'name'));
        self::assertSame(
            'businessReportExportStatus',
            $document['paths']['/api/v1/business/report-exports/{artifact}']['get']['operationId'],
        );
        $download = $document['paths']['/api/v1/business/report-exports/{artifact}/download']['get'];
        self::assertSame('businessReportExportDownload', $download['operationId']);
        self::assertSame(
            'binary',
            $download['responses']['200']['content']['text/csv']['schema']['format'],
        );
        self::assertFalse($document['components']['schemas']['GeneratedBusinessReportResult']['additionalProperties']);
        self::assertSame(
            1000,
            $document['components']['schemas']['GeneratedBusinessReportResult']['properties']['rows']['maxItems'],
        );
        self::assertSame(
            '#/components/schemas/GeneratedBusinessReportDrillDown',
            $document['components']['schemas']['GeneratedBusinessReportResult']['properties']['drill_downs']
                ['items']['items']['$ref'],
        );
        self::assertSame(
            '^/api/v1/business/views/',
            $document['components']['schemas']['GeneratedBusinessReportDrillDown']['properties']['url']['pattern'],
        );
    }

    /**
     * Return the smallest valid checked-in core contract shape needed by the compiler.
     *
     * @return  array<string, mixed>  OpenAPI 3.1 core document.
     *
     * @since   2.0.0
     */
    private function core(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Kumwe test API', 'version' => '1.0.0'],
            'paths' => [
                '/health' => [
                    'get' => [
                        'operationId' => 'healthRead',
                        'responses' => ['200' => ['description' => 'Healthy.']],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'ProblemDetails' => [
                        'type' => 'object',
                        'required' => ['type', 'title', 'status', 'detail'],
                        'properties' => [
                            'type' => ['type' => 'string', 'format' => 'uri-reference'],
                            'title' => ['type' => 'string'],
                            'status' => ['type' => 'integer'],
                            'detail' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Return one minimal safe catalog document carrying a view and action custom contract.
     *
     * @return  array<string, mixed>  Definition metadata accepted by the compiler.
     *
     * @since   2.0.0
     */
    private function customDefinition(): array
    {
        $string = ['type' => 'string', 'maxLength' => 32];

        return [
            'handle' => 'acme.invoice',
            'fields' => [],
            'views' => [[
                'handle' => 'overdue',
                'custom_contract' => [
                    'query_schema' => $this->objectSchema('currency', $string),
                    'result_schema' => $this->objectSchema('count', ['type' => 'integer', 'minimum' => 0]),
                ],
            ]],
            'actions' => [[
                'handle' => 'send',
                'custom_contract' => [
                    'command_schema' => $this->objectSchema('channel', $string),
                    'result_schema' => $this->objectSchema('receipt', $string),
                ],
            ]],
        ];
    }

    /**
     * A read schema admits a converted amount beside the stored pair; a write schema admits only the pair.
     *
     * The asymmetry is the contract's way of saying what the write path already enforces: conversion is a
     * presentation of a stored amount and is never stored itself. A client generated from this document
     * therefore refuses a converted figure as an input in the same place the server does.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMoneyFieldReadsAsEitherAStoredOrAConvertedAmountAndWritesOnlyStored(): void
    {
        $compiled = (new OpenApiContractCompiler())->compile(
            $this->core(),
            [$this->moneyDefinition()],
            str_repeat('f', 64),
        );
        /** @var array<string, mixed> $document */
        $document = json_decode($compiled->json, true, 64, JSON_THROW_ON_ERROR);
        $schemas = $document['components']['schemas'];
        $reference = ['$ref' => '#/components/schemas/GeneratedBusinessConvertedMoney'];

        $read = $schemas['Business_acme_receipt_Record']['properties']['total'];
        self::assertArrayHasKey('oneOf', $read);
        self::assertEquals($this->storedMoneySchema(), $read['oneOf'][0]);
        self::assertEquals($reference, $read['oneOf'][1]);

        foreach (['Business_acme_receipt_Create', 'Business_acme_receipt_Update'] as $write) {
            $written = $schemas[$write]['properties']['total'];
            self::assertArrayNotHasKey('oneOf', $written, $write . ' admits a converted amount as input.');
            self::assertEquals($this->storedMoneySchema(), $written);
        }
    }

    /**
     * A read-only money field keeps its marker on the union rather than losing it to a branch.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAReadOnlyMoneyFieldCarriesItsMarkerOnTheUnion(): void
    {
        $definition = $this->moneyDefinition();
        $definition['fields'][0]['schema']['readOnly'] = true;
        $definition['fields'][0]['uses']['create'] = false;
        $definition['fields'][0]['uses']['update'] = false;
        $compiled = (new OpenApiContractCompiler())->compile(
            $this->core(),
            [$definition],
            str_repeat('9', 64),
        );
        /** @var array<string, mixed> $document */
        $document = json_decode($compiled->json, true, 64, JSON_THROW_ON_ERROR);
        $read = $document['components']['schemas']['Business_acme_receipt_Record']['properties']['total'];

        self::assertTrue($read['readOnly']);
        self::assertArrayHasKey('oneOf', $read);
    }

    /**
     * Build a definition carrying one money field visible to read, create and update.
     *
     * @return  array<string, mixed>  Safe catalog metadata for a single-field definition.
     *
     * @since   2.0.0
     */
    private function moneyDefinition(): array
    {
        return [
            'handle' => 'acme.receipt',
            'fields' => [[
                'handle' => 'total',
                'type' => 'core.money',
                'required' => false,
                'schema' => $this->storedMoneySchema(),
                'uses' => [
                    'detail' => true,
                    'create' => true,
                    'update' => true,
                    'list' => false,
                    'filter' => false,
                    'search' => false,
                    'sort' => false,
                    'report' => false,
                    'export' => false,
                ],
            ]],
            'views' => [],
            'actions' => [],
        ];
    }

    /**
     * Build the closed amount-and-currency schema the catalog produces for a money field.
     *
     * @return  array<string, mixed>  Stored money schema.
     *
     * @since   2.0.0
     */
    private function storedMoneySchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['amount', 'currency'],
            'properties' => [
                'amount' => ['type' => 'string', 'pattern' => '^-?[0-9]+(?:\\.[0-9]+)?$'],
                'currency' => ['type' => 'string', 'maxLength' => 32],
            ],
        ];
    }

    /**
     * Build one closed single-property custom contract schema.
     *
     * @param   string                $name    Required property name.
     * @param   array<string, mixed>  $schema  Bounded property schema.
     *
     * @return  array<string, mixed>  Closed custom object schema.
     *
     * @since   2.0.0
     */
    private function objectSchema(string $name, array $schema): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [$name],
            'properties' => [$name => $schema],
        ];
    }
}
