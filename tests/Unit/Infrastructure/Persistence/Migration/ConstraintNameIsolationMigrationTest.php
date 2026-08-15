<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ResourceOwnershipScopeMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the constraint-renaming rule, which is what makes two installations in one schema possible.
 *
 * The naming rule is the whole migration: everything else is schema plumbing around it. These assert
 * the three properties a second installation depends on — that the name differs between installations,
 * that it fits the smallest identifier limit across the supported engines, and that running the rename
 * twice is a no-op rather than a second rename.
 *
 * @since  2.0.0
 */
#[CoversClass(ConstraintNameIsolationMigration::class)]
final class ConstraintNameIsolationMigrationTest extends TestCase
{
    /**
     * Every foreign-key name the shipped migrations spell literally, with the table it sits on.
     *
     * Held here rather than discovered so that adding a fifty-fifth literal is a deliberate edit to a
     * declared inventory, and so the identifier-budget assertion below has something total to check.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array SHIPPED_LITERALS = [
        'fk_resource_site' => 'resource_site_ownership',
        'fk_business_draft_definition' => 'business_definition_drafts',
        'fk_business_version_definition' => 'business_definition_versions',
        'fk_business_dependency_version' => 'business_definition_dependencies',
        'fk_business_process_work_process' => 'business_process_work',
        'fk_projection_rows_generation' => 'business_projection_rows',
        'fk_org_site' => 'organizations',
        'fk_workspace_org' => 'workspaces',
        'fk_membership_org' => 'organization_memberships',
        'fk_membership_user' => 'organization_memberships',
        'fk_mworkspace_membership' => 'membership_workspaces',
        'fk_mworkspace_workspace' => 'membership_workspaces',
        'fk_mrole_membership' => 'membership_roles',
        'fk_mrole_role' => 'membership_roles',
        'fk_ext_resource_policy_owner' => 'extension_resource_policies',
        'fk_ext_resource_policy_capability' => 'extension_resource_policies',
        'fk_resource_policy_capability' => 'resource_policies',
        'fk_resource_policy_org' => 'resource_policies',
        'fk_resource_policy_definition' => 'resource_policies',
        'fk_sod_site' => 'separation_duty_rules',
        'fk_sod_org' => 'separation_duty_rules',
        'fk_sod_requester_role' => 'separation_duty_rules',
        'fk_sod_approver_role' => 'separation_duty_rules',
        'fk_approval_rule' => 'approval_requests',
        'fk_approval_site' => 'approval_requests',
        'fk_approval_org' => 'approval_requests',
        'fk_approval_workspace' => 'approval_requests',
        'fk_approval_approver_role' => 'approval_requests',
        'fk_approval_vote_request' => 'approval_votes',
        'fk_stepup_subject' => 'step_up_credentials',
        'fk_stepup_recovery_credential' => 'step_up_recovery_codes',
        'fk_portal_session_user' => 'portal_sessions',
        'fk_bschema_install_definition' => 'business_schema_installations',
        'fk_bschema_plan_definition' => 'business_schema_plans',
        'fk_bschema_plan_recovery' => 'business_schema_plans',
        'fk_bschema_step_plan' => 'business_schema_plan_steps',
        'fk_brecord_revision_definition' => 'business_record_revisions',
        'fk_password_user' => 'password_credentials',
        'fk_user_roles_user' => 'user_roles',
        'fk_user_roles_role' => 'user_roles',
        'fk_grants_role' => 'role_capability_grants',
        'fk_grants_capability' => 'role_capability_grants',
        'fk_session_user' => 'administrator_sessions',
        'fk_token_user' => 'api_tokens',
        'fk_state_workflow' => 'workflow_states',
        'fk_type_workflow' => 'content_types',
        'fk_entry_type' => 'content_entries',
        'fk_revision_entry' => 'content_revisions',
        'fk_item_menu' => 'navigation_items',
        'fk_release_extension' => 'extension_releases',
        'fk_dependency_release' => 'extension_dependencies',
        'fk_demo_profile_asset_installation' => 'demo_profile_assets',
        'fk_extension_contribution_capability_owner' => 'extension_contribution_capabilities',
        'fk_extension_contribution_capability_definition' => 'extension_contribution_capabilities',
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
        self::assertSame(
            '20260818010000_schema_global_constraint_names',
            ConstraintNameIsolationMigration::ID,
        );
        self::assertMatchesRegularExpression(
            '/^[0-9]{14}_[a-z0-9_]+$/D',
            ConstraintNameIsolationMigration::ID,
        );
        self::assertGreaterThan(
            ResourceOwnershipScopeMigration::ID,
            ConstraintNameIsolationMigration::ID,
        );
    }

    /**
     * The checksum is derived from this build's bytes and has the shape the plan accepts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheChecksumBindsTheLedgerEntryToThisExactImplementation(): void
    {
        $migration = $this->migration();

        self::assertSame(ConstraintNameIsolationMigration::ID, $migration->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $migration->checksum());
        self::assertSame($migration->checksum(), $this->migration()->checksum());
    }

    /**
     * Two installations of one schema derive a different name for the same constraint.
     *
     * This is the property the whole migration exists for: the name that used to be shared is now a
     * function of the physical table name, and two installations differ by prefix.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTwoInstallationsDeriveDifferentNamesForTheSameConstraint(): void
    {
        $first = ConstraintNameIsolationMigration::isolatedName('kumwe_organizations', 'fk_org_site');
        $second = ConstraintNameIsolationMigration::isolatedName('second_organizations', 'fk_org_site');

        self::assertNotSame($first, $second);
        self::assertStringStartsWith('fk_org_site_', $first);
        self::assertStringStartsWith('fk_org_site_', $second);
        self::assertSame(
            $first,
            ConstraintNameIsolationMigration::isolatedName('kumwe_organizations', 'fk_org_site'),
        );
    }

    /**
     * Every shipped literal, renamed under the longest prefix a site may configure, still fits.
     *
     * The budget is the tight one — PostgreSQL truncates an identifier at 63 bytes and MySQL refuses a
     * constraint name past 64 — and the longest shipped stem is 47 characters, so an unbudgeted rename
     * would have overflowed. Checking the whole inventory is what stops a future literal from being
     * added at a length that only fails on a customer's schema.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryShippedLiteralFitsThePortableIdentifierBudgetOnceRenamed(): void
    {
        $prefix = str_repeat('a', 27) . '_';
        self::assertCount(54, self::SHIPPED_LITERALS);

        foreach (self::SHIPPED_LITERALS as $stem => $table) {
            $isolated = ConstraintNameIsolationMigration::isolatedName($prefix . $table, $stem);
            self::assertLessThanOrEqual(
                ConstraintNameIsolationMigration::MAXIMUM_IDENTIFIER_BYTES,
                strlen($isolated),
                $stem,
            );
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/D', $isolated, $stem);
        }
    }

    /**
     * No two constraints on one table collide once the longest stems have been trimmed to fit.
     *
     * The two extension-contribution names share their first forty-two characters and one of them is
     * trimmed, so the digest is what has to keep them apart. Asserting uniqueness across the whole
     * inventory per table proves the digest is doing that job rather than the surviving stem.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTrimmingALongStemNeverMakesTwoConstraintsOnOneTableEqual(): void
    {
        $isolated = [];
        foreach (self::SHIPPED_LITERALS as $stem => $table) {
            $isolated[] = ConstraintNameIsolationMigration::isolatedName('kumwe_' . $table, $stem);
        }

        self::assertSame(count($isolated), count(array_unique($isolated)));
    }

    /**
     * A name that is already unique to this installation is left exactly as it is.
     *
     * Both already-safe shapes are covered: the prefixed names one shipped migration composes, and the
     * digest-suffixed names the three that always derived produce, plus a name this migration has
     * already renamed once. Re-isolating any of them would rename a constraint on every upgrade.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAlreadyUniqueNamesAreLeftAlone(): void
    {
        $already = ConstraintNameIsolationMigration::isolatedName('kumwe_organizations', 'fk_org_site');

        self::assertFalse(ConstraintNameIsolationMigration::needsIsolation($already, 'kumwe_'));
        self::assertFalse(ConstraintNameIsolationMigration::needsIsolation(
            'kumwe_fk_site_group_member_group',
            'kumwe_',
        ));
        self::assertFalse(ConstraintNameIsolationMigration::needsIsolation(
            'fk_resource_site_' . substr(hash('sha256', 'kumwe_resource_site_ownership'), 0, 16),
            'kumwe_',
        ));
        self::assertFalse(ConstraintNameIsolationMigration::needsIsolation('', 'kumwe_'));
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
            self::assertTrue(
                ConstraintNameIsolationMigration::needsIsolation((string) $stem, 'kumwe_'),
                (string) $stem,
            );
        }
    }

    /**
     * A constraint the schema reports without a name is refused rather than renamed to nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnnamedConstraintIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        ConstraintNameIsolationMigration::isolatedName('kumwe_organizations', '');
    }

    /**
     * Build the migration over a prefixed table map.
     *
     * @return  ConstraintNameIsolationMigration  Migration bound to a stub connection's table map.
     *
     * @since   2.0.0
     */
    private function migration(): ConstraintNameIsolationMigration
    {
        return new ConstraintNameIsolationMigration(
            new TableNames($this->createStub(Connection::class), 'kumwe_'),
        );
    }
}
