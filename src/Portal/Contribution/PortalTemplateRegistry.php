<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Contribution;

use Kumwe\Extension\Spi\Portal\Contribution\PortalTemplateDefinition;

use InvalidArgumentException;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ContributionSurface;

/**
 * Owner-aware registry that is the only route from a portal template id to an isolated Twig path.
 *
 * @since  2.0.0
 */
final class PortalTemplateRegistry implements ContributionSurface
{
    /**
     * Templates keyed by name.
     *
     * @var    array<string, array{owner: ContributionOwner, definition: PortalTemplateDefinition}>
     * @since  2.0.0
     */
    private array $definitions = [];

    /**
     * Register a uniquely owned template declaration.
     *
     * @param   ContributionOwner         $owner       Claiming contributor.
     * @param   PortalTemplateDefinition  $definition  Validated declaration.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When ownership or uniqueness fails.
     *
     * @since   2.0.0
     */
    public function register(ContributionOwner $owner, PortalTemplateDefinition $definition): void
    {
        $owner->assertOwns($definition->name, 'template');
        if (isset($this->definitions[$definition->name])) {
            throw new InvalidArgumentException('A portal template identifier is already owned.');
        }
        $this->definitions[$definition->name] = ['owner' => $owner, 'definition' => $definition];
    }

    /**
     * Determine exact ownership of a template identifier.
     *
     * @param   string             $identifier  Template name.
     * @param   ContributionOwner  $owner       Expected owner.
     *
     * @return  bool  True only for exact ownership.
     *
     * @since   2.0.0
     */
    public function isOwnedBy(string $identifier, ContributionOwner $owner): bool
    {
        $entry = $this->definitions[$identifier] ?? null;

        return is_array($entry) && $entry['owner']->identifier() === $owner->identifier();
    }

    /**
     * Resolve a template only for its owner.
     *
     * @param   ContributionOwner  $owner       Expected owner.
     * @param   string             $identifier  Template name.
     *
     * @return  string  Safe relative Twig path.
     *
     * @throws  InvalidArgumentException  When unknown or foreign-owned.
     *
     * @since   2.0.0
     */
    public function template(ContributionOwner $owner, string $identifier): string
    {
        $entry = $this->definitions[$identifier] ?? null;
        if (!is_array($entry) || $entry['owner']->identifier() !== $owner->identifier()) {
            throw new InvalidArgumentException('The contributed portal template is not owned by this contributor.');
        }
        return $entry['definition']->template;
    }

    /**
     * List one owner's template declarations.
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
            if ($entry['owner']->identifier() === $owner->identifier()) {
                $result[] = $entry['definition']->toArray();
            }
        }
        return $result;
    }

    /**
     * Remove every template owned by one contributor.
     *
     * @param   ContributionOwner  $owner  Contributor being withdrawn.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(ContributionOwner $owner): void
    {
        foreach ($this->definitions as $name => $entry) {
            if ($entry['owner']->identifier() === $owner->identifier()) {
                unset($this->definitions[$name]);
            }
        }
    }
}
