<?php

declare(strict_types=1);

namespace KumweExample\MinimalAdministratorTemplate;

use Kumwe\Extension\Spi\Application\ExtensionServiceProvider;
use Kumwe\Extension\Spi\Runtime\ExtensionContainer;

/**
 * Registers the minimal administrator template package without adding runtime services.
 *
 * The package changes only the protected administrator shell and approved presentation layer. Core and
 * extension views continue to own task markup, authorization, validation, and mutation behavior.
 *
 * @since  2.0.0
 */
final class Provider implements ExtensionServiceProvider
{
    /**
     * Fulfil the extension-provider contract for this presentation-only package.
     *
     * @param   ExtensionContainer  $container  Restricted package container, unused by this template.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function register(ExtensionContainer $container): void
    {
    }
}
