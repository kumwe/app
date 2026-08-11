<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;

/**
 * Validated declaration of an administrator workspace: the group navigation items are filed under.
 *
 * A workspace carries no behaviour of its own. It names and orders one section of the
 * administrator shell so that core and extension navigation merge into a single menu, which is why
 * the label, description, and sort weight are bounded here — an unbounded contribution would
 * distort a shell it does not own.
 *
 * This class also holds `assertIdentifier()`, the identifier grammar every administrator
 * contribution shares, because workspaces, navigation items, views, and routes must all be
 * checkable for namespace ownership by prefix.
 *
 * @since  2.0.0
 */
final readonly class AdministratorWorkspaceDefinition implements ContributionDefinition
{
    /**
     * Validate one administrator workspace declaration.
     *
     * @param   string  $id           Dotted workspace identifier; ownership is checked when it is registered.
     * @param   string  $label        Heading shown for the menu group; 1 to 80 characters.
     * @param   string  $description  Sentence explaining the group to an operator; 1 to 255 characters.
     * @param   int     $priority     Sort weight among workspaces, 0 to 100000; lower sorts nearer the top.
     *
     * @throws  InvalidArgumentException  When the identifier is malformed, or the label, description, or
     *          priority falls outside its bounds.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public int $priority,
    ) {
        self::assertIdentifier($id, 'workspace');
        if (trim($label) === '' || mb_strlen($label) > 80) {
            throw new InvalidArgumentException('An administrator workspace label must contain 1 to 80 characters.');
        }
        if (trim($description) === '' || mb_strlen($description) > 255) {
            throw new InvalidArgumentException(
                'An administrator workspace description must contain 1 to 255 characters.',
            );
        }
        if ($priority < 0 || $priority > 100_000) {
            throw new InvalidArgumentException('An administrator workspace priority must be between 0 and 100000.');
        }
    }

    /**
     * Assert that a contributed administrator identifier has the shape every surface requires.
     *
     * A bounded lowercase identifier that starts and ends alphanumerically, includes at least one dot,
     * and otherwise uses letters, digits, dots, underscores, or hyphens. Internal repeated dots stay
     * representable for existing canonical package owners that contain them. This additive grammar
     * preserves the dotted namespace of extension identifiers across workspaces, navigation items,
     * views, routes, and KIS surfaces, while `ContributionOwner` still decides ownership with an exact
     * namespace prefix test.
     *
     * @param   string  $identifier  Candidate identifier as declared.
     * @param   string  $kind        Contribution kind named in the failure message, such as `route`.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier does not match the shared grammar.
     *
     * @since   2.0.0
     */
    public static function assertIdentifier(string $identifier, string $kind): void
    {
        if (
            strlen($identifier) > 191
            || preg_match('/^[a-z0-9][a-z0-9._-]*\.[a-z0-9._-]*[a-z0-9]$/D', $identifier) !== 1
        ) {
            throw new InvalidArgumentException(sprintf('A contributed administrator %s identifier is invalid.', $kind));
        }
    }

    /**
     * Report the identifier the contribution registries key this workspace by.
     *
     * @return  string  The dotted workspace identifier exactly as declared.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->id;
    }

    /**
     * Export the declaration in the shape the manifest declaration is compared against.
     *
     * The administrator navigation registry also builds its menu group from this array, adding a
     * DOM identifier of its own.
     *
     * @return  array{id: string, label: string, description: string, priority: int}
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'priority' => $this->priority,
        ];
    }
}
