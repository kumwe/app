<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Certifies that every stored business number counter carries the widened counter identity intact.
 *
 * The `V2-ERP-002` widening asked for counters scoped by document type and legal entity. Both dimensions
 * turn out to be coordinates the counter identity has carried since `BusinessNumberSequenceMigration`
 * installed it: a counter is the natural five-part tuple of site, definition, field handle, scope key and
 * period key, where `definition_id` with `field_handle` is the document type, `site_identifier` is the
 * legal entity — the boundary ADR 0001 rules a business's books may never share — and `scope_key` narrows
 * a run to one organization branch when the field declares it. The forward mapping from every existing
 * counter to its widened counter is therefore the identity mapping, and because an identity mapping
 * rewrites nothing, every `current_value` survives it untouched by construction.
 *
 * What an upgrade still owes the operator is proof against the installed data rather than against the
 * design: this step refuses to complete while any stored counter could not serve as a widened counter. It
 * requires the five identity columns and the arbitrating unique index to exist — the index is what makes
 * the mapping land on *exactly one* row — and it refuses a row whose site, field handle or scope key is
 * empty, or whose value is negative. The period key is legitimately empty for a lifetime run, and the
 * definition coordinate needs no emptiness probe because its GUID column type cannot hold one on the
 * engines that give it a native representation.
 *
 * Nothing here writes, so the step is re-entrant from any point, holds no lock worth naming, and needs no
 * privilege beyond reading the installation's own schema; it runs unchanged on MariaDB, MySQL, PostgreSQL
 * and SQLite.
 *
 * @since  2.0.0
 */
final readonly class NumberSequenceIdentityMigration implements RepeatableMigration
{
    /**
     * Stable migration identity recorded in the schema ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260822010000_number_sequence_identity';

    /**
     * Bind the migration to the prefixed table map.
     *
     * @param  TableNames  $tables  Resolver applying the configured prefix to table names.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Name the identity recorded for this migration in the schema ledger.
     *
     * @return  string  The stable migration identifier.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Derive the ledger checksum from this file's bytes so any edit is detected.
     *
     * @return  string  Stable digest binding the recorded version to this exact implementation.
     *
     * @throws  RuntimeException  When the file digest cannot be calculated.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The number sequence identity migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Prove every stored counter maps forward to exactly one widened counter with its value intact.
     *
     * The counter table itself is owned by `BusinessNumberSequenceMigration`, which the plan orders ahead
     * of this step, so its absence here is a broken installation rather than a fresh one and is refused
     * instead of repaired. The certification then has two halves. The schema half requires the five
     * identity columns beside the value, and the unique index over the identity tuple, because that index
     * is the injectivity of the forward mapping: with it in place a widened coordinate tuple can name at
     * most one row. The data half scans for a row no widened coordinate tuple could name — an empty site,
     * field handle or scope key, or a negative value — and fails the upgrade loudly rather than letting
     * such a row allocate ambiguously later. Nothing is updated or deleted, which is how every
     * `current_value` is provably carried forward unchanged.
     *
     * @param   Connection  $database  Connection the certification runs on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the counter table, one of its identity columns or its arbitrating
     *          index is missing, or a stored counter row cannot serve as a widened counter.
     * @throws  \Doctrine\DBAL\Exception  When the driver refuses the introspection or the scan.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $name = $this->tables->raw('business_number_sequences');
        if (!$manager->tablesExist([$name])) {
            throw new RuntimeException(
                'The business number sequence counter table is missing, so there is no counter identity to widen.',
            );
        }
        $table = $manager->introspectTableByUnquotedName($name);
        $columns = ['site_identifier', 'definition_id', 'field_handle', 'scope_key', 'period_key', 'current_value'];
        foreach ($columns as $column) {
            if (!$table->hasColumn($column)) {
                throw new RuntimeException(sprintf(
                    'The business number sequence table is missing its %s column, so counters cannot map forward.',
                    $column,
                ));
            }
        }
        if (!$table->hasIndex($this->tables->raw('uniq_business_number_sequence'))) {
            throw new RuntimeException(
                'The business number sequence table has no counter identity index, '
                . 'so a counter could map forward to more than one widened counter.',
            );
        }
        $stranded = $database->fetchOne(sprintf(
            "SELECT COUNT(*) FROM %s WHERE site_identifier = '' OR field_handle = '' "
            . "OR scope_key = '' OR current_value < 0",
            $this->tables->quoted('business_number_sequences'),
        ));
        if (!is_int($stranded) && !is_string($stranded)) {
            throw new RuntimeException('The business number counter identity scan could not be counted.');
        }
        if ((string) $stranded !== '0') {
            throw new RuntimeException(sprintf(
                '%s business number counter row(s) carry an empty identity coordinate or a negative value '
                . 'and cannot serve as widened counters; repair them before upgrading.',
                (string) $stranded,
            ));
        }
    }
}
