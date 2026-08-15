<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

/**
 * Service surface an extension is given in place of the application container.
 *
 * Every extension callback — `register()`, `boot()` and each contribution — receives one of these rather
 * than the host container, so an extension reaches exactly the services the runtime chose to pass it,
 * plus the ones it registered itself. An implementation owes two guarantees: an identifier that was
 * never granted fails instead of resolving, and a factory the extension registers cannot take over the
 * name of a service it was handed. `RestrictedExtensionContainer` is the implementation the runtime
 * builds, one per active extension. This is an API compatibility boundary, not a security sandbox — it
 * bounds what trusted in-process extension code can reach, not what hostile code could do. See
 * `docs/architecture/extensions.md` for the ambient authority admitted code inherits regardless.
 *
 * @since  2.0.0
 */
interface ExtensionContainer
{
    /**
     * Resolve a service this extension is allowed to see.
     *
     * @param   string  $id  Identifier of a service the runtime granted, or of one this extension shared.
     *
     * @return  object  The resolved service; an identifier that was not granted is an error, never a null
     *          or placeholder return.
     *
     * @since   2.0.0
     */
    public function get(string $id): object;

    /**
     * Register a factory for a service of this extension's own, built on first resolution.
     *
     * The identifier has to stay inside the extension's own namespace, which is what keeps two
     * extensions from colliding and keeps either of them from shadowing a granted host service.
     *
     * @param   string                                $id       Namespaced identifier to register under.
     * @param   callable(ExtensionContainer): object  $factory  Receives this container and returns the
     *          service; called at most once per identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function share(string $id, callable $factory): void;
}
