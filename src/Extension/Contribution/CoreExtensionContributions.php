<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use Kumwe\CMS\Application\Authorization\ResourcePolicyTarget;
use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\BusinessDefinition\Domain\BuiltInFieldTypes;
use Kumwe\CMS\Portal\Contribution\PortalNavigationDefinition;
use Kumwe\CMS\Portal\Contribution\PortalWorkspaceDefinition;

/**
 * Everything the CMS contributes to the contribution registries on its own behalf.
 *
 * `ExtensionContributionRegistrySet` applies this once while it is being built, through the same
 * registrar extensions use, so core has no privileged path into the registries. Order matters inside
 * it: capabilities precede resource policies, and capabilities and workspaces precede navigation items,
 * because a contribution may only name something its own owner has already claimed.
 * Everything here is declared as data, so the shipped core surface is readable in one file.
 *
 * @since  2.0.0
 */
final class CoreExtensionContributions
{
    /**
     * The core capability vocabulary, mapping each capability identifier to its operator-facing wording.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const CAPABILITIES = [
        'administrator.access' => 'Enter and use the authenticated administrator surface.',
        'administrator.bootstrap' => 'Create the first administrator through host bootstrap authority.',
        'automation.manage' => 'Manage schedules and background work.',
        'business.approval.approve' => 'Approve or reject high-impact business operations.',
        'business.approval.manage' => 'Revoke and administer high-impact approval requests.',
        'business.approval.request' => 'Request approval for a bound high-impact business operation.',
        'business.record.action' => 'Execute declared business-record actions and workflow transitions.',
        'business.record.archive' => 'Archive business records.',
        'business.record.browse' => 'Browse business records through bounded typed queries.',
        'business.record.create' => 'Create business records.',
        'business.record.delete' => 'Delete business records under their declared lifecycle policy.',
        'business.record.export' => 'Export authorized business-record projections.',
        'business.record.history' => 'Read business-record revision history.',
        'business.record.read' => 'Read individual business records.',
        'business.record.relate' => 'Manage business-record relationships and ordered lines.',
        'business.record.report' => 'Run authorized business-record reports and aggregates.',
        'business.record.restore' => 'Restore soft-deleted business records.',
        'business.record.transition' => 'Move business records through declared workflow transitions.',
        'business.record.update' => 'Update business records.',
        'business.schema.approve' => 'Approve an exact persisted business schema plan.',
        'business.schema.destructive' => 'Authorize destructive business schema changes.',
        'business.schema.execute' => 'Execute an approved business schema plan.',
        'business.schema.plan' => 'Create deterministic business schema plans.',
        'business.schema.read' => 'Inspect business schema plans, installations, and execution journals.',
        'business.schema.recover' => 'Resume or reconcile interrupted business schema execution.',
        'business.security.manage' => 'Manage organization policy and separation-of-duty controls.',
        'business.step_up.manage' => 'Manage production step-up credentials and recovery material.',
        'content.archive' => 'Archive content.',
        'content.create' => 'Create content.',
        'content.delete' => 'Permanently delete content and media where lifecycle rules permit.',
        'content.publish' => 'Publish approved content.',
        'content.read' => 'Read content, media, models, and workflows.',
        'content.restore' => 'Restore archived content.',
        'content.review' => 'Review content awaiting a publishing decision.',
        'content.submit' => 'Submit content for review.',
        'content.unpublish' => 'Withdraw published content.',
        'content.update' => 'Update content, media, models, and workflows.',
        'extensions.manage' => 'Install and manage extensions and trust.',
        'navigation.manage' => 'Manage public navigation.',
        'portal.access' => 'Enter and use the authenticated portal surface.',
        'settings.manage' => 'Manage site settings.',
        'system.migrate' => 'Apply and recover database schema migrations.',
        'system.scheduler.dispatch' => 'Dispatch due schedules into the durable work queue.',
        'system.worker.operate' => 'Operate durable background work queues.',
        'themes.administrator.manage' => 'Manage the installation-wide administrator theme.',
        'themes.site.manage' => 'Manage a site theme.',
        'users.manage' => 'Manage users, roles, permissions, and tokens.',
    ];

    /**
     * Capabilities whose delegation and exercise have installation-wide or destructive consequences.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const HIGH_IMPACT_CAPABILITIES = [
        'administrator.bootstrap',
        'business.approval.approve',
        'business.approval.manage',
        'business.record.action',
        'business.record.delete',
        'business.schema.approve',
        'business.schema.destructive',
        'business.schema.execute',
        'business.schema.recover',
        'business.security.manage',
        'business.step_up.manage',
        'content.delete',
        'extensions.manage',
        'system.migrate',
        'themes.administrator.manage',
        'users.manage',
    ];

    /**
     * Human capabilities whose grants may only be installation-global.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const GLOBAL_ONLY_CAPABILITIES = [
        'extensions.manage',
        'themes.administrator.manage',
        'users.manage',
    ];

    /**
     * Register the whole core contribution set through the given registrar.
     *
     * Capability labels are derived from the identifier — dots become spaces and the words are
     * title-cased — so the capability map only has to carry descriptions. Core is registered through
     * a non-strict registrar, so unlike an extension it is not matched against a manifest declaration.
     *
     * @param   ExtensionContributionRegistrar  $registrar  Registrar bound to the core contribution owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function register(ExtensionContributionRegistrar $registrar): void
    {
        foreach (BuiltInFieldTypes::all() as $fieldType) {
            $registrar->fieldType($fieldType);
        }
        $policies = self::resourcePolicyDefinitions();
        foreach (self::capabilityDefinitionsFor($policies) as $definition) {
            $registrar->capability($definition);
        }
        foreach ($policies as $policy) {
            $registrar->resourcePolicy($policy);
        }
        foreach (
            [
                ['core.workspace', 'Workspace', 'Daily content and publishing work.', 10],
                ['core.structure', 'Structure', 'Content structure and public navigation.', 20],
                ['core.system', 'System', 'Identity, extensions, automation, and settings.', 30],
            ] as [$id, $label, $description, $priority]
        ) {
            $registrar->administratorWorkspace(new AdministratorWorkspaceDefinition(
                $id,
                $label,
                $description,
                $priority,
            ));
        }
        foreach (self::navigation() as $definition) {
            $registrar->administratorNavigation($definition);
        }
        $registrar->portalWorkspace(new PortalWorkspaceDefinition(
            'core.portal',
            'Portal',
            'Your authenticated business workspace.',
            10,
        ));
        $registrar->portalNavigation(new PortalNavigationDefinition(
            'core.portal-home',
            'core.portal',
            'Overview',
            'Open your business workspace.',
            '/portal',
            'home',
            'portal.access',
            10,
            'home overview workspace',
        ));
        $registrar->portalNavigation(new PortalNavigationDefinition(
            'core.portal-security',
            'core.portal',
            'Account security',
            'Manage authenticator and recovery verification.',
            '/portal/security',
            'shield',
            'portal.access',
            20,
            'account security authenticator recovery',
        ));
        $registrar->portalNavigation(new PortalNavigationDefinition(
            'core.portal-approvals',
            'core.portal',
            'Approvals',
            'Review scoped maker-checker requests.',
            '/portal/approvals',
            'check-circle',
            'portal.access',
            15,
            'approvals inbox decisions',
        ));
    }

    /**
     * Build the typed core capability catalog used by runtime registration and persistence backfills.
     *
     * Returning definitions rather than exposing the underlying wording maps keeps every consumer on
     * the same derivation of allowed scopes, delegation, impact, lifecycle, and version metadata.
     *
     * @return  list<CapabilityDefinition>  Core definitions in deterministic capability order.
     *
     * @since   2.0.0
     */
    public static function capabilityDefinitions(): array
    {
        return self::capabilityDefinitionsFor(self::resourcePolicyDefinitions());
    }

