<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Navigation;

use InvalidArgumentException;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\AdministratorWorkspaceRegistry;
use Kumwe\App\Extension\Contribution\CapabilityDefinitionRegistry;
use Kumwe\Extension\Spi\Contribution\AdministratorNavigationDefinition;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ContributionSurface;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;

/**
 * Holds every administrator menu entry, core and contributed, and presents the subset an actor may see.
 *
 * Registration is the first gate: an entry is accepted only when the contributor owns its identifier,
 * the workspace it is filed under, and the capability that guards it, so an extension can extend its own
 * corner of the shell and nowhere else. Rendering is the second: `visible()` drops entries whose
 * capability the actor does not hold and entries whose extension no longer passes live trust
 * enforcement, so a revoked signing key empties a menu at the next render rather than the next
 * deployment. What survives is sorted by workspace, priority, label and identifier, which is what makes
 * the menu identical between two requests that registered contributions in a different order.
 *
 * @since  2.0.0
 */
final class AdministratorNavigationRegistry implements ContributionSurface
{
    /**
     * Registered entries keyed by identifier, each paired with the contributor that claimed it.
     *
     * @var    array<string, array{owner: ContributionOwner, definition: AdministratorNavigationDefinition}>
     * @since  2.0.0
     */
    private array $items = [];

    /**
     * Wire the registry to the surfaces every entry is validated and rendered against.
     *
     * @param  AdministratorWorkspaceRegistry  $workspaces    Authority on which workspaces exist, who owns
     *         them, and how each menu group is titled and ordered.
     * @param  CapabilityDefinitionRegistry    $capabilities  Authority on which capabilities exist and who
     *         registered them, so an entry cannot hide behind someone else's permission.
     * @param  ?TrustStore                     $trust         Live trust enforcement consulted before an
     *         extension's entries are shown; null disables trust filtering entirely.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly AdministratorWorkspaceRegistry $workspaces,
        private readonly CapabilityDefinitionRegistry $capabilities,
        private readonly ?TrustStore $trust = null,
    ) {
    }

    /**
     * Record one contributed menu entry, refusing anything its contributor does not own.
     *
     * Three ownership checks run before the entry is stored — its own identifier, the workspace it names
     * and the capability that guards it — so a contribution can neither file itself under another
     * extension's section of the shell nor take cover behind a permission it did not define. An
     * identifier is claimed once and never redefined in place, which is what lets `remove()` withdraw a
     * contributor's entries wholesale without disturbing anyone else's.
     *
     * @param   ContributionOwner                  $owner       Contributor registering the entry.
     * @param   AdministratorNavigationDefinition  $definition  Validated declaration to record.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier sits outside the owner's namespace, the
     *          workspace or capability it names belongs to someone else, or the identifier is already
     *          registered.
     *
     * @since   2.0.0
     */
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
     * Present the menu entries this actor may see, ordered the way the shell renders them.
     *
     * Two filters apply. The actor must hold the entry's capability, and the contributing extension must
     * still pass live trust enforcement at this instant — core entries are exempt from the second, and
     * so is every entry when no trust store is wired. The surviving entries are ordered by workspace
     * priority, then entry priority, then label, then identifier, so the menu does not shuffle between
     * requests.
     *
     * @param   array<string, true>  $capabilities  Capability codes the actor holds, in the lookup shape
     *          `AdministratorRequest::capabilityMap()` builds.
     *
     * @return  list<array<string, int|string>>  Presented entries in menu order; empty when the actor may
     *          reach none of them.
     *
     * @throws  InvalidArgumentException  When a surviving entry names a workspace that has since been
     *          withdrawn from the workspace registry.
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
     * List the workspace headings the visible entries fall under, in first-appearance order.
     *
     * The layout renders one section per workspace and then filters the entry list into it, so a
     * workspace that no visible entry names is never offered and no empty heading can appear. Pass the
     * list `visible()` already produced for this actor; omitting it recomputes the very same menu.
     *
     * @param   array<string, true>                   $capabilities  Capability codes the actor holds.
     * @param   list<array<string, int|string>>|null  $visible       Entries `visible()` returned for this
     *          actor, or null to resolve them here.
     *
     * @return  list<array{id: string, label: string, description: string, priority: int, dom_id: string}>
     *          Each workspace's declaration plus `dom_id`, its identifier reduced to characters an HTML
     *          `id` attribute can carry.
     *
     * @throws  InvalidArgumentException  When a visible entry names a workspace that has since been
     *          withdrawn from the workspace registry.
     *
     * @since   2.0.0
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

