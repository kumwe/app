<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\InterfaceStandard;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Infrastructure\Persistence\Migration\InterfacePresentationPreferenceMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\PresentationPreference;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceVersionConflict;
use Kumwe\CMS\Presentation\Infrastructure\Persistence\DoctrinePresentationPreferenceRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the DBAL adapter and migration preserve portable preference and concurrency semantics.
 *
 * @since  2.0.0
 */
#[CoversClass(InterfacePresentationPreferenceMigration::class)]
#[CoversClass(DoctrinePresentationPreferenceRepository::class)]
final class PresentationPreferencePersistenceIntegrationTest extends TestCase
{
    /**
     * Proves migration replay is safe and create, update, read, and reset retain exact values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortablePreferenceLifecyclePersistsExactly(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        $migration = new InterfacePresentationPreferenceMigration($tables);
        $migration->up($database);
        $migration->up($database);
        $repository = new DoctrinePresentationPreferenceRepository($database, $tables);
        $created = $this->preference('compact', 1);
        $key = PresentationPreferenceKey::fromPreference($created);

        $repository->save($created, 0);
        self::assertSame($created->toArray(), $repository->find($key)?->toArray());

        $updated = $this->preference('touch', 2);
        $repository->save($updated, 1);
        self::assertSame($updated->toArray(), $repository->find($key)?->toArray());

        $repository->delete($key, ContributionOwner::core(), 2);
        self::assertNull($repository->find($key));
    }

    /**
     * Proves a stale compare-and-swap leaves the latest durable preference unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaleUpdateReportsActualVersionWithoutOverwrite(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        (new InterfacePresentationPreferenceMigration($tables))->up($database);
        $repository = new DoctrinePresentationPreferenceRepository($database, $tables);
        $created = $this->preference('compact', 1);
        $repository->save($created, 0);
        $repository->save($this->preference('touch', 2), 1);
        $this->expectException(PresentationPreferenceVersionConflict::class);

        try {
            $repository->save($this->preference('comfortable', 2), 1);
        } finally {
            self::assertSame(
                'touch',
                $repository->find(PresentationPreferenceKey::fromPreference($created))?->value()->value(),
            );
        }
    }

    /**
     * Build one deterministic user density record.
     *
     * @param   string  $value    Density literal.
     * @param   int     $version  Optimistic record version.
     *
     * @return  PresentationPreference
     *
     * @since   2.0.0
     */
    private function preference(string $value, int $version): PresentationPreference
    {
        return PresentationPreference::create(
            SurfaceId::fromString('core.administrator.settings'),
            ContributionOwner::core(),
            CustomizationScope::User,
            'actor:one',
            CustomizationSlot::Density,
            $value,
            $version,
            'actor:one',
            new DateTimeImmutable('2026-08-11T14:00:00Z'),
        );
    }
}
