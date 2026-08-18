<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessDefinition;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(BusinessDefinitionService::class)]
#[CoversClass(EntityTypeDefinition::class)]
#[CoversClass(CanonicalDefinitionJson::class)]
final class BusinessDefinitionRuntimeIntegrationTest extends TestCase
{
    public function testGraphicalRuntimePublicationIsImmutablePortableAndRejectsInvalidImports(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $service = $container->get(BusinessDefinitionService::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(BusinessDefinitionService::class, $service);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $context = TestKernelFactory::administratorContext($container);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 10));
        $document = self::document($suffix);
        $physicalTablesBeforePublication = $database->createSchemaManager()->listTableNames();
        sort($physicalTablesBeforePublication, SORT_STRING);

        $draft = $service->saveDraft($context, EntityTypeDefinition::fromArray($document));
        self::assertSame(1, $draft->revision);
        $plan = $service->compareDraft($context, $draft->definition->id);
        self::assertSame(1, $plan->toVersion);
        $published = $service->publish($context, $draft->definition->id, $draft->revision);
        self::assertSame($plan->toChecksum, $published->definition->checksum());
        $storedGraph = $database->fetchOne(sprintf(
            'SELECT dependency_graph FROM %s WHERE definition_id = ? AND version = 1',
            $tables->quoted('business_definition_versions'),
        ), [$published->definition->id]);
        if (is_string($storedGraph)) {
            $storedGraph = json_decode($storedGraph, true, 32, JSON_THROW_ON_ERROR);
        }
        self::assertSame(
            CanonicalDefinitionJson::encode($published->definition->dependencyGraph()),
            CanonicalDefinitionJson::encode($storedGraph),
        );
        self::assertCount(1, $service->history($context, $published->definition->id));
        $physicalTablesAfterPublication = $database->createSchemaManager()->listTableNames();
        sort($physicalTablesAfterPublication, SORT_STRING);
        self::assertSame(
            $physicalTablesBeforePublication,
            $physicalTablesAfterPublication,
            'Definition publication may persist a proposed plan but must never execute DDL.',
        );

        $portable = $published->definition->toArray();
        $portable['id'] = Uuid::uuid7()->toString();
        $portable['handle'] .= '.portable';
        $imported = $service->importDraft($context, $portable);
        self::assertSame(0, $imported->definition->definitionVersion);
        self::assertSame('draft', $imported->definition->status->value);
        self::assertSame(1, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE action = ? AND subject_id = ? AND outcome = ?',
            $tables->quoted('audit_events'),
        ), ['business_definition.import', $imported->definition->id, 'success']));

        $invalid = $document;
        $invalid['id'] = Uuid::uuid7()->toString();
        $invalid['handle'] .= '.invalid';
        $invalid['fields'][1]['type'] = 'core.missing';
        $before = (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE site_identifier = ?',
            $tables->quoted('business_definitions'),
        ), ['default']);
        $rejectionsBefore = (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE action = ? AND outcome = ?',
            $tables->quoted('audit_events'),
        ), ['business_definition.import.reject', 'rejected']);
        try {
            $service->importDraft($context, $invalid);
            self::fail('An unknown field type must be rejected.');
        } catch (InvalidBusinessDefinition) {
            self::assertSame($before, (int) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE site_identifier = ?',
                $tables->quoted('business_definitions'),
            ), ['default']));
        }
        try {
            $service->importJson($context, '{not-json');
            self::fail('Malformed import JSON must be rejected.');
        } catch (InvalidBusinessDefinition) {
            self::assertSame($before, (int) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE site_identifier = ?',
                $tables->quoted('business_definitions'),
            ), ['default']));
        }
        self::assertSame($rejectionsBefore + 2, (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE action = ? AND outcome = ?',
            $tables->quoted('audit_events'),
        ), ['business_definition.import.reject', 'rejected']));
    }

    /** @return array<string, mixed> */
    private static function document(string $suffix): array
    {
        return [
            'id' => Uuid::uuid7()->toString(),
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.runtime_' . $suffix,
            'singular_label' => 'Runtime sample',
            'plural_label' => 'Runtime samples',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                [
                    'handle' => 'id',
                    'label' => 'ID',
                    'type' => 'core.uuid',
                    'required' => true,
                    'nullable' => false,
                    'unique' => true,
                    'indexed' => true,
                    'immutable_after_create' => true,
                ],
                [
                    'handle' => 'amount',
                    'label' => 'Amount',
                    'type' => 'core.decimal',
                    'precision' => 30,
                    'scale' => 6,
                    'filterable' => true,
                    'sortable' => true,
                ],
            ],
            'relationships' => [],
            'views' => [[
                'handle' => 'list',
                'label' => 'Samples',
                'kind' => 'list',
                'fields' => ['amount'],
                'filters' => ['amount'],
                'sorts' => ['amount'],
            ]],
            'actions' => [],
            'workflow' => null,
            'administrator_exposure' => true,
        ];
    }
}
