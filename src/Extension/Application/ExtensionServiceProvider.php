<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application;

use Kumwe\App\Extension\Runtime\ExtensionContainer;

/**
 * The single entry point an extension package exposes to Kumwe.
 *
 * A manifest names one class under `service_provider`, and `ExtensionRuntimeLoader` instantiates it
 * with no arguments and rejects the extension outright when it does not implement this interface — so
 * this contract, not file scanning, is how an extension gets to run at all. Registration is the only
 * phase every extension takes part in; an extension that also needs the container to be complete, or
 * that serves routes, implements `RuntimeExtension` on top of this.
 *
 * @since  2.0.0
 */
interface ExtensionServiceProvider
{
    /**
     * Compose this extension's services into the container it was given.
     *
     * Runs during the pass over every active provider, while the container is still being filled, so a
     * service another extension registers may not be resolvable yet — resolve collaborators lazily
     * inside the factories registered here, or move the work to `RuntimeExtension::boot()`. The
     * container is restricted to this extension: it exposes only the host services allowlisted to the
     * package plus whatever the package shares itself, and it cannot be retained as a global registry.
     *
     * @param   ExtensionContainer  $container  The extension's own restricted container to share factories on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function register(ExtensionContainer $container): void;
}