    /**
     * List every menu entry one contributor registered, whether or not anyone can currently see it.
     *
     * Neither the capability filter nor the trust filter applies here, because this feeds the
     * contribution inventory an operator inspects on the extensions screen: it has to show what an
     * extension declared even when the extension is disabled or the operator holds none of its
     * capabilities.
     *
     * @param   ContributionOwner  $owner  Contributor whose entries are being inspected.
     *
     * @return  list<array<string, mixed>>  Presented entries in registration order; empty when the owner
     *          contributed none.
     *
     * @throws  InvalidArgumentException  When one of the owner's entries names a workspace that has since
     *          been withdrawn from the workspace registry.
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
     * Withdraw every menu entry this contributor registered, freeing their identifiers for a later one.
     *
     * Sweep this surface before the workspace registry, which is the order
     * `ExtensionContributionRegistrySet` removes in; the reverse would leave an entry naming a workspace
     * that can no longer be resolved, and the next render of the menu would raise.
     *
     * @param   ContributionOwner  $owner  Contributor whose entries are withdrawn.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(ContributionOwner $owner): void
    {
        foreach ($this->items as $identifier => $entry) {
            if ($entry['owner']->identifier() === $owner->identifier()) {
                unset($this->items[$identifier]);
            }
        }
    }

    /**
     * Build a registry holding the entries the CMS ships and nothing an operator installed.
     *
     * `AdministratorRenderer` reaches for this on its recovery path: when a themed or extension render
     * raises, the page is drawn again against a core-only menu so a broken contribution cannot take the
     * fallback down with it. No trust store is wired, which costs nothing for a menu that has no
     * extension entries to filter.
     *
     * @return  self  A fresh registry populated from the core contribution set alone.
     *
     * @since   2.0.0
     */
    public static function core(): self
    {
        return (new ExtensionContributionRegistrySet())->navigation();
    }

    /**
     * Flatten one stored entry into the row the shell layout and the command palette read.
     *
     * A contributed path is re-rooted under `/administrator/extensions/<vendor>/<name>` here rather than
     * at registration, so the stored declaration keeps the path its manifest wrote while the rendered
     * `href` can never address a core screen; an extension entry whose path is `/` lands on that prefix
     * itself. The workspace label is copied in as `group` so a template can title a section without a
     * second lookup.
     *
     * @param   array{owner: ContributionOwner, definition: AdministratorNavigationDefinition}  $entry
     *          Stored pairing of contributor and declaration.
     *
     * @return  array<string, int|string>  The declared fields, including an optional KIS surface, plus
     *          `owner`, the resolved `href` and the workspace label as `group`.
     *
     * @throws  InvalidArgumentException  When the entry names a workspace that has since been withdrawn
     *          from the workspace registry.
     *
     * @since   2.0.0
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
        $item = [
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
        if ($definition->surface !== null) {
            $item['surface'] = $definition->surface;
        }

        return $item;
    }

    /**
     * Resolve which extensions currently pass live trust enforcement, as a lookup for the menu filter.
     *
     * Null and an empty map mean opposite things: null says no trust store is wired and trust filtering
     * is skipped altogether, which is how a core-only or test registry behaves, while an empty map says
     * trust was enforced and no extension survived it.
     *
     * @return  array<string, true>|null  Trusted active extension identifiers keyed to `true`, or null
     *          when the registry enforces no trust.
     *
     * @since   2.0.0
     */
    private function activeExtensions(): ?array
    {
        if ($this->trust === null) {
            return null;
        }
        return array_fill_keys($this->trust->trustedActiveRuntimeIdentifiers(), true);
    }

    /**
     * Decide whether one contributor's entries survive the trust filter.
     *
     * Core always survives, since the CMS's own entries are not signed releases, and a null map means
     * trust is not being enforced at all; every other owner has to appear in the map of extensions that
     * currently verify.
     *
     * @param   ContributionOwner         $owner   Contributor the entry being filtered belongs to.
     * @param   array<string, true>|null  $active  Trusted extension identifiers, or null when the registry
     *          enforces no trust.
     *
     * @return  bool  True when this owner's entries may be shown.
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
