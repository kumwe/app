<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\IndexNameIsolationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\NumberSequenceIdentityMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins the index-renaming rule, which is what makes two installations in one PostgreSQL schema possible.
 *
 * The naming rule is the whole migration: everything else is schema plumbing around it. These assert
 * the properties a second installation depends on — that the name differs between installations, that
 * it fits the smallest identifier limit across the supported engines, that every shipped literal is
 * still recognised as collision-prone while every shipped derivation is left alone, and that running
 * the rename twice is a no-op rather than a second rename.
 *
 * @since  2.0.0
 */
#[CoversClass(IndexNameIsolationMigration::class)]
final class IndexNameIsolationMigrationTest extends TestCase
{
    /**
     * Every non-primary index name the shipped migrations spell literally, with the table it sits on.
     *
     * Held here rather than discovered so that adding a one-hundred-and-eleventh literal is a
     * deliberate edit to a declared inventory, and so the identifier-budget assertion below has
     * something total to check. Three CoreSchema entries are replaced by site-scoped successors later
     * in the plan and are inventoried against the table that declares them, because an upgrade
     * interrupted before the replacement still carries them when the rename runs.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    public const array SHIPPED_LITERALS = [
        'idx_admin_session_expiry' => 'administrator_sessions',
        'idx_approval_requester' => 'approval_requests',
        'idx_approval_resource' => 'approval_requests',
        'idx_approval_vote_decision' => 'approval_votes',
        'idx_audit_actor' => 'audit_events',
        'idx_audit_time' => 'audit_events',
        'idx_bcommand_idempotency_expiry' => 'business_command_idempotency',
        'idx_brecord_revision_identity' => 'business_record_revisions',
        'idx_brecord_revision_record' => 'business_record_revisions',
        'idx_bschema_install_owner' => 'business_schema_installations',
        'idx_bschema_install_site' => 'business_schema_installations',
        'idx_bschema_plan_definition' => 'business_schema_plans',
        'idx_bschema_plan_site' => 'business_schema_plans',
        'idx_bschema_recovery_source' => 'business_schema_recovery_evidence',
        'idx_bschema_step_state' => 'business_schema_plan_steps',
        'idx_business_definition_owner' => 'business_definitions',
        'idx_business_definition_state' => 'business_definitions',
        'idx_business_definition_version_state' => 'business_definition_versions',
        'idx_business_dependency_lookup' => 'business_definition_dependencies',
        'idx_business_field_type_active' => 'business_field_types',
        'idx_business_field_type_owner' => 'business_field_types',
        'idx_business_process_scope' => 'business_process_instances',
        'idx_business_process_status' => 'business_process_instances',
        'idx_business_process_work_claim' => 'business_process_work',
        'idx_business_process_work_process' => 'business_process_work',
        'idx_content_definition_versions' => 'content_entries',
        'idx_content_publication' => 'content_entries',
        'idx_content_site_locale' => 'content_entries',
        'idx_content_site_updated' => 'content_entries',
        'idx_content_type_version_site_handle' => 'content_type_definition_versions',
        'idx_demo_profile_asset_resource' => 'demo_profile_assets',
        'idx_demo_profile_installation_status' => 'demo_profile_installations',
        'idx_ext_resource_policy_capability' => 'extension_contribution_resource_policies',
        'idx_ext_resource_policy_owner' => 'extension_contribution_resource_policies',
        'idx_extension_contribution_capability_owner' => 'extension_contribution_capabilities',
        'idx_grants_lookup' => 'role_capability_grants',
        'idx_idempotency_expiry' => 'idempotency',
        'idx_integration_checkpoint_scope' => 'integration_consumer_checkpoints',
        'idx_integration_inbox_claim' => 'integration_inbox',
        'idx_integration_inbox_order' => 'integration_inbox',
        'idx_integration_inbox_queue' => 'integration_inbox',
        'idx_integration_inbox_scope' => 'integration_inbox',
        'idx_integration_outbox_aggregate' => 'integration_outbox',
        'idx_integration_outbox_claim' => 'integration_outbox',
        'idx_integration_outbox_correlation' => 'integration_outbox',
        'idx_integration_outbox_retention' => 'integration_outbox',
        'idx_integration_outbox_scope' => 'integration_outbox',
        'idx_job_claim' => 'jobs',
        'idx_membership_role' => 'membership_roles',
        'idx_membership_user_active' => 'organization_memberships',
        'idx_membership_workspace' => 'membership_workspaces',
        'idx_menu_item_order' => 'navigation_items',
        'idx_navigation_content' => 'navigation_items',
        'idx_org_site_status' => 'organizations',
        'idx_portal_session_scope' => 'portal_sessions',
        'idx_portal_session_user' => 'portal_sessions',
        'idx_projection_generation_active' => 'business_projection_generations',
        'idx_projection_generation_history' => 'business_projection_generations',
        'idx_projection_rows_generation' => 'business_projection_rows',
        'idx_projection_source_contract' => 'business_projection_source_events',
        'idx_report_exports_expiry' => 'business_report_export_artifacts',
        'idx_report_exports_scope' => 'business_report_export_artifacts',
        'idx_resource_policy_match' => 'resource_policies',
        'idx_resource_policy_owner' => 'resource_policies',
        'idx_resource_site' => 'resource_site_ownership',
        'idx_schedule_due' => 'schedules',
        'idx_sod_match' => 'separation_duty_rules',
        'idx_stepup_proof_session' => 'step_up_proofs',
        'idx_stepup_recovery_live' => 'step_up_recovery_codes',
        'idx_stepup_subject_status' => 'step_up_credentials',
        'idx_translation_group_site' => 'content_translation_groups',
        'idx_workflow_version_site_handle' => 'workflow_definition_versions',
        'idx_workspace_org_status' => 'workspaces',
        'uniq_admin_session_token' => 'administrator_sessions',
        'uniq_api_token_digest' => 'api_tokens',
        'uniq_approval_vote_actor' => 'approval_votes',
        'uniq_bcommand_idempotency_scope' => 'business_command_idempotency',
        'uniq_brecord_revision_number' => 'business_record_revisions',
        'uniq_bschema_plan_checksum' => 'business_schema_plans',
        'uniq_business_definition_checksum' => 'business_definition_versions',
        'uniq_business_definition_handle' => 'business_definitions',
        'uniq_business_process_correlation' => 'business_process_instances',
        'uniq_content_revision' => 'content_revisions',
        'uniq_content_site_slug' => 'content_entries',
        'uniq_content_slug' => 'content_entries',
        'uniq_content_translation_locale' => 'content_entries',
        'uniq_content_type_handle' => 'content_types',
        'uniq_content_type_site_handle' => 'content_types',
        'uniq_extension_identifier' => 'extensions',
        'uniq_extension_release' => 'extension_releases',
        'uniq_failed_job' => 'failed_jobs',
        'uniq_idempotency_scope' => 'idempotency',
        'uniq_job_occurrence' => 'jobs',
        'uniq_membership_org_user' => 'organization_memberships',
        'uniq_menu_handle' => 'navigation_menus',
        'uniq_menu_item_path' => 'navigation_items',
        'uniq_org_site_identifier' => 'organizations',
        'uniq_portal_session_digest' => 'portal_sessions',
        'uniq_projection_source_event' => 'business_projection_source_events',
        'uniq_resource_policy_code' => 'resource_policies',
        'uniq_roles_code' => 'roles',
        'uniq_schedule_contribution' => 'schedules',
        'uniq_schedule_name' => 'schedules',
        'uniq_sod_scope_action' => 'separation_duty_rules',
        'uniq_sod_scope_code' => 'separation_duty_rules',
        'uniq_stepup_proof_nonce' => 'step_up_proofs',
        'uniq_users_email' => 'users',
        'uniq_workflow_handle' => 'workflows',
        'uniq_workflow_site_handle' => 'workflows',
        'uniq_workspace_org_identifier' => 'workspaces',
    ];

    /**
     * The identity is a well-formed ledger key that sorts after every migration this build ships.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheIdentitySortsAfterTheMigrationsItFollows(): void
    {
        self::assertSame('20260823010000_schema_global_index_names', IndexNameIsolationMigration::ID);
        self::assertMatchesRegularExpression('/^[0-9]{14}_[a-z0-9_]+$/D', IndexNameIsolationMigration::ID);
        self::assertGreaterThan(NumberSequenceIdentityMigration::ID, IndexNameIsolationMigration::ID);
    }

    /**
     * The checksum binds this file and the delegated target-name derivation to the ledger entry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheChecksumBindsBothSourceFiles(): void
    {
        $migration = $this->migration();

        self::assertSame(IndexNameIsolationMigration::ID, $migration->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $migration->checksum());
        self::assertSame($migration->checksum(), $this->migration()->checksum());
        $source = (new ReflectionClass($migration))->getFileName();
        self::assertIsString($source);
        $sourceDigest = hash_file('sha256', $source);
        $originalDigest = hash_file('sha256', dirname($source) . '/ConstraintNameIsolationMigration.php');
        self::assertIsString($sourceDigest);
        self::assertIsString($originalDigest);
        self::assertSame(
            hash('sha256', $migration->id() . ':' . $sourceDigest . ':' . $originalDigest),
            $migration->checksum(),
        );
    }

    /**
     * The target derivation is the published constraint one, so both repairs speak one naming shape.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTargetDerivationMatchesThePublishedConstraintMigration(): void
    {
        self::assertSame(
            ConstraintNameIsolationMigration::isolatedName('kumwe_organizations', 'uniq_org_site_identifier'),
            IndexNameIsolationMigration::isolatedName('kumwe_organizations', 'uniq_org_site_identifier'),
        );
        self::assertSame(
            ConstraintNameIsolationMigration::MAXIMUM_IDENTIFIER_BYTES,
            IndexNameIsolationMigration::MAXIMUM_IDENTIFIER_BYTES,
        );
    }

    /**
     * Two installations of one schema derive a different name for the same index.
     *
     * This is the property the whole migration exists for: the name that used to be shared is now a
     * function of the physical table name, and two installations differ by prefix.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTwoInstallationsDeriveDifferentNamesForTheSameIndex(): void
    {
        $first = IndexNameIsolationMigration::isolatedName('kumwe_organizations', 'uniq_org_site_identifier');
        $second = IndexNameIsolationMigration::isolatedName('second_organizations', 'uniq_org_site_identifier');

        self::assertNotSame($first, $second);
        self::assertStringStartsWith('uniq_org_site_identifier_', $first);
        self::assertStringStartsWith('uniq_org_site_identifier_', $second);
        self::assertSame(
            $first,
            IndexNameIsolationMigration::isolatedName('kumwe_organizations', 'uniq_org_site_identifier'),
        );
    }

    /**
     * Every shipped literal, renamed under the longest prefix a site may configure, still fits.
     *
     * The budget is the tight one — PostgreSQL truncates an identifier at 63 bytes and MySQL refuses
     * an index name past 64 — and checking the whole inventory is what stops a future literal from
     * being added at a length that only fails on a customer's schema. Uniqueness per installation is
     * asserted alongside, so the truncation a long stem needs cannot make two names equal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryShippedLiteralFitsThePortableIdentifierBudgetOnceRenamed(): void
    {
        $prefix = str_repeat('a', 27) . '_';
        self::assertCount(110, self::SHIPPED_LITERALS);

        $isolated = [];
        foreach (self::SHIPPED_LITERALS as $stem => $table) {
            $renamed = IndexNameIsolationMigration::isolatedName($prefix . $table, $stem);
            self::assertLessThanOrEqual(
                IndexNameIsolationMigration::MAXIMUM_IDENTIFIER_BYTES,
                strlen($renamed),
                $stem,
            );
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/D', $renamed, $stem);
            $isolated[] = $renamed;
        }

        self::assertSame(count($isolated), count(array_unique($isolated)));
    }

    /**
     * Every shipped literal is still recognised as needing the rename.
     *
     * The skip rule has to be narrow enough that it does not accidentally exempt a real collision, so
     * the whole inventory is put through it rather than a sample.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryShippedLiteralIsRecognisedAsCollisionProne(): void
    {
        foreach (array_keys(self::SHIPPED_LITERALS) as $stem) {
            self::assertTrue(IndexNameIsolationMigration::needsIsolation((string) $stem), (string) $stem);
        }
    }

    /**
     * Every already-unique shipped shape is left exactly as it is, and no valid prefix hides a literal.
     *
     * Four derivation families ship: this migration's own sixteen-hex targets, the twenty-hex
     * business-schema compiler names, the twenty-four-hex translation-group name, and DBAL's implicit
     * foreign-key indexes, spelled uppercase on the MySQL family and folded to lowercase by
     * PostgreSQL. A fifteen-hex implicit tail is deliberately renamed — it is unique already, but only
     * the sixteen-or-more rule is proof — and a name that merely starts with the installation's prefix
     * is renamed too, because `idx_` and `uniq_` are themselves valid table prefixes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyDigestSuffixedNamesAreAlreadyIsolated(): void
    {
        $own = IndexNameIsolationMigration::isolatedName('kumwe_organizations', 'uniq_org_site_identifier');

        self::assertFalse(IndexNameIsolationMigration::needsIsolation($own));
        self::assertFalse(IndexNameIsolationMigration::needsIsolation(
            'idx_brecord_revision_window_' . substr(hash('sha256', 'kumwe_business_record_revisions'), 0, 16),
        ));
        self::assertFalse(IndexNameIsolationMigration::needsIsolation(
            'kb_i_orders_field_code_' . substr(hash('sha256', 'business-schema'), 0, 20),
        ));
        self::assertFalse(IndexNameIsolationMigration::needsIsolation(
            'uniq_' . substr(hash('sha256', 'kumwe_content_translation_groups:id:site_identifier'), 0, 24),
        ));
        self::assertFalse(IndexNameIsolationMigration::needsIsolation('IDX_380B5C5E57270CAF'));
        self::assertFalse(IndexNameIsolationMigration::needsIsolation('idx_380b5c5e57270caf'));
        self::assertFalse(IndexNameIsolationMigration::needsIsolation(''));
        self::assertTrue(IndexNameIsolationMigration::needsIsolation('IDX_8B34472AD46468E'));
        self::assertTrue(IndexNameIsolationMigration::needsIsolation('kumwe_idx_job_recovery'));
        self::assertTrue(IndexNameIsolationMigration::needsIsolation('idx_idx_job_claim'));
    }

    /**
     * An index name the schema reports as empty is refused rather than renamed to nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEmptyStemIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        IndexNameIsolationMigration::isolatedName('kumwe_organizations', '');
    }

    /**
     * PostgreSQL renames the index in its catalogue instead of rebuilding it.
     *
     * The engine keeps index names in a schema-wide namespace and has a rename for one, so the repair
     * there is a single statement that keeps the built structure rather than a drop and a recreate
     * that would rebuild it. This is the branch a MariaDB test run never reaches, so the statement it
     * composes is asserted directly from the platform, together with the resume that drops only the
     * verified old twin.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPostgreSqlRenamesTheIndexInPlaceRatherThanRebuildingIt(): void
    {
        $database = $this->offlineConnection('pdo_pgsql', '17.0');
        $target = IndexNameIsolationMigration::isolatedName('kumwe_organizations', 'idx_org_site_status');

        self::assertSame(
            [sprintf('ALTER INDEX "idx_org_site_status" RENAME TO "%s"', $target)],
            $this->renameStatements($database, $target, false),
        );
        self::assertSame(
            ['DROP INDEX "idx_org_site_status"'],
            $this->renameStatements($database, $target, true),
        );
    }

    /**
     * The current MySQL family renames the index in place, and a verified replay drops only the twin.
     *
     * `ALTER TABLE … RENAME INDEX` is a metadata change on MySQL 8 and MariaDB 10.5.2 or later, so an
     * installed site pays no rebuild for the repair even where the collision never existed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheMySqlFamilyRenamesTheIndexInPlace(): void
    {
        $target = IndexNameIsolationMigration::isolatedName('kumwe_organizations', 'idx_org_site_status');

        foreach (['10.11.14-MariaDB', '8.4.0'] as $serverVersion) {
            $database = $this->offlineConnection('pdo_mysql', $serverVersion);
            self::assertSame(
                [sprintf('ALTER TABLE `kumwe_organizations` RENAME INDEX `idx_org_site_status` TO `%s`', $target)],
                $this->renameStatements($database, $target, false),
                $serverVersion,
            );
            self::assertSame(
                ['DROP INDEX `idx_org_site_status` ON `kumwe_organizations`'],
                $this->renameStatements($database, $target, true),
                $serverVersion,
            );
        }
    }

    /**
     * A MariaDB without `RENAME INDEX` creates the isolated name first and only then drops the shared one.
     *
     * The order is the migration's whole replay story on that platform: where DDL commits implicitly,
     * an attempt interrupted between the two statements has already committed the first, and dropping
     * first would leave the table without the index for a replay to find. The recreation carries the
     * uniqueness across, because that is where a unique index would silently become a plain one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMariaDbWithoutRenameIndexCreatesTheIsolatedNameBeforeDroppingTheSharedOne(): void
    {
        $database = $this->offlineConnection('pdo_mysql', '10.4.3-MariaDB');
        $target = IndexNameIsolationMigration::isolatedName('kumwe_organizations', 'uniq_org_site_identifier');

        $statements = $this->renameStatements($database, $target, false, unique: true);

        self::assertSame(
            [
                sprintf(
                    'CREATE UNIQUE INDEX %s ON `kumwe_organizations` (site_identifier, identifier)',
                    $target,
                ),
                'DROP INDEX `uniq_org_site_identifier` ON `kumwe_organizations`',
            ],
            $statements,
        );
    }

    /**
     * Replay equality covers the type, the ordered columns with lengths, and the predicate.
     *
     * The replay decision is what stands between a resume and dropping a live index in favour of an
     * unrelated one, so a mismatch in any structural property must be treated as a different index.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReplayTargetShapeIncludesTypeColumnsAndOrder(): void
    {
        $migration = $this->migration();
        $sameShape = new ReflectionMethod($migration, 'sameShape');
        $source = $this->index('uniq_org_site_identifier', true, 'site_identifier', 'identifier');
        $matching = $this->index('isolated', true, 'site_identifier', 'identifier');
        $wrongType = $this->index('isolated', false, 'site_identifier', 'identifier');
        $wrongColumns = $this->index('isolated', true, 'identifier', 'site_identifier');

        self::assertSame(true, $sameShape->invoke($migration, $source, $matching));
        self::assertSame(false, $sameShape->invoke($migration, $source, $wrongType));
        self::assertSame(false, $sameShape->invoke($migration, $source, $wrongColumns));
    }

    /**
     * Migration ledgers partition a shorter prefix from its initialized longer-prefix neighbour.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOverlappingPrefixesArePartitionedByTheirLedgers(): void
    {
        $migration = $this->migration();
        /** @var list<string> $prefixes */
        $prefixes = (new ReflectionMethod($migration, 'installationPrefixes'))->invoke($migration, [
            'app_schema_migrations',
            'app_sites',
            'app_two_schema_migrations',
            'app_two_sites',
        ]);
        $belongs = new ReflectionMethod($migration, 'belongsToInstallation');

