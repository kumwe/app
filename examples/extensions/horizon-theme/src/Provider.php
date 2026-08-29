<?php

declare(strict_types=1);

namespace KumweExample\HorizonTheme;

use Kumwe\Extension\Spi\Application\ExtensionServiceProvider;
use Kumwe\Extension\Spi\Runtime\ExtensionContainer;

/**
 * Registers the Horizon site theme package without adding runtime services.
 *
 * Site templates receive presentation-ready state from Kumwe, so this branded reference theme needs
 * only the standard provider required by the extension lifecycle; every visual decision lives in its
 * templates and stylesheet.
 *
 * @since  2.0.0
 */
final class Provider implements ExtensionServiceProvider
{
    /**
     * Fulfil the extension-provider contract for this presentation-only package.
     *
     * @param   ExtensionContainer  $container  Restricted package container, unused by this theme.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function register(ExtensionContainer $container): void
    {
    }
}
