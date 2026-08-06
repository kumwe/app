<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Navigation;

use InvalidArgumentException;

final class AdministratorNavigationRegistry
{
    /** @var array<string, AdministratorNavigationItem> */
    private array $items = [];

    /** @param iterable<AdministratorNavigationItem> $items */
    public function __construct(iterable $items = [])
    {
        foreach ($items as $item) {
            $this->register($item);
        }
    }

    public function register(AdministratorNavigationItem $item): void
    {
        if (isset($this->items[$item->id])) {
            throw new InvalidArgumentException(sprintf(
                'Administrator navigation item %s is already registered.',
                $item->id,
            ));
        }
        $this->items[$item->id] = $item;
    }

    /**
     * @param array<string, true> $capabilities
     * @return list<array<string, int|string>>
     */
    public function visible(array $capabilities): array
    {
        $items = array_values(array_filter(
            $this->items,
            static fn (AdministratorNavigationItem $item): bool => isset($capabilities[$item->capability]),
        ));
        usort($items, static fn (AdministratorNavigationItem $left, AdministratorNavigationItem $right): int =>
            [$left->priority, $left->label] <=> [$right->priority, $right->label]);

        return array_map(static fn (AdministratorNavigationItem $item): array => $item->toArray(), $items);
    }

    public static function core(): self
    {
        return new self([
            new AdministratorNavigationItem(
                'dashboard',
                'Dashboard',
                'Overview and publishing activity',
                '/administrator',
                'dashboard',
                'Workspace',
                'content.read',
                10,
                'home overview activity',
            ),
            new AdministratorNavigationItem(
                'content',
                'Content',
                'Find, edit and publish content',
                '/administrator/content',
                'content',
                'Workspace',
                'content.read',
                20,
                'pages articles entries search',
            ),
            new AdministratorNavigationItem(
                'create-content',
                'Create content',
                'Start a new content item',
                '/administrator/content/new',
                'plus',
                'Workspace',
                'content.create',
                30,
                'new page article entry',
            ),
            new AdministratorNavigationItem(
                'media',
                'Media',
                'Browse and upload files',
                '/administrator/media',
                'media',
                'Workspace',
                'content.read',
                40,
                'images files uploads library',
            ),
            new AdministratorNavigationItem(
                'models',
                'Content models',
                'Fields and publishing workflows',
                '/administrator/content-models',
                'models',
                'Structure',
                'content.read',
                100,
                'schemas fields types workflows states',
            ),
            new AdministratorNavigationItem(
                'navigation',
                'Menus',
                'Public navigation structure',
                '/administrator/navigation',
                'navigation',
                'Structure',
                'navigation.manage',
                110,
                'menus links tree site navigation',
            ),
            new AdministratorNavigationItem(
                'access',
                'Users & access',
                'People, groups and permissions',
                '/administrator/access',
                'users',
                'System',
                'users.manage',
                200,
                'users groups roles permissions tokens',
            ),
            new AdministratorNavigationItem(
                'extensions',
                'Extensions',
                'Packages, trust and themes',
                '/administrator/extensions',
                'extensions',
                'System',
                'extensions.manage',
                210,
                'plugins modules packages themes templates',
            ),
            new AdministratorNavigationItem(
                'automation',
                'Automation',
                'Schedules and background work',
                '/administrator/automation',
                'automation',
                'System',
                'automation.manage',
                220,
                'jobs schedules cron workers',
            ),
            new AdministratorNavigationItem(
                'settings',
                'Settings',
                'Site identity and defaults',
                '/administrator/settings',
                'settings',
                'System',
                'settings.manage',
                230,
                'configuration site homepage seo',
            ),
        ]);
    }
}
