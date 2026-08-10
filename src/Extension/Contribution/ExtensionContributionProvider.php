<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use Kumwe\CMS\Extension\Runtime\ExtensionContainer;

/**
 * Contract a strict schema-2-or-newer extension implements to hand declared contributions to the CMS.
 *
 * A manifest only declares what an extension contributes; the live objects — capability, workspace,
 * navigation, view, route, field type, entity type — are produced here. The two have to agree, so
 * `ActiveExtensionSet` insists the pairing is exact: a strict provider that does not implement this
 * interface fails bootstrap, and so does a provider that implements it from a schema-1 package, where
 * there would be no declaration to check the registrations against.
 *
 * @since  2.0.0
 */
interface ExtensionContributionProvider
{
    /**
     * Register every contribution this extension's manifest declared.
     *
     * Called after every active provider registered services and before boot or route registration.
     * The registrar is bound to this extension alone, is matched against the manifest declaration, and
     * closes once the phase ends, so an implementation must register everything here and must not
     * retain the registrar for later use.
     *
     * @param   ExtensionContributionRegistrar  $contributions  Owner-bound sink for this extension's declarations.
     * @param   ExtensionContainer              $container      This extension's own container, already registered.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function contribute(
        ExtensionContributionRegistrar $contributions,
        ExtensionContainer $container,
    ): void;
}
