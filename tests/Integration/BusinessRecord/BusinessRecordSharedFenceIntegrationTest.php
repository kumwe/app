<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\Context\Contract\SystemActor;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use stdClass;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordReadRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves shared writer admission and exclusive lifecycle exclusion on the configured production engine.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineBusinessRecordMutationFence::class)]
#[CoversClass(DoctrineBusinessRecordReadRepository::class)]
#[CoversClass(BusinessRecordService::class)]
final class BusinessRecordSharedFenceIntegrationTest extends TestCase
{
    /**
     * A second aggregate commits before the first transaction releases, without exposing its uncommitted row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnrelatedRecordCommitsOverlapWithoutLosingVisibilityOrExpectedVersions(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $secondary = TestKernelFactory::create($environment);
        $context = TestKernelFactory::administratorContext($primary);
        $peerContext = TestKernelFactory::administratorContext($secondary);
        $database = $primary->get(Connection::class);
        $peer = $secondary->get(Connection::class);
        $records = $primary->get(BusinessRecordService::class);
        $peerRecords = $secondary->get(BusinessRecordService::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(Connection::class, $peer);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(BusinessRecordService::class, $peerRecords);
        $suffix = substr(str_replace('-', '', Uuid::uuid7()->toString()), -12);
        $definition = NeutralBusinessFixture::install(
            $primary,
            $context,
            NeutralBusinessFixture::document($suffix, Uuid::uuid7()->toString()),
        );
        $first = Uuid::uuid7()->toString();
        $second = Uuid::uuid7()->toString();
        foreach ([$first, $second] as $recordId) {
            $records->create(new CreateRecordCommand(
                $context,
                $definition->handle,
                NeutralBusinessFixture::recordValues($recordId),
                NeutralBusinessFixture::idempotencyKey('shared-create-' . $recordId),
                recordId: $recordId,
            ));
        }
        $peer->executeStatement($peer->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? 'SET SESSION innodb_lock_wait_timeout = 1'
            : "SET lock_timeout = '1s'");
        try {
            $database->beginTransaction();
            $records->update(new UpdateRecordCommand(
                $context,
                $definition->handle,
                $first,
                1,
                ['name' => 'held transaction'],
                NeutralBusinessFixture::idempotencyKey('shared-first-' . $suffix),
            ));
            $committed = $peerRecords->update(new UpdateRecordCommand(
                $peerContext,
                $definition->handle,
                $second,
                1,
                ['name' => 'concurrent transaction'],
                NeutralBusinessFixture::idempotencyKey('shared-second-' . $suffix),
            ));
            self::assertSame(2, $committed->version);
            self::assertTrue($database->isTransactionActive());
            self::assertSame(1, $peerRecords->read(new ReadRecordQuery(
                $peerContext,
                $definition->handle,
                $first,
            ))->version, 'Readers cannot see the other transaction before it commits.');
            try {
                $peerRecords->update(new UpdateRecordCommand(
                    $peerContext,
                    $definition->handle,
                    $first,
                    1,
                    ['name' => 'blocked on the same record'],
                    NeutralBusinessFixture::idempotencyKey('shared-blocked-' . $suffix),
                ));
                self::fail('Concurrent writers of the same record must still conflict.');
            } catch (BusinessRecordTemporarilyUnavailable) {
                self::assertTrue($database->isTransactionActive());
            }
            $fresh = $records->update(new UpdateRecordCommand(
                $context,
                $definition->handle,
                $second,
                2,
                ['name' => 'current version after peer commit'],
                NeutralBusinessFixture::idempotencyKey('shared-current-' . $suffix),
            ));
            self::assertSame(3, $fresh->version, 'A mutation cannot reuse an earlier MySQL read snapshot.');
            $database->commit();
            try {
                $peerRecords->update(new UpdateRecordCommand(
                    $peerContext,
                    $definition->handle,
                    $first,
                    1,
                    ['name' => 'stale loser'],
                    NeutralBusinessFixture::idempotencyKey('shared-stale-' . $suffix),
                ));
                self::fail('A moved expected version must still fail with a stable conflict.');
            } catch (BusinessRecordVersionConflict) {
                self::assertSame(2, $peerRecords->read(new ReadRecordQuery(
                    $peerContext,
                    $definition->handle,
                    $first,
                ))->version);
            }
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            $database->close();
            $peer->close();
        }
    }

    /**
     * Two writer fences overlap, while an installation transition waits for the last holder to leave.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWritersOverlapAndSchemaTransitionRequiresEveryHolderToLeave(): void
    {
        $configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
        $connections = new DoctrineConnectionFactory($configuration->database);
        $database = $connections->create();
        $peer = $connections->create();
        $suffix = substr(str_replace('-', '', Uuid::uuid7()->toString()), -12);
        $tables = new TableNames($database, 'fence_' . $suffix . '_');
        $definitionId = Uuid::uuid7()->toString();
        $handle = 'site.default.concurrent';
        // This fixture exercises the production fence SQL directly, independently of composition wiring.
        // Authorization is not under test: only the site's identity is consumed by this persistence port.
        $actor = $this->createStub(SystemActor::class);
        $actor->method('identifier')->willReturn('system:fence-test');
        $context = ExecutionContext::issueSystem(new stdClass(), $actor, SiteContext::fromString('default'), $suffix);
        $fence = new DoctrineBusinessRecordMutationFence($database, $tables);
        $peerFence = new DoctrineBusinessRecordMutationFence($peer, new TableNames($peer, $tables->prefix()));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (id VARCHAR(36) PRIMARY KEY, handle VARCHAR(191) NOT NULL UNIQUE, '
            . 'site_identifier VARCHAR(191) NOT NULL, owner_identifier VARCHAR(191) NOT NULL, '
            . 'owner_active BOOLEAN NOT NULL)',
            $tables->quoted('business_definitions'),
        ));
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (definition_id VARCHAR(36) PRIMARY KEY, site_identifier VARCHAR(191) NOT NULL, '
            . 'owner_identifier VARCHAR(191) NOT NULL, definition_version INTEGER NOT NULL, '
            . 'definition_checksum VARCHAR(64) NOT NULL, schema_checksum VARCHAR(64) NOT NULL, '
            . 'status VARCHAR(32) NOT NULL)',
            $tables->quoted('business_schema_installations'),
        ));
        $database->insert($tables->raw('business_definitions'), [
            'id' => $definitionId,
            'handle' => $handle,
            'site_identifier' => 'default',
            'owner_identifier' => 'default',
            'owner_active' => true,
        ], ['owner_active' => \Doctrine\DBAL\Types\Types::BOOLEAN]);
        $database->insert($tables->raw('business_schema_installations'), [
            'definition_id' => $definitionId,
            'site_identifier' => 'default',
            'owner_identifier' => 'default',
            'definition_version' => 1,
            'definition_checksum' => str_repeat('a', 64),
            'schema_checksum' => str_repeat('b', 64),
            'status' => 'active',
        ]);
        foreach ([$database, $peer] as $connection) {
            $connection->executeStatement($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? 'SET SESSION innodb_lock_wait_timeout = 1'
                : "SET lock_timeout = '1s'");
        }

        try {
            $database->beginTransaction();
            $first = $fence->lock($context, $handle);
            $peer->beginTransaction();
            $second = $peerFence->lock($context, $handle);
            self::assertEquals($first, $second, 'Both active transactions pinned the same generation.');
            $database->rollBack();

            // A lifecycle writer still conflicts while the second record transaction is in flight.
            $database->beginTransaction();
            try {
                $database->executeStatement(sprintf(
                    "UPDATE %s SET status = 'installing' WHERE definition_id = ?",
                    $tables->quoted('business_schema_installations'),
                ), [$definitionId]);
                self::fail('DDL admission must wait for all current-generation writers.');
            } catch (DbalException) {
                self::assertTrue($peer->isTransactionActive());
            } finally {
                $database->rollBack();
            }
            $peer->rollBack();
            $database->executeStatement(sprintf(
                "UPDATE %s SET status = 'installing' WHERE definition_id = ?",
                $tables->quoted('business_schema_installations'),
            ), [$definitionId]);
            $peer->beginTransaction();
            try {
                $peerFence->lock($context, $handle);
                self::fail('A newly admitted writer cannot use the old active generation.');
            } catch (BusinessRecordSchemaUnavailable) {
                self::assertTrue(true);
            }
        } finally {
            foreach ([$database, $peer] as $connection) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
            $database->executeStatement('DROP TABLE ' . $tables->quoted('business_schema_installations'));
            $database->executeStatement('DROP TABLE ' . $tables->quoted('business_definitions'));
            $peer->close();
            $database->close();
        }
    }
}
