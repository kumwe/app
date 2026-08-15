<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Runs the corrected isolation logic in the published migration's original plan slot.
 *
 * The first constraint-name migration was distributed with a checksum derived from its own bytes, so
 * those bytes stay immutable. Fresh databases and databases interrupted before recording that ledger row
 * still need the corrected `fk_`, overlapping-prefix and replay-shape behavior, however. This compatibility
 * migration therefore retains the published ID while delegating `up()` to the safe append-only implementation.
 * `MigrationPlan` explicitly accepts the published checksum for databases that already recorded the old
 * implementation; they skip this slot and reach the later portability migration instead.
 *
 * @since  2.0.0
 */
final readonly class ConstraintNameIsolationCompatibilityMigration implements RepeatableMigration
{
    /**
     * Original ledger identity, retained so this implementation occupies the exact published plan slot.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = ConstraintNameIsolationMigration::ID;

    /**
     * Checksum recorded by the published implementation before this compatibility handoff existed.
     *
     * The value is deliberately literal: both plan compatibility and interrupted-attempt recovery grant
     * their narrow exception only to these exact released bytes. The unit suite also proves that the
     * immutable original source still calculates this value.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string PUBLISHED_CHECKSUM =
        '0edbe48d080c481f70ba07e54b4de1d2e8852407d9eec4b11e3fb9a70f348d5a';

    /**
     * Bind the compatibility slot to this installation's physical table names.
     *
     * @param  TableNames  $tables  Prefix-aware table-name compiler passed to the safe implementation.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the identity of the published plan slot this implementation safely replaces.
     *
     * @return  string  Original ordered migration identity.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind compatibility to every source file whose behavior this wrapper executes or delegates through.
     *
     * The portability implementation calls the immutable original's target-name derivation, so all three
     * files are inputs: this wrapper, the immutable original and the corrected implementation. Editing any
     * one produces a new checksum instead of silently changing what a fresh or resumed database runs.
     *
     * @return  string  SHA-256 migration checksum.
     *
     * @throws  RuntimeException  When one of the bound source files cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $ownDigest = hash_file('sha256', __FILE__);
        if (!is_string($ownDigest)) {
            throw new RuntimeException('The constraint name isolation compatibility checksum failed.');
        }
        $digests = [$ownDigest];
        foreach ([
            __DIR__ . '/ConstraintNameIsolationMigration.php',
            __DIR__ . '/ConstraintNameIsolationPortabilityMigration.php',
        ] as $source) {
            $digest = hash_file('sha256', $source);
            if (!is_string($digest)) {
                throw new RuntimeException('The constraint name isolation compatibility checksum failed.');
            }
            $digests[] = $digest;
        }

        return hash('sha256', self::ID . ':' . implode(':', $digests));
    }

    /**
     * Apply the corrected, re-entrant isolation behavior in the published plan slot.
     *
     * @param   Connection  $database  Installation database whose constraint names are isolated.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the safe implementation finds an unusable constraint name, an
     *          overlapping replay target with the wrong shape, or an unisolated postcondition.
     * @throws  \Doctrine\DBAL\Exception  When the driver refuses schema introspection or generated DDL.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        (new ConstraintNameIsolationPortabilityMigration($this->tables))->up($database);
    }
}
