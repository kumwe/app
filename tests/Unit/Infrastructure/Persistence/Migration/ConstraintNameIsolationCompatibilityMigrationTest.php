<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationCompatibilityMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationPortabilityMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationPlan;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the protocol handoff from the immutable published rename to its corrected implementation.
 *
 * @since  2.0.0
 */
#[CoversClass(ConstraintNameIsolationCompatibilityMigration::class)]
#[CoversClass(MigrationPlan::class)]
final class ConstraintNameIsolationCompatibilityMigrationTest extends TestCase
{
    /**
     * The wrapper occupies the published slot but fingerprints every implementation byte it executes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testChecksumBindsTheWholeCompatibilityImplementation(): void
    {
        $migration = $this->compatibility();
        $wrapper = (new ReflectionClass($migration))->getFileName();
        self::assertIsString($wrapper);
        $root = dirname($wrapper);
        $digests = [];
        foreach (
            [
            $root . '/ConstraintNameIsolationCompatibilityMigration.php',
            $root . '/ConstraintNameIsolationMigration.php',
            $root . '/ConstraintNameIsolationPortabilityMigration.php',
            ] as $source
        ) {
            $digest = hash_file('sha256', $source);
            self::assertIsString($digest);
            $digests[] = $digest;
        }

        self::assertSame(ConstraintNameIsolationMigration::ID, $migration->id());
        self::assertSame(
            hash('sha256', ConstraintNameIsolationCompatibilityMigration::ID . ':' . implode(':', $digests)),
            $migration->checksum(),
        );
        self::assertNotSame(ConstraintNameIsolationCompatibilityMigration::PUBLISHED_CHECKSUM, $migration->checksum());
    }

    /**
     * The historical exception names the exact checksum the immutable published source still produces.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishedChecksumStillMatchesTheImmutableOriginal(): void
    {
        self::assertSame(
            ConstraintNameIsolationCompatibilityMigration::PUBLISHED_CHECKSUM,
            $this->published()->checksum(),
        );
    }

    /**
     * A fresh ledger runs the corrected wrapper in the original slot, then the append-only follow-up.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFreshPlanKeepsTheCorrectedSlotPending(): void
    {
        $compatibility = $this->compatibility();
        $portability = $this->portability();
        $plan = $this->plan($compatibility, $portability);

        self::assertSame([$compatibility, $portability], $plan->pending([]));
    }

    /**
     * A ledger carrying the published checksum skips the wrapper and reaches the append-only repair.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExistingPublishedLedgerContinuesAtTheFollowUp(): void
    {
        $compatibility = $this->compatibility();
        $portability = $this->portability();
        $plan = $this->plan($compatibility, $portability);

        self::assertSame([$portability], $plan->pending([
            ConstraintNameIsolationCompatibilityMigration::ID =>
                ConstraintNameIsolationCompatibilityMigration::PUBLISHED_CHECKSUM,
        ]));
    }

    /**
     * Compose the minimal two-slot plan with the one explicit historical checksum it accepts.
     *
     * @return  MigrationPlan  Compatibility slot followed by the append-only repair.
     *
     * @since   2.0.0
     */
    private function plan(
        ConstraintNameIsolationCompatibilityMigration $compatibility,
        ConstraintNameIsolationPortabilityMigration $portability,
    ): MigrationPlan {
        return new MigrationPlan(
            [$compatibility, $portability],
            [ConstraintNameIsolationCompatibilityMigration::ID => [
                ConstraintNameIsolationCompatibilityMigration::PUBLISHED_CHECKSUM,
            ]],
        );
    }

    /**
     * Build the corrected implementation occupying the published plan slot.
     *
     * @return  ConstraintNameIsolationCompatibilityMigration  Migration under test.
     *
     * @since   2.0.0
     */
    private function compatibility(): ConstraintNameIsolationCompatibilityMigration
    {
        return new ConstraintNameIsolationCompatibilityMigration($this->tables());
    }

    /**
     * Build the immutable implementation whose checksum installed databases may carry.
     *
     * @return  ConstraintNameIsolationMigration  Immutable published migration.
     *
     * @since   2.0.0
     */
    private function published(): ConstraintNameIsolationMigration
    {
        return new ConstraintNameIsolationMigration($this->tables());
    }

    /**
     * Build the later repair reached by databases that already completed the published slot.
     *
     * @return  ConstraintNameIsolationPortabilityMigration  Append-only repair.
     *
     * @since   2.0.0
     */
    private function portability(): ConstraintNameIsolationPortabilityMigration
    {
        return new ConstraintNameIsolationPortabilityMigration($this->tables());
    }

    /**
     * Build the prefix-aware table map these checksum and plan tests do not connect through.
     *
     * @return  TableNames  Prefix-aware table map for checksum-only tests.
     *
     * @since   2.0.0
     */
    private function tables(): TableNames
    {
        return new TableNames($this->createStub(Connection::class), 'kumwe_');
    }
}
