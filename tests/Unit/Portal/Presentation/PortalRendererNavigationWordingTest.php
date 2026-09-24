<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Portal\Presentation;

use DateTimeImmutable;
use Kumwe\App\BusinessSurface\Presentation\Field\SdkFieldConfigurationAdmission;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\App\Portal\Application\PortalSessionIdentity;
use Kumwe\App\Portal\Presentation\PortalNavigationVisibility;
use Kumwe\App\Portal\Presentation\PortalRenderer;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Contribution\ContributionOwner;
use Kumwe\Access\ResourcePolicyTarget;
use Kumwe\App\Extension\Contribution\CapabilityDefinition;
use Kumwe\App\Extension\Contribution\ResourcePolicyDefinition;
use Kumwe\Portal\Contract\PortalNavigationDefinition;
use Kumwe\Portal\Contract\PortalWorkspaceDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Proves core's portal navigation speaks the request's language while unmapped entries keep their text.
 *
 * The portal shell and its dashboard destinations read the same rows, so both used to stay English in
 * every translated locale. Core entries now resolve through the catalogue, an entry without catalogue
 * wording keeps the text it declared, and a renderer without translation keeps the declared text.
 *
 * @since  2.0.0
 */
#[CoversClass(PortalRenderer::class)]
final class PortalRendererNavigationWordingTest extends TestCase
{
    /**
     * Core portal entries and their group resolve in Arabic; an entry without catalogue wording is unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCorePortalNavigationResolvesInTheRequestLocale(): void
    {
        $renderer = $this->renderer('ar');

        $rows = $this->rowsById($renderer->visibleNavigation($this->session()));
        $html = $renderer->render('proof', [], $this->session());

        self::assertSame('نظرة عامة', $rows['core.portal-home']['label']);
        self::assertSame('افتح مساحة عمل أعمالك.', $rows['core.portal-home']['description']);
        self::assertSame('البوابة', $rows['core.portal-home']['group']);
        self::assertSame('Proof desk', $rows['core.portal-proof-desk']['label']);
        self::assertSame('Proof', $rows['core.portal-proof-desk']['group']);
        self::assertStringContainsString('core.portal=البوابة:مساحة عمل أعمالك الموثّقة.|', $html);
        self::assertStringContainsString('core.portal-proof=Proof:Proof portal workspace.|', $html);
        self::assertStringContainsString('core.portal-security=أمان الحساب;', $html);
    }

    /**
     * Without a translation extension the declared source text renders, never an identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEnvironmentWithoutTranslationKeepsTheDeclaredText(): void
    {
        $renderer = $this->renderer(null);

        $html = $renderer->render('proof', [], $this->session());

        self::assertStringContainsString('core.portal=Portal:Your authenticated business workspace.|', $html);
        self::assertStringContainsString('core.portal-home=Overview;', $html);
    }

    /**
     * An extension's portal entry keeps the text its owner declared while core entries beside it translate.
     *
     * The catalogue carries wording only for core identifiers. An extension that happened to reuse a core
     * identifier's shape must never be relabelled with core wording, so ownership, not the identifier, decides
     * whether the catalogue is consulted at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExtensionPortalEntryKeepsItsDeclaredTextInATranslatedLocale(): void
    {
        $renderer = $this->renderer('ar', true);

        $rows = $this->rowsById($renderer->visibleNavigation($this->session(['acme.tools.portal'])));

        self::assertSame('Acme desk', $rows['acme.tools.portal-desk']['label']);
        self::assertSame('Open the acme desk.', $rows['acme.tools.portal-desk']['description']);
        self::assertSame('نظرة عامة', $rows['core.portal-home']['label']);
    }

    /**
     * Build a renderer over core plus one unmapped entry, printing workspaces and entries.
     *
     * @param   ?string  $locale     Request locale, or null for no translation extension.
     * @param   bool     $extension  Whether an `acme/tools` workspace and entry are also registered.
     *
     * @return  PortalRenderer  Renderer whose visibility predicate admits every entry.
     *
     * @since   2.0.0
     */
    private function renderer(?string $locale, bool $extension = false): PortalRenderer
    {
        $registries = new ExtensionContributionRegistrySet(
            new DeterministicCanonicalEncoder(),
            new SdkFieldConfigurationAdmission(),
        );
        // A core-owned entry with no catalogue wording stands for anything not in the wording map, such as
        // a later core addition: it keeps its declared text instead of printing an identifier.
        $owner = ContributionOwner::core();
        $registries->portalWorkspaces()->register(
            $owner,
            new PortalWorkspaceDefinition('core.portal-proof', 'Proof', 'Proof portal workspace.', 90),
        );
        $registries->portalNavigation()->register($owner, new PortalNavigationDefinition(
            'core.portal-proof-desk',
            'core.portal-proof',
            'Proof desk',
            'Open the proof desk.',
            '/portal/proof-desk',
            'dashboard',
            'portal.access',
            10,
        ));
        if ($extension) {
            $acme = ContributionOwner::extension('acme/tools');
            $registries->capabilities()->register(
                $acme,
                new CapabilityDefinition('acme.tools.portal', 'Use the acme desk', 'Use the acme portal desk.'),
            );
            $registries->resourcePolicies()->register($acme, new ResourcePolicyDefinition(
                'acme.tools.portal-session',
                'acme.tools.portal',
                [new ResourcePolicyTarget('portal_session')],
            ));
            $registries->portalWorkspaces()->register(
                $acme,
                new PortalWorkspaceDefinition('acme.tools.portal', 'Acme', 'Acme portal workspace.', 95),
            );
            $registries->portalNavigation()->register($acme, new PortalNavigationDefinition(
                'acme.tools.portal-desk',
                'acme.tools.portal',
                'Acme desk',
                'Open the acme desk.',
                '/portal/acme-desk',
                'dashboard',
                'acme.tools.portal',
                20,
            ));
        }
        $twig = new Environment(new ArrayLoader([
            'portal/proof.twig' => '{% for workspace in portal_workspaces %}'
                . '{{ workspace.id }}={{ workspace.label }}:{{ workspace.description }}|{% endfor %}'
                . '{% for item in portal_navigation %}{{ item.id }}={{ item.label }};{% endfor %}',
        ]), ['strict_variables' => true]);
        if ($locale !== null) {
            $twig->addExtension(InterfaceTranslation::twigExtension($locale));
        }
        $visibility = $this->createStub(PortalNavigationVisibility::class);
        $visibility->method('visible')->willReturn(true);

        return new PortalRenderer(
            $twig,
            $registries->portalNavigation(),
            $registries->portalTemplates(),
            $visibility,
        );
    }

    /**
     * Build a resolved portal session holding portal access.
     *
     * @param   list<string>  $extra  Further capabilities the principal holds.
     *
     * @return  PortalSession  Session for the default site without an organization membership.
     *
     * @since   2.0.0
     */
    private function session(array $extra = []): PortalSession
    {
        $now = new DateTimeImmutable('2026-08-15T10:00:00+00:00');
        $principal = AuthenticatedPrincipal::issueFromStrings(
            new \stdClass(),
            '018f0000-0000-7000-8000-000000000001',
            ['portal.access', ...$extra],
        );

        return new PortalSession(
            '018f0000-0000-7000-8000-000000000002',
            new PortalSessionIdentity($principal, new PortalContext(SiteContext::default(), null), 1),
            str_repeat('c', 43),
            $now,
            null,
            $now->modify('+1 hour'),
        );
    }

    /**
     * Index presented navigation rows by their identifier.
     *
     * @param   list<array<string, int|string>>  $rows  Rows the renderer presented.
     *
     * @return  array<string, array<string, int|string>>  The same rows keyed by `id`.
     *
     * @since   2.0.0
     */
    private function rowsById(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['id']] = $row;
        }

        return $indexed;
    }
}
