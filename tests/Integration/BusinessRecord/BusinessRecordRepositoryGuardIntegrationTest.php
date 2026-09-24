<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordReadRepository;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\BusinessRecordWriteRepository;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRelationshipRejected;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordReadRepository;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordWriteRepository;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Record\Model\BusinessRecord;
use Kumwe\Record\Model\RecordScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves the record repositories' own guards on the configured engine, beneath the service that normally
 * keeps callers inside them.
 *
 * The record service locks the aggregate and passes the version it read, so its callers never reach these
 * refusals; the repositories still enforce them because they are the last line before the rows. The
 * owned-line read that document integrity is judged against must see the committed collection behind the
 * owner's row lock: it runs only inside the writing transaction, only for an owned-line collection, and only
 * against the owner's own line table. A compare-and-set write that matches no row re-reads the current
 * version to say why: a stale version is a conflict carrying both versions, a vanished row is not found, and
 * a singular link that holds some other target is rejected rather than cleared.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineBusinessRecordReadRepository::class)]
#[CoversClass(DoctrineBusinessRecordWriteRepository::class)]
final class BusinessRecordRepositoryGuardIntegrationTest extends TestCase
{
    /**
     * Inside the writing transaction the stored lines come back in position order with their values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStoredLinesAreReadInPositionOrderInsideTheWritingTransaction(): void
    {
        $fixture = $this->fixture();
        $database = $this->service($fixture['container'], Connection::class);
        $reads = $this->service($fixture['container'], BusinessRecordReadRepository::class);
        $records = $this->service($fixture['container'], BusinessRecordService::class);
        $version = 1;
        $lineIds = [Uuid::uuid7()->toString(), Uuid::uuid7()->toString()];
        foreach ($lineIds as $offset => $lineId) {
            $version = $records->relate(new RelateRecordsCommand(
                $fixture['context'],
                $fixture['owner']->definition->handle,
                $fixture['ownerId'],
                $version,
                'lines',
                $lineId,
                NeutralBusinessFixture::idempotencyKey('integrity-line-' . $offset . '-' . $lineId),
                1 - $offset,
                targetValues: ['description' => 'Integrity line ' . $offset, 'units' => ($offset + 1) . '.000'],
            ))->version;
        }

        $database->beginTransaction();
        try {
            $stored = $reads->ownedLinesForDocumentIntegrity(
                $fixture['owner'],
                $fixture['ownerRecord'],
                $this->relationship($fixture['owner'], 'lines'),
                $fixture['line'],
                10,
            );
        } finally {
            $database->rollBack();
        }

        self::assertSame([$lineIds[1], $lineIds[0]], array_map(static fn ($line): string => $line->recordId, $stored));
        self::assertSame([0, 1], array_map(static fn ($line): int => $line->position, $stored));
        self::assertSame('Integrity line 1', $stored[0]->values['description'] ?? null);
    }

    /**
     * The read is refused outside the writing transaction, and for anything but the owner's line collection.
     *
     * Outside a transaction the read could not hold the rows it would judge. A relationship that is not an
     * owned-line collection, or an installation other than the owner's, has no collection to judge at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheReadIsRefusedOutsideTheTransactionAndForAnythingButTheOwnersLineCollection(): void
    {
        $fixture = $this->fixture();
        $database = $this->service($fixture['container'], Connection::class);
        $reads = $this->service($fixture['container'], BusinessRecordReadRepository::class);
        try {
            $reads->ownedLinesForDocumentIntegrity(
                $fixture['owner'],
                $fixture['ownerRecord'],
                $this->relationship($fixture['owner'], 'lines'),
                $fixture['line'],
                10,
            );
            self::fail('The integrity read must not run outside the writing transaction.');
        } catch (BusinessRecordTemporarilyUnavailable) {
            self::assertFalse($database->isTransactionActive());
        }
        $refusals = [];

        $database->beginTransaction();
        try {
            foreach (
                [
                    [$fixture['owner'], $this->relationship($fixture['owner'], 'tags')],
                    [$fixture['line'], $this->relationship($fixture['owner'], 'lines')],
                ] as [$installation, $relationship]
            ) {
                try {
                    $reads->ownedLinesForDocumentIntegrity(
                        $installation,
                        $fixture['ownerRecord'],
                        $relationship,
                        $fixture['line'],
                        10,
                    );
                } catch (BusinessRecordSchemaUnavailable $refusal) {
                    $refusals[] = $refusal->getMessage();
                }
            }
        } finally {
            $database->rollBack();
        }

        self::assertSame([
            'Only an owned-line collection carries document lines.',
            'The installed owned-line table is unavailable.',
        ], $refusals);
    }

    /**
     * A compare-and-set write that matches no row explains itself from the version stored now.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnmatchedCompareAndSetReportsConflictAbsenceOrAForeignLinkFromTheStoredVersion(): void
    {
        $fixture = $this->fixture();
        $container = $fixture['container'];
        $context = $fixture['context'];
        $database = $this->service($container, Connection::class);
        $writes = $this->service($container, BusinessRecordWriteRepository::class);
        $records = $this->service($container, BusinessRecordService::class);
        $resolver = $this->service($container, BusinessRecordDefinitionResolver::class);
        $owner = $fixture['owner'];
        $category = $this->relationship($owner, 'category');
        $target = $resolver->forCreate($context, $fixture['targetHandle']);
        $version = $records->relate(new RelateRecordsCommand(
            $context,
            $owner->definition->handle,
            $fixture['ownerId'],
            1,
            'category',
            $fixture['targetIds'][0],
            NeutralBusinessFixture::idempotencyKey('guard-category-' . $fixture['ownerId']),
        ))->version;
        self::assertSame(2, $version);
        $stored = $fixture['ownerRecord'];
        $current = new BusinessRecord(
            $stored->definitionId,
            $stored->definitionVersion,
            $stored->recordKey,
            $stored->recordId,
            $stored->scope,
            $version,
            null,
            ['title' => 'Integrity owner'],
            $stored->createdBy,
            $stored->createdAt,
            $stored->updatedBy,
            $stored->updatedAt,
        );
        $otherTargetKey = $this->recordKey($database, $target, $fixture['targetIds'][1]);
        $now = new DateTimeImmutable();
        $outcomes = [];

        $database->beginTransaction();
        try {
            $attempts = [
                'stale update' => fn () => $writes->update(
                    $owner,
                    $current->updated(['title' => 'Stale rewrite'], $context->actorId(), $now),
                    1,
                ),
                'vanished delete' => fn () => $writes->hardDelete(
                    $owner,
                    new BusinessRecord(
                        $stored->definitionId,
                        $stored->definitionVersion,
                        Uuid::uuid7()->toString(),
                        Uuid::uuid7()->toString(),
                        $stored->scope,
                        1,
                        null,
                        [],
                        $stored->createdBy,
                        $stored->createdAt,
                        $stored->updatedBy,
                        $stored->updatedAt,
                    ),
                    1,
                ),
                'foreign link' => fn () => $writes->unrelate(
                    $owner,
                    $current,
                    $category,
                    $otherTargetKey,
                    $context->actorId(),
                    $now,
                    $version,
                ),
                'stale unlink' => fn () => $writes->unrelate(
                    $owner,
                    $current,
                    $category,
                    $otherTargetKey,
                    $context->actorId(),
                    $now,
                    1,
                ),
            ];
            foreach ($attempts as $name => $attempt) {
                try {
                    $attempt();
                    $outcomes[$name] = 'written';
                } catch (BusinessRecordVersionConflict $conflict) {
                    $outcomes[$name] = sprintf(
                        'conflict %d/%d',
                        $conflict->expectedVersion,
                        $conflict->actualVersion ?? -1,
                    );
                } catch (BusinessRecordNotFound) {
                    $outcomes[$name] = 'not found';
                } catch (BusinessRelationshipRejected $rejected) {
                    $outcomes[$name] = $rejected->getMessage();
                }
            }
        } finally {
            $database->rollBack();
        }

        self::assertSame([
            'stale update' => 'conflict 1/2',
            'vanished delete' => 'not found',
            'foreign link' => 'The requested singular relationship does not exist.',
            'stale unlink' => 'conflict 1/2',
        ], $outcomes);
    }

    /**
     * Read the storage key of one record by its public identity.
     *
     * @param   Connection                  $database  Session.
     * @param   ResolvedBusinessDefinition  $resolved  Definition whose record table holds the row.
     * @param   string                      $recordId  Public record identity.
     *
     * @return  string  Storage key.
     *
     * @since   2.0.0
     */
    private function recordKey(Connection $database, ResolvedBusinessDefinition $resolved, string $recordId): string
    {
        $table = $resolved->installation->blueprint->table('record');
        self::assertNotNull($table);
        $key = $table->column('record_id');
        $identityField = $resolved->definition->identityStrategy === IdentityStrategy::Uuid
            ? 'record_id'
            : $table->options['identity_field'] ?? null;
        self::assertIsString($identityField);
        $identity = $table->column($identityField);
        self::assertNotNull($key);
        self::assertNotNull($identity);
        $value = $database->fetchOne(sprintf(
            'SELECT %s FROM %s WHERE %s = ?',
            $database->quoteSingleIdentifier($key->physicalName),
            $database->quoteSingleIdentifier($table->physicalName),
            $database->quoteSingleIdentifier($identity->physicalName),
        ), [$recordId]);
        self::assertIsString($value);

        return $value;
    }

