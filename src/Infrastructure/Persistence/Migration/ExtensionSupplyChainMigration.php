<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Gives the extension supply-chain controls the two tables they need to be more than a log line.
 *
 * `extension_release_attestations` is where install-time admission writes its result: the state of the
 * package's CycloneDX bill of materials and its provenance statement, the digests that bind them, and
 * the outcome of the static code scan with the findings it raised. It is a table of its own rather than
 * columns on `extension_releases` because it holds two JSON documents whose size tracks the package
 * rather than the release, and because a release installed before this migration must keep reading back
 * cleanly — an absent row is what the Extensions screen renders as `unscanned`.
 *
 * `extension_revocation_feed_state` holds one row per configured feed origin: the highest sequence
 * number applied, when the last verified list was fetched, and why the last attempt failed if it did.
 * The sequence is the rollback defense — a list is applied only when it is strictly newer than the one
 * already recorded — and the last-success instant is what makes an unreachable feed visible as staleness
 * rather than as silence.
 *
 * Deliberately no trigger, no privileged operation, and no data movement. It creates two tables and
 * nothing else, so it applies identically on MariaDB, MySQL, PostgreSQL and SQLite and needs nothing
 * beyond the DDL rights every other schema migration already uses. Both creations are guarded by an
 * existence check, so a replay after an interrupted attempt is a no-op.
 *
 * @since  2.0.0
 */
final readonly class ExtensionSupplyChainMigration implements RepeatableMigration
{
    /**
     * Stable migration identity recorded in the schema ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260814010000_extension_supply_chain';

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
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The extension supply-chain migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Create both tables and prove they exist before the migration is recorded.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When either table is still absent afterwards.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $this->createAttestations($database);
        $this->createRevocationFeedState($database);
        $this->assertApplied($database);
    }

    /**
     * Create the per-release record of what install-time admission established.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    private function createAttestations(Connection $database): void
    {
        $name = $this->tables->raw('extension_release_attestations');
        if ($database->createSchemaManager()->tablesExist([$name])) {
            return;
        }
        $schema = new Schema();
        $table = $schema->createTable($name);
        $table->addColumn('release_id', Types::GUID);
        $table->addColumn('sbom_state', Types::STRING, ['length' => 16]);
        $table->addColumn('sbom_sha256', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $table->addColumn('sbom_components', Types::INTEGER, ['default' => 0]);
        $table->addColumn('sbom_document', Types::JSON, ['notnull' => false]);
        $table->addColumn('provenance_state', Types::STRING, ['length' => 16]);
        $table->addColumn('provenance_sha256', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $table->addColumn('provenance_builder', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('provenance_document', Types::JSON, ['notnull' => false]);
        $table->addColumn('conformance_mode', Types::STRING, ['length' => 16]);
        $table->addColumn('conformance_state', Types::STRING, ['length' => 16]);
        $table->addColumn('conformance_document', Types::JSON, ['notnull' => false]);
        $table->addColumn('recorded_at', Types::DATETIME_IMMUTABLE);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('release_id')->create(),
        );
        $table->addIndex(['sbom_state'], $this->tables->raw('idx_extension_attestation_sbom'));
        foreach ($schema->toSql($database->getDatabasePlatform()) as $statement) {
            $database->executeStatement($statement);
        }
    }

    /**
     * Create the per-origin record of the upstream revocation list already applied.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    private function createRevocationFeedState(Connection $database): void
    {
        $name = $this->tables->raw('extension_revocation_feed_state');
        if ($database->createSchemaManager()->tablesExist([$name])) {
            return;
        }
        $schema = new Schema();
        $table = $schema->createTable($name);
        $table->addColumn('origin_digest', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('origin', Types::STRING, ['length' => 500]);
        $table->addColumn('issuer', Types::STRING, ['length' => 191, 'notnull' => false]);
        $table->addColumn('applied_sequence', Types::BIGINT, ['default' => 0]);
        $table->addColumn('document_sha256', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $table->addColumn('revoked_key_count', Types::INTEGER, ['default' => 0]);
        $table->addColumn('last_success_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_attempt_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_failure_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_failure_reason', Types::STRING, ['length' => 500, 'notnull' => false]);
        $table->addColumn('consecutive_failures', Types::INTEGER, ['default' => 0]);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('origin_digest')->create(),
        );
        foreach ($schema->toSql($database->getDatabasePlatform()) as $statement) {
            $database->executeStatement($statement);
        }
    }

    /**
     * Prove both tables this migration exists to add are present before it is recorded as applied.
     *
     * @param   Connection  $database  Connection the checks run on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When either table is still absent.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the introspection.
     *
     * @since   2.0.0
     */
    private function assertApplied(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        foreach (['extension_release_attestations', 'extension_revocation_feed_state'] as $logical) {
            if (!$manager->tablesExist([$this->tables->raw($logical)])) {
                throw new RuntimeException(sprintf('The %s table is missing after migration.', $logical));
            }
        }
    }
}