    /**
     * Build the full core action/resource and unattended-authority vocabulary as typed definitions.
     *
     * Splitting installation-global targets from site-owned targets preserves the existing grant
     * semantics without branches in the gateway. System identities are declared only on the exact
     * binding they need, so a worker's content authority cannot bleed into an unrelated resource type.
     *
     * @return  list<ResourcePolicyDefinition>  Core policies in deterministic declaration order.
     *
     * @since   2.0.0
     */
    public static function resourcePolicyDefinitions(): array
    {
        return [
            self::policy(
                'core.administrator.access',
                'administrator.access',
                [new ResourcePolicyTarget('administrator_session')],
            ),
            self::policy(
                'core.administrator.bootstrap',
                'administrator.bootstrap',
                [new ResourcePolicyTarget('administrator')],
                systems: [SystemIdentity::Bootstrap],
            ),
            self::policy(
                'core.automation.manage.site',
                'automation.manage',
                [
                    new ResourcePolicyTarget('administrator_session'),
                    new ResourcePolicyTarget('job'),
                    new ResourcePolicyTarget('queue'),
                    new ResourcePolicyTarget('schedule'),
                ],
                systems: [SystemIdentity::InstallationMaintenance, SystemIdentity::Worker],
            ),
            self::policy(
                'core.automation.manage.installation',
                'automation.manage',
                [new ResourcePolicyTarget('automation_installation')],
                installationGlobal: true,
                systems: [SystemIdentity::InstallationMaintenance],
            ),
            self::policy('core.business.approval.approve', 'business.approval.approve', [
                new ResourcePolicyTarget('approval_request'),
                new ResourcePolicyTarget('organization'),
                new ResourcePolicyTarget('workspace'),
            ]),
            self::policy('core.business.approval.manage', 'business.approval.manage', [
                new ResourcePolicyTarget('approval_request'),
                new ResourcePolicyTarget('organization'),
                new ResourcePolicyTarget('workspace'),
            ]),
            self::policy('core.business.approval.request', 'business.approval.request', [
                new ResourcePolicyTarget('api_token'),
                new ResourcePolicyTarget('approval_request'),
                new ResourcePolicyTarget('business_record'),
                new ResourcePolicyTarget('business_schema'),
                new ResourcePolicyTarget('content'),
                new ResourcePolicyTarget('extension'),
                new ResourcePolicyTarget('grant'),
                new ResourcePolicyTarget('job'),
                new ResourcePolicyTarget('organization'),
                new ResourcePolicyTarget('queue'),
                new ResourcePolicyTarget('role'),
                new ResourcePolicyTarget('schedule'),
                new ResourcePolicyTarget('site'),
                new ResourcePolicyTarget('theme'),
                new ResourcePolicyTarget('user'),
                new ResourcePolicyTarget('workspace'),
            ]),
            self::policy('core.business.record.action', 'business.record.action', [
                new ResourcePolicyTarget('business_record'),
            ]),
            self::policy('core.business.record.archive', 'business.record.archive', [
                new ResourcePolicyTarget('business_record'),
            ]),
            self::policy('core.business.record.browse', 'business.record.browse', [
                new ResourcePolicyTarget('business_record'),
            ]),
            self::policy('core.business.record.create', 'business.record.create', [
                new ResourcePolicyTarget('business_record'),
            ]),
            self::policy('core.business.record.delete', 'business.record.delete', [
                new ResourcePolicyTarget('business_record'),
            ]),
            self::policy('core.business.record.export', 'business.record.export', [
                new ResourcePolicyTarget('business_record'),
            ]),
            self::policy('core.business.record.history', 'business.record.history', [
                new ResourcePolicyTarget('business_record'),
            ]),
            self::policy('core.business.record.read', 'business.record.read', [
                new ResourcePolicyTarget('business_record'),
            ]),
            self::policy('core.business.record.relate', 'business.record.relate', [
                new ResourcePolicyTarget('business_record'),
            ]),
            self::policy('core.business.record.report', 'business.record.report', [
                new ResourcePolicyTarget('business_record'),
            ]),
            self::policy('core.business.record.restore', 'business.record.restore', [
                new ResourcePolicyTarget('business_record'),
            ]),
            self::policy('core.business.record.transition', 'business.record.transition', [
                new ResourcePolicyTarget('business_record'),
            ]),
            self::policy('core.business.record.update', 'business.record.update', [
                new ResourcePolicyTarget('business_record'),
            ]),
            self::policy('core.business.schema.approve', 'business.schema.approve', [
                new ResourcePolicyTarget('business_schema'),
            ]),
            self::policy('core.business.schema.destructive', 'business.schema.destructive', [
                new ResourcePolicyTarget('business_schema'),
            ]),
            self::policy('core.business.schema.execute', 'business.schema.execute', [
                new ResourcePolicyTarget('business_schema'),
            ]),
            self::policy('core.business.schema.plan', 'business.schema.plan', [
                new ResourcePolicyTarget('business_schema'),
            ]),
            self::policy('core.business.schema.read', 'business.schema.read', [
                new ResourcePolicyTarget('business_schema'),
            ]),
            self::policy('core.business.schema.recover', 'business.schema.recover', [
                new ResourcePolicyTarget('business_schema'),
            ]),
            self::policy('core.business.security.manage', 'business.security.manage', [
                new ResourcePolicyTarget('organization'),
                new ResourcePolicyTarget('organization_membership'),
                new ResourcePolicyTarget('resource_policy'),
                new ResourcePolicyTarget('separation_duty_rule'),
                new ResourcePolicyTarget('workspace'),
            ]),
            self::policy('core.business.step_up.manage', 'business.step_up.manage', [
                new ResourcePolicyTarget('organization'),
                new ResourcePolicyTarget('step_up_credential'),
                new ResourcePolicyTarget('user'),
                new ResourcePolicyTarget('workspace'),
            ]),
            self::policy('core.content.archive', 'content.archive', [new ResourcePolicyTarget('content')], systems: [
                SystemIdentity::Worker,
            ]),
            self::policy('core.content.create', 'content.create', [new ResourcePolicyTarget('content')]),
            self::policy('core.content.delete', 'content.delete', [
                new ResourcePolicyTarget('content'),
                new ResourcePolicyTarget('media'),
            ]),
            self::policy('core.content.publish', 'content.publish', [new ResourcePolicyTarget('content')], systems: [
                SystemIdentity::Worker,
            ]),
            self::policy('core.content.read', 'content.read', [
                new ResourcePolicyTarget('business_definition'),
                new ResourcePolicyTarget('content'),
                new ResourcePolicyTarget('content_type'),
                new ResourcePolicyTarget('media'),
                new ResourcePolicyTarget('workflow'),
            ], systems: [SystemIdentity::Worker]),
            self::policy('core.content.restore', 'content.restore', [new ResourcePolicyTarget('content')], systems: [
                SystemIdentity::Worker,
            ]),
            self::policy('core.content.review', 'content.review', [new ResourcePolicyTarget('content')], systems: [
                SystemIdentity::Worker,
            ]),
            self::policy('core.content.submit', 'content.submit', [new ResourcePolicyTarget('content')], systems: [
                SystemIdentity::Worker,
            ]),
            self::policy(
                'core.content.unpublish',
                'content.unpublish',
                [new ResourcePolicyTarget('content')],
                systems: [SystemIdentity::Worker],
            ),
            self::policy('core.content.update', 'content.update', [
                new ResourcePolicyTarget('business_definition'),
                new ResourcePolicyTarget('content'),
                new ResourcePolicyTarget('content_type'),
                new ResourcePolicyTarget('media'),
                new ResourcePolicyTarget('workflow'),
            ], systems: [SystemIdentity::Worker]),
            self::policy('core.extensions.manage', 'extensions.manage', [
                new ResourcePolicyTarget('extension'),
                new ResourcePolicyTarget('extension_runtime_map'),
                new ResourcePolicyTarget('extension_trust_key'),
            ], installationGlobal: true, systems: [SystemIdentity::ExtensionMaterializer]),
            self::policy('core.navigation.manage', 'navigation.manage', [
                new ResourcePolicyTarget('menu'),
                new ResourcePolicyTarget('menu_item'),
            ]),
            self::policy(
                'core.portal.access',
                'portal.access',
                [
                    new ResourcePolicyTarget('organization'),
                    new ResourcePolicyTarget('portal_session'),
                    new ResourcePolicyTarget('workspace'),
                ],
            ),
            self::policy('core.settings.manage', 'settings.manage', [new ResourcePolicyTarget('site')]),
            self::policy(
                'core.system.migrate',
                'system.migrate',
                [new ResourcePolicyTarget('database_schema')],
                systems: [SystemIdentity::Migration],
            ),
            self::policy(
                'core.system.scheduler.dispatch',
                'system.scheduler.dispatch',
                [new ResourcePolicyTarget('schedule')],
                systems: [SystemIdentity::Scheduler],
            ),
            self::policy(
                'core.system.worker.operate',
                'system.worker.operate',
                [new ResourcePolicyTarget('job'), new ResourcePolicyTarget('queue')],
                systems: [SystemIdentity::Worker],
            ),
            self::policy(
                'core.themes.administrator.manage',
                'themes.administrator.manage',
                [new ResourcePolicyTarget('theme', ['administrator'])],
                installationGlobal: true,
            ),
            self::policy(
                'core.themes.site.manage',
                'themes.site.manage',
                [new ResourcePolicyTarget('theme', ['site'])],
            ),
            self::policy('core.users.manage.site', 'users.manage', [
                new ResourcePolicyTarget('api_token'),
                new ResourcePolicyTarget('site'),
            ]),
            self::policy('core.users.manage.installation', 'users.manage', [
                new ResourcePolicyTarget('capability'),
                new ResourcePolicyTarget('grant'),
                new ResourcePolicyTarget('role'),
                new ResourcePolicyTarget('user'),
            ], installationGlobal: true),
        ];
    }

