<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Contribution;

use InvalidArgumentException;
use Kumwe\App\Extension\Contribution\ContributionDefinition;

/**
 * Explicit portal template declaration confined to its owner's isolated Twig namespace.
 *
 * @since  2.0.0
 */
final readonly class PortalTemplateDefinition implements ContributionDefinition
{
    /**
     * Validate a dotted template name and safe relative Twig path.
     *
     * @param   string  $name      Owner-scoped template identifier.
     * @param   string  $template  Relative `.twig` path without traversal.
     *
     * @throws  InvalidArgumentException  When either value is unsafe.
     *
     * @since   2.0.0
     */
    public function __construct(public string $name, public string $template)
    {
        PortalWorkspaceDefinition::assertIdentifier($name, 'template');
        if (
            preg_match('#^(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.twig$#D', $template) !== 1
            || str_contains($template, '..')
        ) {
            throw new InvalidArgumentException('A contributed portal template path is unsafe.');
        }
    }

    /**
     * Return the claimed template identifier.
     *
     * @return  string  Dotted name.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->name;
    }

    /**
     * Export the manifest-comparison shape.
     *
     * @return  array{name: string, template: string}  Template declaration.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'template' => $this->template];
    }
}
