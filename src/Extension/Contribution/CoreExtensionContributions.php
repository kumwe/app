<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use Kumwe\CMS\BusinessDefinition\Domain\BuiltInFieldTypes;

final class CoreExtensionContributions
{
    /** @var array<string, string> */
    private const CAPABILITIES = [
        'automation.manage' => 'Manage schedules and background work.',
        'business.record.action' => 'Execute declared business-record actions and workflow transitions.',
        'business.record.archive' => 'Archive business records.',
        'business.record.browse' => 'Browse business records through bounded typed queries.',
        'business.record.create' => 'Create business records.',
        'business.record.delete' => 'Delete business records under their declared lifecycle policy.',
        'business.record.history' => 'Read business-record revision history.',
        'business.record.read' => 'Read individual business records.',
        'business.record.relate' => 'Manage business-record relationships and ordered lines.',
        'business.record.restore' => 'Restore soft-deleted business records.',
        'business.record.update' => 'Update business records.',
        'business.schema.approve' => 'Approve an exact persisted business schema plan.',
        'business.schema.destructive' => 'Authorize destructive business schema changes.',
        'business.schema.execute' => 'Execute an approved business schema plan.',
        'business.schema.plan' => 'Create deterministic business schema plans.',
        'business.schema.read' => 'Inspect business schema plans, installations, and execution journals.',
        'business.schema.recover' => 'Resume or reconcile interrupted business schema execution.',
        'content.create' => 'Create content.',
        'content.read' => 'Read content, media, models, and workflows.',
        'extensions.manage' => 'Install and manage extensions and trust.',
        'navigation.manage' => 'Manage public navigation.',
        'settings.manage' => 'Manage site settings.',
        'users.manage' => 'Manage users, roles, permissions, and tokens.',
    ];

    public static function register(ExtensionContributionRegistrar $registrar): void
    {
        foreach (BuiltInFieldTypes::all() as $fieldType) {
            $registrar->fieldType($fieldType);
        }
        foreach (self::CAPABILITIES as $id => $description) {
            $registrar->capability(new CapabilityDefinition(
                $id,
                ucwords(str_replace('.', ' ', $id)),
                $description,
            ));
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
    }

    /** @return list<AdministratorNavigationDefinition> */
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

    private function __construct()
    {
    }
}
