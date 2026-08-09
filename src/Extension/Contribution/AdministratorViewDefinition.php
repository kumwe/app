<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;

/**
 * Validated declaration binding a contributed administrator view name to its Twig template.
 *
 * An extension template is never named directly by a handler. `AdministratorRenderer` takes a view
 * name, resolves it through `AdministratorViewRegistry`, and prefixes the template with the Twig
 * namespace isolated to that extension. Constraining the template path here — relative, ending in
 * `.twig`, free of `..` — is what keeps that indirection from reaching outside the namespace.
 *
 * @since  2.0.0
 */
final readonly class AdministratorViewDefinition implements ContributionDefinition
{
    /**
     * Validate one administrator view declaration.
     *
     * @param   string  $name      Dotted view identifier; ownership is checked when it is registered.
     * @param   string  $template  Template path relative to the owner's Twig namespace, ending in `.twig`.
     *
     * @throws  InvalidArgumentException  When the identifier is malformed or the template path is unsafe.
     *
     * @since   2.0.0
     */
    public function __construct(public string $name, public string $template)
    {
        AdministratorWorkspaceDefinition::assertIdentifier($name, 'view');
        if (
            preg_match('#^(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.twig$#D', $template) !== 1
            || str_contains($template, '..')
        ) {
            throw new InvalidArgumentException('A contributed administrator view template path is unsafe.');
        }
    }

    /**
     * Report the identifier the contribution registries key this view by.
     *
     * @return  string  The dotted view name exactly as declared.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->name;
    }

    /**
     * Export the declaration in the shape the manifest declaration is compared against.
     *
     * @return  array{name: string, template: string}
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'template' => $this->template];
    }
}
