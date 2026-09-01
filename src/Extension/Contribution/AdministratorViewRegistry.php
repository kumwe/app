<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Contribution\AdministratorViewDefinition;
use InvalidArgumentException;

/**
 * Owns the administrator view names contributions may render, one contributor per name.
 *
 * `AdministratorRenderer` will not render an extension template it cannot resolve here, and
 * `AdministratorRouteRegistry` will not accept a route whose view this registry does not attribute
 * to the same contributor. That makes this the single authority on which view names exist and who
 * may render them, so one extension cannot render another's template by naming it.
 *
 * @since  2.0.0
 */
final class AdministratorViewRegistry implements ContributionSurface
{
    /**
     * Registered views keyed by view name, each carrying the owner identifier that claimed it.
     *
     * @var    array<string, array{owner: string, definition: AdministratorViewDefinition}>
     * @since  2.0.0
     */
    private array $definitions = [];

    /**
     * Claim a view name for a contributor.
     *
     * @param   ContributionOwner            $owner       Contributor claiming the view.
     * @param   AdministratorViewDefinition  $definition  Validated declaration to record.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the name sits outside the owner's namespace, or the name
     *          is already registered to anyone, this contributor included.
     *
     * @since   2.0.0
     */
    public function register(ContributionOwner $owner, AdministratorViewDefinition $definition): void
    {
        $owner->assertOwns($definition->name, 'view');
        if (isset($this->definitions[$definition->name])) {
            throw new InvalidArgumentException(sprintf(
                'Administrator view %s is already owned by %s.',
                $definition->name,
                $this->definitions[$definition->name]['owner'],
            ));
        }
        $this->definitions[$definition->name] = [
            'owner' => $owner->identifier(),
            'definition' => $definition,
        ];
    }

    /**
     * Report whether a view name is registered to this contributor.
     *
     * @param   string             $identifier  View name to look up.
     * @param   ContributionOwner  $owner       Contributor the name must belong to.
     *
     * @return  bool  False both when the name is unknown and when someone else holds it.
     *
     * @since   2.0.0
     */
    public function isOwnedBy(string $identifier, ContributionOwner $owner): bool
    {
        return ($this->definitions[$identifier]['owner'] ?? null) === $owner->identifier();
    }

    /**
     * Resolve an owned view name to the template it renders.
     *
     * This is the lookup `AdministratorRenderer` performs before prefixing the result with the
     * extension's Twig namespace, so refusing an unowned view here is what confines a contribution
     * to its own templates.
     *
     * @param   ContributionOwner  $owner  Contributor asking to render the view.
     * @param   string             $view   View name being rendered.
     *
     * @return  string  Template path relative to the owner's Twig namespace.
     *
     * @throws  InvalidArgumentException  When the view is unknown or belongs to another contributor.
     *
     * @since   2.0.0
     */
    public function template(ContributionOwner $owner, string $view): string
    {
        if (!$this->isOwnedBy($view, $owner)) {
            throw new InvalidArgumentException('An extension cannot render an unowned administrator view.');
        }
        return $this->definitions[$view]['definition']->template;
    }

    /**
     * List this owner's views for the contribution inventory.
     *
     * @param   ContributionOwner  $owner  Contributor whose views are wanted.
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
     * Withdraw every view this owner contributed, freeing their names for a later contributor.
     *
     * Once a view is withdrawn `template()` no longer resolves it, so any handler still holding the
     * name fails instead of rendering a template the contributor no longer owns.
     *
     * @param   ContributionOwner  $owner  Contributor whose views are withdrawn.
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
