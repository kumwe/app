<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation\Twig;

use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Twig loader that exposes only an explicitly listed set of template names from the loader it wraps.
 *
 * An activated administrator theme must be able to restyle the back office without being able to
 * replace login views or controller-specific pages, so the theme's own loader is wrapped here with the
 * override contract the theme was activated against — `layout.twig` and its `@admin-theme` alias.
 * Every other name is refused, which lets the core loader chained behind this one answer it and keeps
 * the theme's reach auditable from a single list. The wrapped loader is never consulted for a name the
 * contract does not carry, so a theme cannot influence resolution by shipping extra files.
 *
 * @since  2.0.0
 */
final readonly class ContractRestrictedLoader implements LoaderInterface
{
    /**
     * Contracted template names, held as a lookup keyed by name for constant-time membership tests.
     *
     * @var    array<string, true>
     * @since  2.0.0
     */
    private array $allowed;

    /**
     * Wrap a loader in the override contract it is permitted to answer.
     *
     * @param  LoaderInterface  $loader   Loader being restricted, in practice the activated theme's loader.
     * @param  list<string>     $allowed  Template names the contract permits, including namespaced aliases.
     *
     * @since  2.0.0
     */
    public function __construct(private LoaderInterface $loader, array $allowed)
    {
        $this->allowed = array_fill_keys($allowed, true);
    }

    /**
     * Return the compilable source of a contracted template.
     *
     * @param   string  $name  Template name as written in the Twig reference being resolved.
     *
     * @return  Source  Source as the wrapped loader reports it, unmodified by the restriction.
     *
     * @throws  LoaderError  When the name is outside the contract, or the wrapped loader cannot resolve it.
     *
     * @since   2.0.0
     */
    public function getSourceContext(string $name): Source
    {
        $this->assertAllowed($name);

        return $this->loader->getSourceContext($name);
    }

    /**
     * Return the key a contracted template's compiled form is cached under.
     *
     * @param   string  $name  Template name as written in the Twig reference being resolved.
     *
     * @return  string  The wrapped loader's key, so restricting a loader never changes cache identity.
     *
     * @throws  LoaderError  When the name is outside the contract, or the wrapped loader cannot resolve it.
     *
     * @since   2.0.0
     */
    public function getCacheKey(string $name): string
    {
        $this->assertAllowed($name);

        return $this->loader->getCacheKey($name);
    }

    /**
     * Report whether a contracted template is unchanged since its compiled form was written.
     *
     * @param   string  $name  Template name as written in the Twig reference being resolved.
     * @param   int     $time  Unix timestamp at which the compiled template was cached.
     *
     * @return  bool  True when the cached compilation is still current and may be reused.
     *
     * @throws  LoaderError  When the name is outside the contract, or the wrapped loader cannot resolve it.
     *
     * @since   2.0.0
     */
    public function isFresh(string $name, int $time): bool
    {
        $this->assertAllowed($name);

        return $this->loader->isFresh($name, $time);
    }

    /**
     * Report whether a name is both inside the contract and present in the wrapped loader.
     *
     * This is the one lookup that answers false instead of raising, which is what allows a chain
     * loader to fall through to the core loader for every name the contract does not cover.
     *
     * @param   string  $name  Template name as written in the Twig reference being resolved.
     *
     * @return  bool  True only when the contract lists the name and the wrapped loader holds it.
     *
     * @since   2.0.0
     */
    public function exists(string $name): bool
    {
        return isset($this->allowed[$name]) && $this->loader->exists($name);
    }

    /**
     * Refuse a template name the override contract does not carry.
     *
     * @param   string  $name  Template name to test before the wrapped loader is consulted.
     *
     * @return  void
     *
     * @throws  LoaderError  When the name is outside the contract, naming the template for the operator.
     *
     * @since   2.0.0
     */
    private function assertAllowed(string $name): void
    {
        if (!isset($this->allowed[$name])) {
            throw new LoaderError(sprintf('Administrator theme template %s is outside the override contract.', $name));
        }
    }
}
