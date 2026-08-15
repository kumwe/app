<?php

declare(strict_types=1);

namespace KumweContract\ManifestThree;

use Kumwe\CMS\Application\Authorization\ResourcePolicyTarget;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresentationContext;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresentationContribution;
use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;
use Kumwe\CMS\Extension\Contribution\AdministratorNavigationDefinition;
use Kumwe\CMS\Extension\Contribution\AdministratorViewDefinition;
use Kumwe\CMS\Extension\Contribution\AdministratorWorkspaceDefinition;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinition;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionProvider;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrar;
use Kumwe\CMS\Extension\Contribution\ResourcePolicyDefinition;
use Kumwe\CMS\Extension\Runtime\ExtensionContainer;
use Kumwe\CMS\Portal\Contribution\PortalNavigationDefinition;
use Kumwe\CMS\Portal\Contribution\PortalTemplateDefinition;
use Kumwe\CMS\Portal\Contribution\PortalWorkspaceDefinition;

/**
 * Compatibility provider for the manifest-3 generation of the extension contract.
 *
 * Schema 3 is the generation that added the portal surface and safe field presentation on top of schema
 * 2, so this provider registers exactly those additions alongside the schema-2 surface it inherits. It
 * is a compatibility probe, not example code; `examples/extensions` holds the material authors copy.
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
     * Contribute the declared schema-3 capability, policy, administrator, portal and field surface.
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
            'kumwe.contract-manifest-three.manage',
            'Manage the manifest-three compatibility surface',
            'Open the workspaces the manifest-three compatibility package contributes.',
        ));
        $contributions->resourcePolicy(new ResourcePolicyDefinition(
            'kumwe.contract-manifest-three.administrator',
            'kumwe.contract-manifest-three.manage',
            [new ResourcePolicyTarget('administrator_session'), new ResourcePolicyTarget('portal_session')],
        ));
        $contributions->administratorWorkspace(new AdministratorWorkspaceDefinition(
            'kumwe.contract-manifest-three.workspace',
            'Manifest three',
            'Compatibility workspace for the manifest-three generation.',
            150,
        ));
        $contributions->administratorNavigation(new AdministratorNavigationDefinition(
            'kumwe.contract-manifest-three.navigation',
            'kumwe.contract-manifest-three.workspace',
            'Manifest three',
            'Open the manifest-three compatibility surface',
            '/',
            'extensions',
            'kumwe.contract-manifest-three.manage',
            10,
            'compatibility manifest generation three',
            'kumwe.contract-manifest-three.index',
        ));
        $contributions->administratorView(new AdministratorViewDefinition(
            'kumwe.contract-manifest-three.index',
            'index.twig',
        ));
        $contributions->portalWorkspace(new PortalWorkspaceDefinition(
            'kumwe.contract-manifest-three.portal-workspace',
            'Manifest three',
            'Compatibility portal workspace for the manifest-three generation.',
            150,
        ));
        $contributions->portalNavigation(new PortalNavigationDefinition(
            'kumwe.contract-manifest-three.portal-navigation',
            'kumwe.contract-manifest-three.portal-workspace',
            'Manifest three',
            'Open the manifest-three portal compatibility surface',
            '/',
            'extensions',
            'kumwe.contract-manifest-three.manage',
            10,
            'compatibility portal generation three',
            'kumwe.contract-manifest-three.portal-status',
        ));
        $contributions->portalTemplate(new PortalTemplateDefinition(
            'kumwe.contract-manifest-three.portal-status',
            'status.twig',
        ));
        $grade = new FieldTypeDefinition(
            'kumwe.contract-manifest-three.grade',
            'Compatibility grade',
            'A package-owned bounded grade value used only by the compatibility fixture.',
            'string',
            'string',
            ['options'],
        );
        $contributions->fieldType($grade);
        $contributions->fieldPresentation(
            new FieldPresentationContribution($grade->id, FieldPresentationContext::cases()),
            new GradeFieldPresenter(),
        );
    }
}
