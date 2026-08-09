<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinitionRegistry;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ContributionSurface;
use Kumwe\CMS\Identity\Domain\Capability;

/**
 * Capability-, owner-, and live-trust-filtered portal navigation registry.
 *
 * @since  2.0.0
 */
final class PortalNavigationRegistry implements ContributionSurface
{
    /**
     * Registered items keyed by their identifier.
     *
     * @var    array<string, array{owner: ContributionOwner, definition: PortalNavigationDefinition}>
     * @since  2.0.0
     */
    private array $items = [];

    /**
     * Bind navigation to sibling ownership registries and optional live trust enforcement.
     *
     * @param  PortalWorkspaceRegistry       $workspaces    Owned workspace authority.
     * @param  CapabilityDefinitionRegistry  $capabilities  Owned capability authority.
     * @param  AuthorizationPolicyRegistry   $authorization Canonical action-to-resource policy authority.
     * @param  ?TrustStore                   $trust         Live extension trust store; null for core-only tests.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly PortalWorkspaceRegistry $workspaces,
        private readonly CapabilityDefinitionRegistry $capabilities,
        private readonly AuthorizationPolicyRegistry $authorization,
        private readonly ?TrustStore $trust = null,
    ) {
    }

    /**
     * Register an item only when its id, workspace, and capability share one owner.
     *
     * @param   ContributionOwner          $owner       Claiming contributor.
     * @param   PortalNavigationDefinition $definition Validated item.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When ownership or collision checks fail.
     *
     * @since   2.0.0
     */
    public function register(ContributionOwner $owner, PortalNavigationDefinition $definition): void
    {
        $owner->assertOwns($definition->id, 'navigation');
        if (!$this->workspaces->isOwnedBy($definition->workspace, $owner)) {
            throw new InvalidArgumentException('Portal navigation must reference an owned workspace.');
        }
        if (!$this->capabilities->isOwnedBy($definition->capability, $owner)) {
            throw new InvalidArgumentException('Portal navigation must reference an owned capability.');
        }
        if (!$this->authorization->supports(
            Capability::fromString($definition->capability),
            AuthorizationResource::collection('portal_session'),
        )) {
            throw new InvalidArgumentException(
                'Portal navigation must reference an enforceable whole-family portal-session policy.',
            );
        }
        if (isset($this->items[$definition->id])) {
            throw new InvalidArgumentException('A portal navigation identifier is already owned.');
        }
        $this->items[$definition->id] = ['owner' => $owner, 'definition' => $definition];
    }

    /**
     * Present only entries whose capability is held and whose extension remains trusted and active.
     *
     * @param   array<string, true>  $capabilities  Live principal capability lookup.
     *
     * @return  list<array<string, int|string>>  Sorted shell rows.
     *
     * @since   2.0.0
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
     * Return workspace headings that have at least one visible entry.
     *
     * @param   array<string, true>                   $capabilities  Live principal capability lookup.
     * @param   list<array<string, int|string>>|null  $visible       Precomputed visible rows.
     *
     * @return  list<array{id: string, label: string, description: string, priority: int, dom_id: string}>
     *          Ordered non-empty groups.
     *
     * @since   2.0.0
     */
    public function visibleWorkspaces(array $capabilities, ?array $visible = null): array
    {
        $visible ??= $this->visible($capabilities);
        $result = [];
        foreach ($visible as $item) {
            $id = (string) $item['workspace'];
            if (!isset($result[$id])) {
                $result[$id] = $this->workspaces->definition($id)->toArray() + [
                    'dom_id' => preg_replace('/[^a-z0-9-]+/', '-', $id) ?? $id,
                ];
            }
        }

        return array_values($result);
    }

    /**
     * List all declarations owned by one contributor regardless of visibility.
     *
     * @param   ContributionOwner  $owner  Contributor to inspect.
     *
     * @return  list<array<string, mixed>>  Presented contribution rows.
     *
     * @since   2.0.0
     */
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

    /**
     * Remove every navigation item owned by one contributor.
     *
     * @param   ContributionOwner  $owner  Contributor being withdrawn.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(ContributionOwner $owner): void
    {
        foreach ($this->items as $id => $entry) {
            if ($entry['owner']->identifier() === $owner->identifier()) {
                unset($this->items[$id]);
            }
        }
    }

    /**
     * Build the shell row and confine extension hrefs to their own portal mount.
     *
     * @param   array{owner: ContributionOwner, definition: PortalNavigationDefinition}  $entry  Stored item.
     *
     * @return  array<string, int|string>  Renderable row.
     *
     * @since   2.0.0
     */
    private function present(array $entry): array
    {
        $owner = $entry['owner'];
        $definition = $entry['definition'];
        $workspace = $this->workspaces->definition($definition->workspace);
        $href = $owner->identifier() === ContributionOwner::CORE
            ? $definition->path
            : '/portal/extensions/' . $owner->identifier()
                . ($definition->path === '/' ? '' : $definition->path);

        return $definition->toArray() + [
            'owner' => $owner->identifier(),
            'href' => $href,
            'group' => $workspace->label,
        ];
    }

    /**
     * Resolve the current trusted and active extension set when trust enforcement is wired.
     *
     * @return  array<string, true>|null  Active owner lookup, or null when filtering is disabled.
     *
     * @since   2.0.0
     */
    private function activeExtensions(): ?array
    {
        return $this->trust === null
            ? null
            : array_fill_keys($this->trust->trustedActiveRuntimeIdentifiers(), true);
    }

    /**
     * Decide whether an owner survives live trust enforcement.
     *
     * @param   ContributionOwner         $owner   Contributor to test.
     * @param   array<string, true>|null  $active  Active extension lookup or null.
     *
     * @return  bool  True for core, disabled filtering, or a trusted active extension.
     *
     * @since   2.0.0
     */
    private static function ownerIsActive(ContributionOwner $owner, ?array $active): bool
    {
        return $owner->identifier() === ContributionOwner::CORE
            || $active === null
            || isset($active[$owner->identifier()]);
    }
}