    /**
     * Install an owner with an owned-line collection, two stored targets and one stored owner record.
     *
     * @return  array{container: Container, context: ExecutionContext, owner: ResolvedBusinessDefinition,
     *          line: ResolvedBusinessDefinition, ownerId: string, targetHandle: string, targetIds: list<string>,
     *          ownerRecord: BusinessRecord}  The fixture.
     *
     * @since   2.0.0
     */
    private function fixture(): array
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $this->service($container, BusinessRecordService::class);
        $resolver = $this->service($container, BusinessRecordDefinitionResolver::class);
        $database = $this->service($container, Connection::class);
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
                ['label' => 'Guard target ' . $offset],
                NeutralBusinessFixture::idempotencyKey('guard-target-' . $targetId),
                recordId: $targetId,
            ));
        }
        $ownerId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $owner->handle,
            ['title' => 'Integrity owner'],
            NeutralBusinessFixture::idempotencyKey('integrity-owner-' . $suffix),
            recordId: $ownerId,
        ));
        $resolvedOwner = $resolver->forCreate($context, $owner->handle);
        $table = $resolvedOwner->installation->blueprint->table('record');
        self::assertNotNull($table);
        $keyColumn = $table->column('record_id');
        self::assertNotNull($keyColumn);
        $recordKey = $database->fetchOne(sprintf(
            'SELECT %s FROM %s',
            $database->quoteSingleIdentifier($keyColumn->physicalName),
            $database->quoteSingleIdentifier($table->physicalName),
        ));
        self::assertIsString($recordKey, 'The fresh owner definition holds exactly the one record.');
        $now = new DateTimeImmutable();

        return [
            'container' => $container,
            'context' => $context,
            'owner' => $resolvedOwner,
            'line' => $resolver->forCreate($context, $line->handle),
            'ownerId' => $ownerId,
            'targetHandle' => $target->handle,
            'targetIds' => $targetIds,
            'ownerRecord' => new BusinessRecord(
                $resolvedOwner->definition->id,
                $resolvedOwner->definition->definitionVersion,
                $recordKey,
                $ownerId,
                RecordScope::forDefinition($resolvedOwner->definition->scope, $context->site(), null),
                1,
                null,
                ['title' => 'Integrity owner'],
                $context->actorId(),
                $now,
                $context->actorId(),
                $now,
            ),
        ];
    }

    /**
     * Find one declared relationship of a resolved definition.
     *
     * @param   ResolvedBusinessDefinition  $resolved  Definition declaring it.
     * @param   string                      $handle    Relationship handle.
     *
     * @return  RelationshipDefinition  The relationship.
     *
     * @since   2.0.0
     */
    private function relationship(ResolvedBusinessDefinition $resolved, string $handle): RelationshipDefinition
    {
        $relationship = $resolved->definition->runtimeRelationship($handle);
        self::assertNotNull($relationship);

        return $relationship;
    }

    /**
     * Resolve a typed service.
     *
     * @template T of object
     *
     * @param   Container        $container  Booted kernel.
     * @param   class-string<T>  $id         Service identity.
     *
     * @return  T  Service.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $id): object
    {
        $service = $container->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