        self::assertSame(['app_', 'app_two_'], $prefixes);
        self::assertSame(true, $belongs->invoke($migration, 'app_sites', 'app_', $prefixes));
        self::assertSame(false, $belongs->invoke($migration, 'app_two_sites', 'app_', $prefixes));
        self::assertSame(true, $belongs->invoke($migration, 'app_two_sites', 'app_two_', $prefixes));
    }

    /**
     * Compose one rename through the private platform branch under test.
     *
     * @param   Connection  $database  Offline connection selecting the SQL platform.
     * @param   string      $target    Isolated target name.
     * @param   bool        $created   Whether that target already exists on the table.
     * @param   bool        $unique    Whether the composed source index enforces uniqueness.
     *
     * @return  list<string>  Statements in execution order.
     *
     * @since   2.0.0
     */
    private function renameStatements(
        Connection $database,
        string $target,
        bool $created,
        bool $unique = false,
    ): array {
        $migration = new IndexNameIsolationMigration(new TableNames($database, 'kumwe_'));
        $stem = $unique ? 'uniq_org_site_identifier' : 'idx_org_site_status';
        $columns = $unique ? ['site_identifier', 'identifier'] : ['site_identifier', 'status'];
        /** @var list<string> $statements */
        $statements = (new ReflectionMethod($migration, 'renameStatements'))->invoke(
            $migration,
            $database,
            'kumwe_organizations',
            $stem,
            $target,
            $this->index($stem, $unique, ...$columns),
            $created,
        );

        return $statements;
    }

    /**
     * Build one named index shape for statement and replay comparisons.
     *
     * @param   string  $name     Index name, irrelevant to structural equality.
     * @param   bool    $unique   Whether the index enforces uniqueness.
     * @param   string  $columns  Indexed column names, in index order.
     *
     * @return  Index  Index over the given columns.
     *
     * @since   2.0.0
     */
    private function index(string $name, bool $unique, string ...$columns): Index
    {
        return Index::editor()
            ->setUnquotedName($name)
            ->setType($unique ? IndexType::UNIQUE : IndexType::REGULAR)
            ->setUnquotedColumnNames(...$columns)
            ->create();
    }

    /**
     * Open a connection to no server at all, for the engines a run is not measured on.
     *
     * Declaring the server version is what lets the platform be decided without connecting, which is
     * how the PostgreSQL branch is exercised on a MariaDB test run. No statement is ever executed.
     *
     * @param   string  $driver         Doctrine driver name selecting the platform family.
     * @param   string  $serverVersion  Version string the platform is chosen from.
     *
     * @return  Connection  Connection that knows its platform and has never opened a socket.
     *
     * @since   2.0.0
     */
    private function offlineConnection(string $driver, string $serverVersion): Connection
    {
        return DriverManager::getConnection(['driver' => $driver, 'serverVersion' => $serverVersion]);
    }

    /**
     * Build the migration over a prefixed table map.
     *
     * @return  IndexNameIsolationMigration  Migration bound to a stub connection's table map.
     *
     * @since   2.0.0
     */
    private function migration(): IndexNameIsolationMigration
    {
        return new IndexNameIsolationMigration(
            new TableNames($this->createStub(Connection::class), 'kumwe_'),
        );
    }
}
