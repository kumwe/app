<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Navigation;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Contribution\AdministratorNavigationDefinition;
use Kumwe\CMS\Extension\Contribution\AdministratorWorkspaceRegistry;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinitionRegistry;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;

final class AdministratorNavigationRegistry
{
    /** @var array<string, array{owner: ContributionOwner, definition: AdministratorNavigationDefinition}> */
    private array $items = [];

    public function __construct(
        private readonly AdministratorWorkspaceRegistry $workspaces,
        private readonly CapabilityDefinitionRegistry $capabilities,
        private readonly ?TrustStore $trust = null,
    ) {
    }

    public function registerOwned(
        ContributionOwner $owner,
        AdministratorNavigationDefinition $definition,
    ): void {
        $owner->assertOwns($definition->id, 'navigation');
        if (!$this->workspaces->isOwnedBy($definition->workspace, $owner)) {
            throw new InvalidArgumentException('Administrator navigation must reference an owned workspace.');
        }
        if (!$this->capabilities->isOwnedBy($definition->capability, $owner)) {
            throw new InvalidArgumentException('Administrator navigation must reference an owned capability.');
        }
        if (isset($this->items[$definition->id])) {
            throw new InvalidArgumentException(sprintf(
                'Administrator navigation item %s is already registered.',
                $definition->id,
            ));
        }
        $this->items[$definition->id] = ['owner' => $owner, 'definition' => $definition];
    }

    /**
     * @param array<string, true> $capabilities
     * @return list<array<string, int|string>>
     */
    public function visible(array $capabilities): array
    {
        $active = $this->activeExtensions();
        $items = array_values(array_filter(
            $this->items,
            static fn (array $entry): bool => isset($capabilities[$entry['definition']->capability])
                && self::ownerIsActive($entry['owner'], $active),
        ));
        usort($items, function (array $left, array $right): int {
            $leftWorkspace = $this->workspaces->definition($left['definition']->workspace);
            $rightWorkspace = $this->workspaces->definition($right['definition']->workspace);
            return [
                $leftWorkspace->priority,
                $left['definition']->priority,
                $left['definition']->label,
                $left['definition']->id,
            ] <=> [
                $rightWorkspace->priority,
                $right['definition']->priority,
                $right['definition']->label,
                $right['definition']->id,
            ];
        });

        return array_map(fn (array $entry): array => $this->present($entry), $items);
    }

    /**
     * @param array<string, true> $capabilities
     * @param list<array<string, int|string>>|null $visible
     * @return list<array{id: string, label: string, description: string, priority: int, dom_id: string}>
     */
    public function visibleWorkspaces(array $capabilities, ?array $visible = null): array
    {
        $visible ??= $this->visible($capabilities);
        $result = [];
        foreach ($visible as $item) {
            $workspaceId = (string) $item['workspace'];
            if (isset($result[$workspaceId])) {
                continue;
            }
            $workspace = $this->workspaces->definition($workspaceId);
            $result[$workspaceId] = $workspace->toArray() + [
                'dom_id' => preg_replace('/[^a-z0-9-]+/', '-', $workspaceId) ?? $workspaceId,
            ];
        }
        return array_values($result);
    }

    /** @return list<array<string, mixed>> */
    public function ownedBy(ContributionOwner $owner): array
    {
        $result = [];
        foreach ($this->items as $entry) {
            if ($entry['owner']->identifier() === $owner->identifier()) {
                $result[] = $this->present($entry);
            }
        }
        return $result;
    }

    public function remove(ContributionOwner $owner): void
    {
        foreach ($this->items as $identifier => $entry) {
            if ($entry['owner']->identifier() === $owner->identifier()) {
                unset($this->items[$identifier]);
            }
        }
    }

    public static function core(): self
    {
        return (new ExtensionContributionRegistrySet())->navigation();
    }

    /**
     * @param array{owner: ContributionOwner, definition: AdministratorNavigationDefinition} $entry
     * @return array<string, int|string>
     */
    private function present(array $entry): array
    {
        $owner = $entry['owner'];
        $definition = $entry['definition'];
        $workspace = $this->workspaces->definition($definition->workspace);
        $path = $owner->identifier() === ContributionOwner::CORE
            ? $definition->path
            : '/administrator/extensions/' . $owner->identifier()
                . ($definition->path === '/' ? '' : $definition->path);
        return [
            'id' => $definition->id,
            'owner' => $owner->identifier(),
            'workspace' => $definition->workspace,
            'label' => $definition->label,
            'description' => $definition->description,
            'href' => $path,
            'icon' => $definition->icon,
            'group' => $workspace->label,
            'capability' => $definition->capability,
            'priority' => $definition->priority,
            'keywords' => $definition->keywords,
        ];
    }

    /** @return array<string, true>|null */
    private function activeExtensions(): ?array
    {
        if ($this->trust === null) {
            return null;
        }
        return array_fill_keys($this->trust->trustedActiveRuntimeIdentifiers(), true);
    }

    /** @param array<string, true>|null $active */
    private static function ownerIsActive(ContributionOwner $owner, ?array $active): bool
    {
        return $owner->identifier() === ContributionOwner::CORE
            || $active === null
            || isset($active[$owner->identifier()]);
    }
}
