<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Joomla\DI\Container;
use Kumwe\CMS\Application\Automation\IdempotencyKey;
use Kumwe\CMS\BusinessDefinition\Domain\NumberSequenceFormat;
use Kumwe\CMS\BusinessDefinition\Domain\NumberSequenceScope;
use Kumwe\CMS\BusinessRecord\Application\BusinessNumberSequenceAllocator;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordService;
use Kumwe\CMS\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\CMS\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessNumberSequenceAllocator;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\NeutralBusinessFixture;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Pins where document type and legal entity live in the counter identity, on the unchanged allocator.
 *
 * The `V2-ERP-002` widening resolves to an already-present identity: a counter is the five-coordinate
 * tuple of site, definition, field handle, scope key and period key, so the document type (definition and
 * field) and the legal entity (site, with the organization branch in the scope key) partition counters
 * without any change to how the counter advances. This suite proves that partition mechanically — every
 * coordinate isolates its own contiguous run while the neighbouring runs stand still — first at the
 * allocator seam and then through the real create command, where two allocated-number fields on one
 * definition draw from runs exclusively their own.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineBusinessNumberSequenceAllocator::class)]
#[CoversClass(NumberSequenceFormat::class)]
#[CoversClass(NumberSequenceScope::class)]
final class BusinessNumberSequenceIdentityIntegrationTest extends TestCase
{
    /**
     * Every identity coordinate isolates its own contiguous run on the one shared counter table.
     *
     * The first run is advanced twice, each neighbouring coordinate — another document type, another
     * field handle, another legal entity's site, two organization branches, another period — starts its
     * own run at one, and the first run then continues at three: nothing any neighbour allocated
     * interleaved with it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEachIdentityCoordinateIsolatesItsOwnContiguousRun(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $allocator = $this->allocator($container);
        $database = $this->connection($container);
        $invoice = Uuid::uuid7()->toString();
        $credit = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable('2026-08-18T09:00:00', new DateTimeZone('UTC'));

        $database->beginTransaction();
        try {
            self::assertSame(1, $allocator->allocate('default', $invoice, 'document_number', '-', '2026', $now));
            self::assertSame(2, $allocator->allocate('default', $invoice, 'document_number', '-', '2026', $now));
            self::assertSame(
                1,
                $allocator->allocate('default', $credit, 'document_number', '-', '2026', $now),
                'Another definition is another document type and draws from its own counter.',
            );
            self::assertSame(
                1,
                $allocator->allocate('default', $invoice, 'voucher_number', '-', '2026', $now),
                'Another allocated-number field of the same definition keeps its own run.',
            );
            self::assertSame(
                1,
                $allocator->allocate('entity-b', $invoice, 'document_number', '-', '2026', $now),
                'Another site is another legal entity and never interleaves with the first.',
            );
            self::assertSame(
                1,
                $allocator->allocate('default', $invoice, 'document_number', 'north-branch', '2026', $now),
                'A per-organization scope key gives each branch its own run.',
            );
            self::assertSame(
                1,
                $allocator->allocate('default', $invoice, 'document_number', 'south-branch', '2026', $now),
            );
            self::assertSame(
                1,
                $allocator->allocate('default', $invoice, 'document_number', '-', '2027', $now),
                'A new period starts a new run without touching the old one.',
            );
            self::assertSame(
                3,
                $allocator->allocate('default', $invoice, 'document_number', '-', '2026', $now),
                'The original run continues contiguously; no neighbouring counter consumed from it.',
            );
            $database->commit();
        } catch (\Throwable $failure) {
            $database->rollBack();
            throw $failure;
        }

        self::assertSame(1, $this->stored($container, $invoice, 'document_number', '-', '2027'));
        self::assertSame(3, $this->stored($container, $invoice, 'document_number', '-', '2026'));
        self::assertSame(1, $this->stored($container, $credit, 'document_number', '-', '2026'));
        self::assertSame(1, $this->stored($container, $invoice, 'document_number', 'north-branch', '2026'));
    }

    /**
     * Two allocated-number fields on one published definition keep independent runs through real creates.
     *
     * Each created record carries both numbers, and each field's run is contiguous from one under its own
     * declared scope, reset and format — the create-path form of the same identity partition the allocator
     * seam proves above.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTwoSequenceFieldsOnOnePublishedDefinitionKeepIndependentRuns(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->records($container);

        $document = NeutralBusinessFixture::document($this->suffix(), Uuid::uuid7()->toString());
        $document['fields'][] = $this->sequenceField('document_number', 'yearly', 'SEQ-', 4);
        $document['fields'][] = $this->sequenceField('voucher_number', 'monthly', 'VCH-', 3);
        $definition = NeutralBusinessFixture::install($container, $context, $document);

        $year = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y');
        $month = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m');
        for ($index = 1; $index <= 2; ++$index) {
            $recordId = Uuid::uuid7()->toString();
            $records->create(new CreateRecordCommand(
                $context,
                $definition->id,
                NeutralBusinessFixture::recordValues(sprintf('Identity run %d', $index)),
                IdempotencyKey::fromString('sequence-identity-' . Uuid::uuid7()->toString()),
                recordId: $recordId,
            ));
            $view = $records->read(new ReadRecordQuery($context, $definition->id, $recordId));
            self::assertSame(sprintf('SEQ-%s-%04d', $year, $index), $view->values['document_number'] ?? null);
            self::assertSame(sprintf('VCH-%s-%03d', $month, $index), $view->values['voucher_number'] ?? null);
        }
        self::assertSame(2, $this->stored($container, $definition->id, 'document_number', '-', $year));
        self::assertSame(2, $this->stored($container, $definition->id, 'voucher_number', '-', $month));
    }

    /**
     * Declare one closed allocated-number field for the fixture document.
     *
     * @param   string  $handle   Field handle the run is allocated under.
     * @param   string  $reset    Reset period the sequence declares.
     * @param   string  $prefix   Literal head of the rendered number.
     * @param   int     $padding  Digits the counter is padded to.
     *
     * @return  array<string, mixed>  A closed, required, unique `core.sequence` field declaration.
     *
     * @since   2.0.0
     */
    private function sequenceField(string $handle, string $reset, string $prefix, int $padding): array
    {
        return [
            'handle' => $handle,
            'label' => ucfirst(str_replace('_', ' ', $handle)),
            'type' => 'core.sequence',
            'configuration' => [
                'scope' => 'site',
                'reset' => $reset,
                'prefix' => $prefix,
                'padding' => $padding,
                'timezone' => 'UTC',
            ],
            'required' => true,
            'nullable' => false,
            'length' => 36,
            'unique' => true,
            'indexed' => true,
            'immutable_after_create' => true,
            'server_only' => true,
            'read_only' => true,
            'create_visible' => false,
            'update_visible' => false,
            'sortable' => true,
            'filterable' => true,
        ];
    }

