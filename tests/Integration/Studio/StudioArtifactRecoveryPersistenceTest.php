<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use Doctrine\DBAL\DriverManager;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioArtifactRecoveryMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineStudioHostStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Exercises AP-4 DDL and exact-byte persistence through real DBAL transactions.
 *
 * This suite is intentionally driver-neutral and therefore runs unchanged in the repository's SQLite,
 * MariaDB/MySQL and PostgreSQL integration lanes.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineStudioHostStorage::class)]
#[CoversClass(StudioArtifactRecoveryMigration::class)]
final class StudioArtifactRecoveryPersistenceTest extends TestCase
{
    /**
     * Head compare-and-set, immutable history, recovery scope and rate counters survive migration replay.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testVersionHistoryAndRecoveryPersistAtomicallyWithoutDriverJsonRewrites(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        $migration = new StudioArtifactRecoveryMigration($tables);
        $migration->up($database);
        $migration->up($database);

        self::assertSame('20260824030000_studio_artifact_recovery', $migration->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $migration->checksum());
        foreach (
            [
            'studio_artifact_heads',
            'studio_artifact_revisions',
            'studio_host_idempotency',
            'studio_recovery_envelopes',
            'studio_recovery_rate_limits',
            ] as $table
        ) {
            self::assertTrue($database->createSchemaManager()->tablesExist([$tables->raw($table)]));
        }

        $storage = new DoctrineStudioHostStorage($database, $tables);
        $transactions = new DoctrineTransactionManager($database);
        $admission = new StudioArtifactAdmission(StudioContractSchemas::fromVendoredCorpus());
        $first = $admission->admit('publisher-namibia', self::blueprint());
        self::assertTrue($transactions->transactional(fn (): bool => $storage->store($first, null)));
        $second = $admission->revise($first, 'product-card-r6', 'draft');
        self::assertTrue($transactions->transactional(
            fn (): bool => $storage->store($second, $first->revision),
        ));
        $loser = $admission->revise($first, 'product-card-r7', 'published');
        self::assertFalse($transactions->transactional(
            fn (): bool => $storage->store($loser, $first->revision),
        ));

        self::assertSame($second->canonicalDocument, $storage->current(
            'publisher-namibia',
            $first->id,
            $first->version,
        )?->canonicalDocument);
        self::assertSame($first->canonicalDocument, $storage->revision(
            'publisher-namibia',
            $first->id,
            $first->version,
            $first->revision,
        )?->canonicalDocument);
        self::assertNull($storage->revision(
            'publisher-namibia',
            $first->id,
            $first->version,
            'product-card-r7',
        ));

        $envelope = CanonicalJson::stringify((object) ['number' => -0.0, 'nested' => (object) []]);
        self::assertSame('{"nested":{},"number":0}', $envelope);
        $transactions->transactional(function () use ($storage, $envelope): void {
            self::assertNull($storage->consumeRateLimit(str_repeat('a', 64), 0, 60000, 1));
            $storage->saveEnvelope('actor-1', str_repeat('b', 64), 'contexts/recovery', $envelope, 0);
        });
        self::assertSame($envelope, $storage->loadEnvelope(
            'actor-1',
            str_repeat('b', 64),
            'contexts/recovery',
        ));
        self::assertNull($transactions->transactional(
            fn (): ?int => $storage->consumeRateLimit(str_repeat('a', 64), 60000, 60000, 1),
        ));
        $transactions->transactional(function () use ($storage): void {
            $storage->discardEnvelope('actor-1', str_repeat('b', 64), 'contexts/recovery');
        });
        self::assertNull($storage->loadEnvelope('actor-1', str_repeat('b', 64), 'contexts/recovery'));
    }

    /**
     * Load the actual pinned Blueprint fixture.
     *
     * @return  stdClass  Decoded canonical Blueprint fixture.
     *
     * @since   2.0.0
     */
    private static function blueprint(): stdClass
    {
        $document = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 2) . '/Fixtures/Studio/testkit/fixtures/blueprint.product.example.json',
            ),
            false,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(stdClass::class, $document);

        return $document;
    }
}
