<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRelationView;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordWriteRepository;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Record\Query\RecordProjection;
use Kumwe\Record\Query\RecordQuerySpecification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves that a link or owned line relating without a position is appended after the highest stored one.
 *
 * Every other relationship scenario names its positions explicitly, so this is the path an operator takes
 * when they simply add another item: the write repository reads the owner's highest stored position from
 * the committed version it has locked and places the new row one past it. An empty collection starts at
 * zero, and an explicitly sparse position is respected rather than renumbered, since contiguous numbering is
 * only ever restored by a reorder.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineBusinessRecordWriteRepository::class)]
#[CoversClass(BusinessRecordService::class)]
final class BusinessRecordAppendPositionIntegrationTest extends TestCase
{
    /**
     * Ordered links and owned lines without a requested position append one past the highest stored slot.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnpositionedLinksAndOwnedLinesAppendAfterTheHighestStoredPosition(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $line = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString()),
        );
        $owner = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationshipOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
                $line->handle,
            ),
        );
        $targetIds = [];
        foreach (['first', 'sparse', 'appended'] as $label) {
            $targetId = Uuid::uuid7()->toString();
            $records->create(new CreateRecordCommand(
                $context,
                $target->handle,
                ['label' => 'Append ' . $label],
                NeutralBusinessFixture::idempotencyKey('append-target-' . $label . '-' . $suffix),
                recordId: $targetId,
            ));
            $targetIds[$label] = $targetId;
        }
        $ownerId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $owner->handle,
            ['title' => 'Append owner'],
            NeutralBusinessFixture::idempotencyKey('append-owner-' . $suffix),
            recordId: $ownerId,
        ));

        $version = 1;
        foreach (['first' => null, 'sparse' => 5, 'appended' => null] as $label => $position) {
            $version = $records->relate(new RelateRecordsCommand(
                $context,
                $owner->handle,
                $ownerId,
                $version,
                'tags',
                $targetIds[$label],
                NeutralBusinessFixture::idempotencyKey('append-tag-' . $label . '-' . $suffix),
                $position,
            ))->version;
        }
        $lineIds = [Uuid::uuid7()->toString(), Uuid::uuid7()->toString()];
        foreach ($lineIds as $offset => $lineId) {
            $version = $records->relate(new RelateRecordsCommand(
                $context,
                $owner->handle,
                $ownerId,
                $version,
                'lines',
                $lineId,
                NeutralBusinessFixture::idempotencyKey('append-line-' . $offset . '-' . $suffix),
                targetValues: [
                    'description' => 'Appended line ' . ($offset + 1),
                    'units' => ($offset + 1) . '.000',
                ],
            ))->version;
        }
        self::assertSame(6, $version, 'Each append re-versions the owner exactly once.');

        $includes = self::includes($records, $context, $owner->handle, ['tags', 'lines']);
        self::assertSame(
            [$targetIds['first'] => 0, $targetIds['sparse'] => 5, $targetIds['appended'] => 6],
            self::positions($includes['tags']),
            'An unpositioned link starts an empty collection at zero and otherwise follows the highest slot.',
        );
        self::assertSame(
            [$lineIds[0] => 0, $lineIds[1] => 1],
            self::positions($includes['lines']),
            'Owned lines append in the order they were added.',
        );
    }

    /**
     * An append that cannot lock the collection it measures is refused, and nothing of it is kept.
     *
     * A peer transaction holds the owner's existing links. Guessing the next position from an older snapshot
     * could hand two writers the same slot, so the relate is refused as temporarily unavailable once the
     * engine's lock wait expires; after the peer lets go, the same append lands one past the stored links.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAppendThatCannotLockItsCollectionIsRefusedAndLeavesTheOwnerUnchanged(): void
    {
        $environment = Environment::fromGlobals();
        $container = TestKernelFactory::create($environment);
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $resolver = $container->get(BusinessRecordDefinitionResolver::class);
        $database = $container->get(Connection::class);
        $peer = TestKernelFactory::create($environment)->get(Connection::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessRecordDefinitionResolver::class, $resolver);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(Connection::class, $peer);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $target = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $line = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString()),
        );
        $owner = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::relationshipOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
                $line->handle,
            ),
        );
        $targetIds = [Uuid::uuid7()->toString(), Uuid::uuid7()->toString()];
        foreach ($targetIds as $offset => $targetId) {
            $records->create(new CreateRecordCommand(
                $context,
                $target->handle,
                ['label' => 'Locked append ' . $offset],
                NeutralBusinessFixture::idempotencyKey('locked-target-' . $offset . '-' . $suffix),
                recordId: $targetId,
            ));
        }
        $ownerId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $owner->handle,
            ['title' => 'Locked append owner'],
            NeutralBusinessFixture::idempotencyKey('locked-owner-' . $suffix),
            recordId: $ownerId,
        ));
        $relate = fn (string $targetId, int $version, string $key) => $records->relate(new RelateRecordsCommand(
            $context,
            $owner->handle,
            $ownerId,
            $version,
            'tags',
            $targetId,
            NeutralBusinessFixture::idempotencyKey($key . '-' . $suffix),
        ));
        self::assertSame(2, $relate($targetIds[0], 1, 'locked-first')->version);
        $junction = $resolver->forCreate($context, $owner->handle)->installation->blueprint->table('relation:tags');
        self::assertNotNull($junction);
        $source = $junction->column('source_id');
        self::assertNotNull($source);
        $mysql = $database->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $database->executeStatement($mysql ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1s'");

        $peer->beginTransaction();
        try {
            $held = $peer->fetchFirstColumn(sprintf(
                'SELECT %s FROM %s WHERE %s = ? FOR UPDATE',
                $peer->quoteSingleIdentifier($source->physicalName),
                $peer->quoteSingleIdentifier($junction->physicalName),
                $peer->quoteSingleIdentifier($source->physicalName),
            ), [$ownerId]);
            self::assertCount(1, $held, 'The peer holds the owner\'s one stored link.');
            try {
                $relate($targetIds[1], 2, 'locked-blocked');
                self::fail('An append whose collection is locked must not guess a position.');
            } catch (BusinessRecordTemporarilyUnavailable) {
                self::assertFalse($database->isTransactionActive(), 'The refused relate rolled back.');
            }
        } finally {
            $peer->rollBack();
            $database->executeStatement($mysql ? 'SET SESSION innodb_lock_wait_timeout = 50' : 'SET lock_timeout = 0');
        }

        self::assertSame(3, $relate($targetIds[1], 2, 'locked-retried')->version, 'The owner kept version two.');
        self::assertSame(
            [$targetIds[0] => 0, $targetIds[1] => 1],
            self::positions(self::includes($records, $context, $owner->handle, ['tags'])['tags']),
        );
    }

    /**
     * Read the owner's related collections through the record service.
     *
     * @param   BusinessRecordService  $records     Record facade.
     * @param   ExecutionContext       $context     Administrator.
     * @param   string                 $definition  Owner definition handle; the only record it holds is read.
     * @param   list<string>           $includes    Relationship handles to include.
     *
     * @return  array<string, list<BusinessRecordRelationView>>  Related rows by relationship handle.
     *
     * @since   2.0.0
     */
    private static function includes(
        BusinessRecordService $records,
        ExecutionContext $context,
        string $definition,
        array $includes,
    ): array {
        $result = $records->browse(new BrowseRecordsQuery(
            $context,
            $definition,
            new RecordQuerySpecification(pageSize: 1, projection: new RecordProjection(['title'], $includes)),
        ));
        self::assertCount(1, $result->records);

        return $result->records[0]->includes;
    }

    /**
     * Map related rows to their stored positions.
     *
     * @param   list<BusinessRecordRelationView>  $related  Related rows in collection order.
     *
     * @return  array<string, ?int>  Position by related record identity.
     *
     * @since   2.0.0
     */
    private static function positions(array $related): array
    {
        $positions = [];
        foreach ($related as $view) {
            $positions[$view->recordId] = $view->position;
        }

        return $positions;
    }
}