    /**
     * Derive typed core capabilities from the resource-policy set used in the same registration phase.
     *
     * @param   list<ResourcePolicyDefinition>  $policies  Complete typed core resource-policy set.
     *
     * @return  list<CapabilityDefinition>  Definitions in the capability map's deterministic order.
     *
     * @since   2.0.0
     */
    private static function capabilityDefinitionsFor(array $policies): array
    {
        $definitions = [];
        foreach (self::CAPABILITIES as $id => $description) {
            $systemOnly = $id === 'administrator.bootstrap' || str_starts_with($id, 'system.');
            $definitions[] = new CapabilityDefinition(
                $id,
                ucwords(str_replace('.', ' ', $id)),
                $description,
                $systemOnly ? [] : self::allowedScopes($id, $policies),
                !$systemOnly,
                in_array($id, self::HIGH_IMPACT_CAPABILITIES, true),
            );
        }

        return $definitions;
    }

    /**
     * Construct one typed core resource policy with the shared active/version-one defaults.
     *
     * @param   string                          $id                  Core policy identifier.
     * @param   string                          $capability          Core capability this policy binds.
     * @param   list<ResourcePolicyTarget>      $targets             Bounded resource selectors.
     * @param   bool                            $installationGlobal  Whether a global human grant is required.
     * @param   list<SystemIdentity>            $systems             Unattended identities permitted to use it.
     *
     * @return  ResourcePolicyDefinition  Typed contribution ready for the core registrar.
     *
     * @since   2.0.0
     */
    private static function policy(
        string $id,
        string $capability,
        array $targets,
        bool $installationGlobal = false,
        array $systems = [],
    ): ResourcePolicyDefinition {
        return new ResourcePolicyDefinition(
            $id,
            $capability,
            $targets,
            $installationGlobal,
            $systems,
        );
    }

