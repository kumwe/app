<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ContributionSurface;

/**
 * Owner-aware collision-safe registry of portal navigation workspaces.
 *
 * @since  2.0.0
 */
final class PortalWorkspaceRegistry implements ContributionSurface
{
    /**
     * Definitions keyed by id with their canonical owner string.
     *
     * @var    array<string, array{owner: string, definition: PortalWorkspaceDefinition}>
     * @since  2.0.0
     */
    private array $definitions = [];

    /**
     * Register one uniquely owned workspace.
     *
     * @param   ContributionOwner         $owner       Claiming contributor.
     * @param   PortalWorkspaceDefinition $definition Validated declaration.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When namespace ownership or uniqueness fails.
     *
     * @since   2.0.0
     */
    public function register(ContributionOwner $owner, PortalWorkspaceDefinition $definition): void
    {
        $owner->assertOwns($definition->id, 'workspace');
        if (isset($this->definitions[$definition->id])) {
            throw new InvalidArgumentException('A portal workspace identifier is already owned.');
        }
        $this->definitions[$definition->id] = ['owner' => $owner->identifier(), 'definition' => $definition];
    }

    /**
     * Determine exact ownership of a workspace.
     *
     * @param   string             $identifier  Workspace id.
     * @param   ContributionOwner  $owner       Expected owner.
     *
     * @return  bool  True only for exact ownership.
     *
     * @since   2.0.0
     */
    public function isOwnedBy(string $identifier, ContributionOwner $owner): bool
    {
        return ($this->definitions[$identifier]['owner'] ?? null) === $owner->identifier();
    }

    /**
     * Resolve a registered workspace.
     *
     * @param   string  $identifier  Workspace id.
     *
     * @return  PortalWorkspaceDefinition  Registered declaration.
     *
     * @throws  InvalidArgumentException  When unknown.
     *
     * @since   2.0.0
     */
    public function definition(string $identifier): PortalWorkspaceDefinition
    {
        return $this->definitions[$identifier]['definition']
            ?? throw new InvalidArgumentException('The contributed portal workspace is not registered.');
    }

    /**
     * List one owner's workspaces.
     *
     * @param   ContributionOwner  $owner  Contributor to inspect.
     *
     * @return  list<array<string, mixed>>  Declaration exports.
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
     * Remove all workspaces owned by one contributor.
     *
     * @param   ContributionOwner  $owner  Contributor being withdrawn.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(ContributionOwner $owner): void
    {
        foreach ($this->definitions as $id => $entry) {
            if ($entry['owner'] === $owner->identifier()) {
                unset($this->definitions[$id]);
            }
        }
    }
}
