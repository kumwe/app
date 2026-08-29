<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\Extension\Spi\Contribution\ContributionOwner;

use Kumwe\Extension\Spi\Contribution\AdministratorWorkspaceDefinition;

use InvalidArgumentException;

/**
 * Owns the administrator workspaces navigation may be filed under, one contributor per id.
 *
 * `AdministratorNavigationRegistry` accepts an item only when this registry attributes the
 * workspace it names to the same contributor, then reads the workspace back to order and title the
 * menu group. So this is the single authority on which workspace ids exist, and the reason an
 * extension can add to its own section of the shell but not to anyone else's.
 *
 * @since  2.0.0
 */
final class AdministratorWorkspaceRegistry implements ContributionSurface
{
    /**
     * Registered workspaces keyed by id, each carrying the owner identifier that claimed it.
     *
     * @var    array<string, array{owner: string, definition: AdministratorWorkspaceDefinition}>
     * @since  2.0.0
     */
    private array $definitions = [];

    /**
     * Claim a workspace id for a contributor.
     *
     * @param   ContributionOwner                 $owner       Contributor claiming the workspace.
     * @param   AdministratorWorkspaceDefinition  $definition  Validated declaration to record.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the id sits outside the owner's namespace, or the id is
     *          already registered to anyone, this contributor included.
     *
     * @since   2.0.0
     */
    public function register(ContributionOwner $owner, AdministratorWorkspaceDefinition $definition): void
    {
        $owner->assertOwns($definition->id, 'workspace');
        if (isset($this->definitions[$definition->id])) {
            throw new InvalidArgumentException(sprintf(
                'Administrator workspace %s is already owned by %s.',
                $definition->id,
                $this->definitions[$definition->id]['owner'],
            ));
        }
        $this->definitions[$definition->id] = [
            'owner' => $owner->identifier(),
            'definition' => $definition,
        ];
    }

    /**
     * Report whether a workspace id is registered to this contributor.
     *
     * @param   string             $identifier  Workspace id to look up.
     * @param   ContributionOwner  $owner       Contributor the id must belong to.
     *
     * @return  bool  False both when the id is unknown and when someone else holds it.
     *
     * @since   2.0.0
     */
    public function isOwnedBy(string $identifier, ContributionOwner $owner): bool
    {
        return ($this->definitions[$identifier]['owner'] ?? null) === $owner->identifier();
    }

    /**
     * Read a registered workspace back by id, whoever owns it.
     *
     * Ownership is deliberately not checked: rendering the menu has to title and order a group from
     * its workspace regardless of which contributor declared it.
     *
     * @param   string  $identifier  Workspace id to resolve.
     *
     * @return  AdministratorWorkspaceDefinition  The declaration registered under that id.
     *
     * @throws  InvalidArgumentException  When no contributor has registered that workspace.
     *
     * @since   2.0.0
     */
    public function definition(string $identifier): AdministratorWorkspaceDefinition
    {
        return $this->definitions[$identifier]['definition']
            ?? throw new InvalidArgumentException('The contributed administrator workspace is not registered.');
    }

    /**
     * List this owner's workspaces for the contribution inventory.
     *
     * @param   ContributionOwner  $owner  Contributor whose workspaces are wanted.
     *
     * @return  list<array<string, mixed>>  One array per declaration; empty when the owner contributed none.
     *
     * @since   2.0.0
     */
    public function ownedBy(ContributionOwner $owner): array
    {
        $result = [];
        foreach ($this->definitions as $entry) {
            if ($entry['owner'] === $owner->identifier()) {
                $result[] = $entry['definition']->toArray();
            }
        }
        return $result;
    }

    /**
     * Withdraw every workspace this owner contributed, freeing their ids for a later contributor.
     *
     * Withdraw workspaces only after the navigation items filed under them, which is the order
     * `ExtensionContributionRegistrySet` removes its surfaces in; otherwise a surviving item would
     * name a workspace `definition()` can no longer resolve.
     *
     * @param   ContributionOwner  $owner  Contributor whose workspaces are withdrawn.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(ContributionOwner $owner): void
    {
        foreach ($this->definitions as $identifier => $entry) {
            if ($entry['owner'] === $owner->identifier()) {
                unset($this->definitions[$identifier]);
            }
        }
    }
}