    /**
     * Derive allowed grant scopes from a capability's resource policies.
     *
     * Global-only capabilities deliberately ignore their resource types for delegation. Every other
     * human capability accepts installation, site, and exact matching resource scopes, preserving the
     * existing reach while making it explicit in the registered capability metadata.
     *
     * @param   string                          $capability  Capability whose metadata is being built.
     * @param   list<ResourcePolicyDefinition>  $policies    Complete typed core resource policy set.
     *
     * @return  list<string>  Sorted allowed scope types.
     *
     * @since   2.0.0
     */
    private static function allowedScopes(string $capability, array $policies): array
    {
        if (in_array($capability, self::GLOBAL_ONLY_CAPABILITIES, true)) {
            return ['global'];
        }

        $scopes = ['global' => true, 'site' => true];
        foreach ($policies as $policy) {
            if ($policy->capability !== $capability) {
                continue;
            }
            foreach ($policy->resources as $resource) {
                $scopes[$resource->type] = true;
            }
        }
        ksort($scopes, SORT_STRING);

        return array_keys($scopes);
    }

    /**
     * Build the core administrator menu, each item bound to a core workspace and core capability.
     *
     * @return  list<AdministratorNavigationDefinition>  Declaration order; display order comes from priority.
     *
     * @since   2.0.0
     */
    private static function navigation(): array
    {
        return [
            new AdministratorNavigationDefinition(
                'core.dashboard',
                'core.workspace',
                'Dashboard',
                'Overview and publishing activity',
                '/administrator',
                'dashboard',
                'content.read',
                10,
                'home overview activity',
            ),
            new AdministratorNavigationDefinition(
                'core.content',
                'core.workspace',
                'Content',
                'Find, edit and publish content',
                '/administrator/content',
                'content',
                'content.read',
                20,
                'pages articles entries search',
            ),
            new AdministratorNavigationDefinition(
                'core.create-content',
                'core.workspace',
                'Create content',
                'Start a new content item',
                '/administrator/content/new',
                'plus',
                'content.create',
                30,
                'new page article entry',
            ),
            new AdministratorNavigationDefinition(
                'core.media',
                'core.workspace',
                'Media',
                'Browse and upload files',
                '/administrator/media',
                'media',
                'content.read',
                40,
                'images files uploads library',
            ),
            new AdministratorNavigationDefinition(
                'core.models',
                'core.structure',
                'Content models',
                'Fields and publishing workflows',
                '/administrator/content-models',
                'models',
                'content.read',
                100,
                'schemas fields types workflows states',
            ),
            new AdministratorNavigationDefinition(
                'core.navigation',
                'core.structure',
                'Menus',
                'Public navigation structure',
                '/administrator/navigation',
                'navigation',
                'navigation.manage',
                110,
                'menus links tree site navigation',
            ),
            new AdministratorNavigationDefinition(
                'core.business-definitions',
                'core.structure',
                'Business definitions',
                'Operational entities, fields and relationships',
                '/administrator/business-definitions',
                'models',
                'content.read',
                105,
                'entities fields relationships views actions workflows schema',
            ),
            new AdministratorNavigationDefinition(
                'core.business-schema-plans',
                'core.structure',
                'Schema plans',
                'Inspect and control generated relational storage',
                '/administrator/business-schema-plans',
                'models',
                'business.schema.read',
                108,
                'database schema plans execution recovery checksums',
            ),
            new AdministratorNavigationDefinition(
                'core.access',
                'core.system',
                'Users & access',
                'People, groups and permissions',
                '/administrator/access',
                'users',
                'users.manage',
                200,
                'users groups roles permissions tokens',
            ),
            new AdministratorNavigationDefinition(
                'core.business-security',
                'core.system',
                'Business Security',
                'Organizations, policy, approvals and step-up assurance',
                '/administrator/business-security',
                'security',
                'business.security.manage',
                205,
                'organizations workspaces memberships policy approvals security access',
            ),
            new AdministratorNavigationDefinition(
                'core.extensions',
                'core.system',
                'Extensions',
                'Packages, trust and themes',
                '/administrator/extensions',
                'extensions',
                'extensions.manage',
                210,
                'plugins modules packages themes templates',
            ),
            new AdministratorNavigationDefinition(
                'core.automation',
                'core.system',
                'Automation',
                'Schedules and background work',
                '/administrator/automation',
                'automation',
                'automation.manage',
                220,
                'jobs schedules cron workers',
            ),
            new AdministratorNavigationDefinition(
                'core.settings',
                'core.system',
                'Settings',
                'Site identity and defaults',
                '/administrator/settings',
                'settings',
                'settings.manage',
                230,
                'configuration site homepage seo',
            ),
        ];
    }

    /**
     * Prevent instantiation; the core contribution set is static declaration only.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
