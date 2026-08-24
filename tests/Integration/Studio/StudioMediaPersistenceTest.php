<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioMediaUploadMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadPlan;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadRequest;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadSession;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadState;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineStudioMediaUploadRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real repeatable migration and scoped optimistic upload repository through SQLite DBAL.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineStudioMediaUploadRepository::class)]
#[CoversClass(StudioMediaUploadMigration::class)]
final class StudioMediaPersistenceTest extends TestCase
{
    /**
     * Upload snapshots round-trip only under their full trusted scope and advance by compare-and-swap.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testScopedUploadRoundTripAndOptimisticTransition(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        $migration = new StudioMediaUploadMigration($tables);
        $migration->up($database);
        $migration->up($database);
        $repository = new DoctrineStudioMediaUploadRepository($database, $tables);
        $session = new StudioMediaUploadSession(
            'uploads/0123456789abcdef0123456789abcdef',
            'actor-1',
            'default',
            'contexts/media-persistence',
            'session-r1',
            new StudioMediaUploadRequest('photo.jpg', 'image/jpeg', 128, 'studio.media/content'),
            new StudioMediaUploadPlan(1024, false),
            StudioMediaUploadState::Authorized,
            0,
            str_repeat('a', 64),
            new DateTimeImmutable('2030-01-01T00:00:00Z'),
        );

        $repository->add($session);

        self::assertEquals($session, $repository->find(
            $session->id,
            'actor-1',
            'default',
            'contexts/media-persistence',
            'session-r1',
        ));
        self::assertNull($repository->find(
            $session->id,
            'actor-2',
            'default',
            'contexts/media-persistence',
            'session-r1',
        ));
        $verifying = $session->transition(StudioMediaUploadState::Verifying, 128);
        self::assertTrue($repository->save($verifying, 1));
        self::assertFalse($repository->save($verifying, 1));
        self::assertSame(StudioMediaUploadState::Verifying, $repository->find(
            $session->id,
            'actor-1',
            'default',
            'contexts/media-persistence',
            'session-r1',
        )?->state);
        $forgedScope = new StudioMediaUploadSession(
            $verifying->id,
            'actor-2',
            $verifying->siteId,
            $verifying->contextKey,
            $verifying->generation,
            $verifying->request,
            $verifying->plan,
            $verifying->state,
            $verifying->transferred,
            $verifying->tokenDigest,
            $verifying->expiresAt,
            version: $verifying->version,
        );
        self::assertFalse($repository->save(
            $forgedScope->transition(StudioMediaUploadState::Cancelled, $forgedScope->transferred),
            $forgedScope->version,
        ));
    }
}
