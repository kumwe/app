<?php

declare(strict_types=1);

namespace KumweContract\ManifestTwo;

use Kumwe\App\Application\Authorization\ResourcePolicyTarget;
use Kumwe\App\Extension\Application\ExtensionServiceProvider;
use Kumwe\App\Extension\Contribution\AdministratorNavigationDefinition;
use Kumwe\App\Extension\Contribution\AdministratorViewDefinition;
use Kumwe\App\Extension\Contribution\AdministratorWorkspaceDefinition;
use Kumwe\App\Extension\Contribution\CapabilityDefinition;
use Kumwe\App\Extension\Contribution\ExtensionContributionProvider;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrar;
use Kumwe\App\Extension\Contribution\ResourcePolicyDefinition;
use Kumwe\App\Extension\Runtime\ExtensionContainer;

/**
 * Compatibility provider for the manifest-2 generation of the extension contract.
 *
 * It contributes exactly what its manifest declares and nothing else, so the lifecycle fixture can
 * assert that the surface a schema-2 package receives is still the surface schema 2 promised. Nothing
 * here is example code for authors; `examples/extensions` holds that. This exists to fail when the
 * generation moves.
 *
 * @since  2.0.0
 */
final class Provider implements ExtensionServiceProvider, ExtensionContributionProvider
{
    /**
     * Register the package's own services; this generation's fixture needs none.
     *
     * @param   ExtensionContainer  $container  Restricted owner-scoped service surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function register(ExtensionContainer $container): void
    {
    }

    /**
     * Contribute the declared schema-2 capability, policy and administrator surface.
     *
     * @param   ExtensionContributionRegistrar  $contributions  Registrar bound to this package's owner.
     * @param   ExtensionContainer              $container      Restricted owner-scoped service surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function contribute(
        ExtensionContributionRegistrar $contributions,
        ExtensionContainer $container,
    ): void {
        $contributions->capability(new CapabilityDefinition(
            'kumwe.contract-manifest-two.manage',
            'Manage the manifest-two compatibility surface',
            'Open the workspace the manifest-two compatibility package contributes.',
        ));
        $contributions->resourcePolicy(new ResourcePolicyDefinition(
            'kumwe.contract-manifest-two.administrator',
            'kumwe.contract-manifest-two.manage',
            [new ResourcePolicyTarget('administrator_session')],
        ));
        $contributions->administratorWorkspace(new AdministratorWorkspaceDefinition(
            'kumwe.contract-manifest-two.workspace',
            'Manifest two',
            'Compatibility workspace for the manifest-two generation.',
            150,
        ));
        $contributions->administratorNavigation(new AdministratorNavigationDefinition(
            'kumwe.contract-manifest-two.navigation',
            'kumwe.contract-manifest-two.workspace',
            'Manifest two',
            'Open the manifest-two compatibility surface',
            '/',
            'extensions',
            'kumwe.contract-manifest-two.manage',
            10,
            'compatibility manifest generation two',
            'kumwe.contract-manifest-two.index',
        ));
        $contributions->administratorView(new AdministratorViewDefinition(
            'kumwe.contract-manifest-two.index',
            'index.twig',
        ));
    }
}