    /**
     * Read the committed value one counter identity stands at, or zero when it has no row.
     *
     * @param   Container  $container     Integration container holding the connection and table map.
     * @param   string     $definitionId  Definition coordinate of the counter.
     * @param   string     $fieldHandle   Field-handle coordinate of the counter.
     * @param   string     $scopeKey      Scope-key coordinate of the counter.
     * @param   string     $periodKey     Period-key coordinate of the counter.
     *
     * @return  int  The stored `current_value`, or zero when the identity names no row yet.
     *
     * @since   2.0.0
     */
    private function stored(
        Container $container,
        string $definitionId,
        string $fieldHandle,
        string $scopeKey,
        string $periodKey,
    ): int {
        $stored = $this->connection($container)->fetchOne(sprintf(
            'SELECT current_value FROM %s WHERE definition_id = ? AND field_handle = ? '
            . 'AND scope_key = ? AND period_key = ?',
            $this->tables($container)->quoted('business_number_sequences'),
        ), [$definitionId, $fieldHandle, $scopeKey, $periodKey]);

        return $stored === false ? 0 : (int) $stored;
    }

    /**
     * Mint a fresh fixture suffix so each run installs its own definition and its own empty counters.
     *
     * @return  string  Twelve lowercase characters unique to this run.
     *
     * @since   2.0.0
     */
    private function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
    }

    /**
     * Resolve the allocator under test from the integration container.
     *
     * @param   Container  $container  Integration container.
     *
     * @return  BusinessNumberSequenceAllocator  The installed allocator.
     *
     * @since   2.0.0
     */
    private function allocator(Container $container): BusinessNumberSequenceAllocator
    {
        $allocator = $container->get(BusinessNumberSequenceAllocator::class);
        if (!$allocator instanceof BusinessNumberSequenceAllocator) {
            throw new RuntimeException('The business number sequence allocator is unavailable.');
        }

        return $allocator;
    }

    /**
     * Resolve the record service from the integration container.
     *
     * @param   Container  $container  Integration container.
     *
     * @return  BusinessRecordService  The installed record service.
     *
     * @since   2.0.0
     */
    private function records(Container $container): BusinessRecordService
    {
        $records = $container->get(BusinessRecordService::class);
        if (!$records instanceof BusinessRecordService) {
            throw new RuntimeException('The business record service is unavailable.');
        }

        return $records;
    }

    /**
     * Resolve the live integration connection from the container.
     *
     * @param   Container  $container  Integration container.
     *
     * @return  Connection  The installation's connection.
     *
     * @since   2.0.0
     */
    private function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new RuntimeException('The integration connection is unavailable.');
        }

        return $connection;
    }

    /**
     * Resolve the prefixed table-name map from the container.
     *
     * @param   Container  $container  Integration container.
     *
     * @return  TableNames  The installation's table map.
     *
     * @since   2.0.0
     */
    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        if (!$tables instanceof TableNames) {
            throw new RuntimeException('The integration table map is unavailable.');
        }

        return $tables;
    }
}
