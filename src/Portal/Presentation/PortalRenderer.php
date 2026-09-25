<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Presentation;

use Kumwe\Extension\Spi\Binding\Http\PortalRouteRenderer;
use Kumwe\Contribution\ContributionOwner;
use Kumwe\App\Localization\Presentation\TranslationTwigExtension;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\App\Portal\Contribution\PortalNavigationRegistry;
use Kumwe\App\Portal\Contribution\PortalTemplateRegistry;
use Kumwe\App\Presentation\Asset\ViteAssetManifest;
use Kumwe\App\Presentation\Twig\IsolatedTwigEnvironmentFactory;
use Twig\Environment;

/**
 * Renderer for the distinct portal shell and its explicitly contributed templates.
 *
 * No recovery renderer is accepted here: the recovery container must not construct the portal at all,
 * and a broken portal contribution therefore cannot gain access to the administrator recovery shell.
 *
 * @since  2.0.0
 */
final readonly class PortalRenderer
{
    /**
     * Catalogue identifiers of the wording core's own portal navigation renders in, keyed by entry.
     *
     * Contributed navigation carries source-language text because the contribution contract is locale
     * free. Core's portal entries are authored in all nine catalogues, so they resolve per request
     * through the translator the templates use; an extension's entries keep their declared text. The
     * identifiers are written out so the catalogue gate sees each one referenced.
     *
     * @var    array<string, array{label: string, description: string}>
     * @since  2.0.0
     */
    private const array CORE_NAVIGATION_WORDING = [
        'core.portal-home' => [
            'label' => 'core.navigation.portal.portal-home.label',
            'description' => 'core.navigation.portal.portal-home.description',
        ],
        'core.portal-business-records' => [
            'label' => 'core.navigation.portal.portal-business-records.label',
            'description' => 'core.navigation.portal.portal-business-records.description',
        ],
        'core.portal-business-reports' => [
            'label' => 'core.navigation.portal.portal-business-reports.label',
            'description' => 'core.navigation.portal.portal-business-reports.description',
        ],
        'core.portal-security' => [
            'label' => 'core.navigation.portal.portal-security.label',
            'description' => 'core.navigation.portal.portal-security.description',
        ],
        'core.portal-approvals' => [
            'label' => 'core.navigation.portal.portal-approvals.label',
            'description' => 'core.navigation.portal.portal-approvals.description',
        ],
    ];

    /**
     * Catalogue identifiers of core's portal navigation group heading and description, keyed by group.
     *
     * @var    array<string, array{label: string, description: string}>
     * @since  2.0.0
     */
    private const array CORE_WORKSPACE_WORDING = [
        'core.portal' => [
            'label' => 'core.navigation.portal_workspace.portal.label',
            'description' => 'core.navigation.portal_workspace.portal.description',
        ],
    ];

    /**
     * Bind the renderer to its isolated Twig environment and portal-only contribution registries.
     *
     * @param  Environment                 $twig                        Portal Twig environment.
     * @param  PortalNavigationRegistry    $navigation                  Capability and live-trust-filtered menu.
     * @param  PortalTemplateRegistry      $templates                   Explicit portal template authority.
     * @param  PortalNavigationVisibility  $visibility                  Request-session navigation predicate.
     * @param ?ViteAssetManifest $assets Built portal asset manifest, or null for fallbacks.
     * @param  ?object                     $extensionRequestProvenance  Private composition-root authority
     *         required to mint extension route renderer capabilities.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Environment $twig,
        private PortalNavigationRegistry $navigation,
        private PortalTemplateRegistry $templates,
        private PortalNavigationVisibility $visibility,
        private ?ViteAssetManifest $assets = null,
        private ?object $extensionRequestProvenance = null,
    ) {
    }

    /**
     * Render a core template below `templates/portal` with live session and navigation context.
     *
     * @param   string                $template  Safe core template base name.
     * @param   array<string, mixed>  $data      Template-specific variables.
     * @param   ?PortalSession        $session   Resolved session, absent only on login.
     *
     * @return  string  Rendered HTML document.
     *
     * @throws  \InvalidArgumentException  When the template base name is unsafe.
     * @throws  \Twig\Error\Error  When strict rendering fails.
     *
     * @since   2.0.0
     */
    public function render(string $template, array $data = [], ?PortalSession $session = null): string
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $template) !== 1) {
            throw new \InvalidArgumentException('A portal core template name is invalid.');
        }

        return $this->twig->render('portal/' . $template . '.twig', $this->shared($data, $session));
    }

    /**
     * Render an extension template only through its owner-checked registry and isolated namespace.
     *
     * @param   string                $extension  Canonical `vendor/name` owner identifier.
     * @param   string                $template   Owned dotted portal template identifier.
     * @param   array<string, mixed>  $data       Template-specific variables.
     * @param   PortalSession         $session    Live portal session.
     *
     * @return  string  Rendered HTML document inside the portal layout contract.
     *
     * @throws  \InvalidArgumentException  When ownership or the extension identifier is invalid.
     * @throws  \Twig\Error\Error  When strict rendering fails.
     *
     * @since   2.0.0
     */
    public function renderExtension(
        string $extension,
        string $template,
        array $data,
        PortalSession $session,
    ): string {
        $owner = ContributionOwner::extension($extension);
        $path = $this->templates->template($owner, $template);

        return $this->twig->render(
            '@' . IsolatedTwigEnvironmentFactory::extensionNamespace($extension) . '/' . $path,
            $this->shared($data, $session),
        );
    }

    /**
     * Mint a renderer capability closed over one validated owner, template and active navigation item.
     *
     * @param   string  $extension         Owning `vendor/name` package identifier.
     * @param   string  $template          Signed template path the capability may render.
     * @param   string  $activeNavigation  Portal navigation identifier marked active for the route.
     *
     * @return  PortalRouteRenderer  Renderer bound to exactly this owner, template, and provenance.
     *
     * @since   2.0.0
     */
    public function forExtensionRoute(
        string $extension,
        string $template,
        string $activeNavigation,
    ): PortalRouteRenderer {
        $provenance = $this->extensionRequestProvenance
            ?? throw new \LogicException('Extension route rendering requires the private host provenance.');

        return new PortalContributionRenderer($this, $extension, $template, $activeNavigation, $provenance);
    }

    /**
     * Project the exact portal navigation visible in one authenticated session.
     *
     * Dashboard composition and the portal shell deliberately call this same boundary. That keeps
     * capability, live extension trust, generated-business discovery, and request-session policy from
     * drifting into two subtly different menus, and means dashboard workflow destinations never come
     * from request data or an unfiltered registry read.
     *
     * @param   PortalSession  $session  Current authenticated and policy-resolved portal session.
     *
     * @return  list<array<string, int|string>>  Navigation rows safe to present for this session.
     *
     * @since   2.0.0
     */
    public function visibleNavigation(PortalSession $session): array
    {
        $capabilities = [];
        foreach ($session->identity->principal->capabilities() as $capability) {
            $capabilities[$capability->value()] = true;
        }

        return $this->localizedNavigation(array_values(array_filter(
            $this->navigation->visible($capabilities),
            fn (array $item): bool => $this->visibility->visible($session, $item),
        )));
    }

    /**
     * Add only safe shell context derived from the resolved session.
     *
     * @param   array<string, mixed>  $data     Template-specific variables.
     * @param   ?PortalSession        $session  Resolved session or null on login.
     *
     * @return  array<string, mixed>  Complete strict-Twig context.
     *
     * @since   2.0.0
     */
    private function shared(array $data, ?PortalSession $session): array
    {
        $capabilities = [];
        if ($session instanceof PortalSession) {
            foreach ($session->identity->principal->capabilities() as $capability) {
                $capabilities[$capability->value()] = true;
            }
        }
        $navigation = $session instanceof PortalSession
            ? $this->visibleNavigation($session)
            : $this->localizedNavigation($this->navigation->visible($capabilities));

        $assetEntry = ($this->assets ?? new ViteAssetManifest(''))->entry(
            'assets/portal/main.ts',
            '/assets/portal.css',
        );

        $data['portal_session'] = $session;
        $data['portal_site'] = $session?->identity->context->site->identifier();
        $data['portal_organization'] = $session?->identity->context->membership?->organization()->identifier();
        $data['portal_workspace'] = $session?->identity->context->membership?->workspace()?->identifier();
        $data['portal_navigation'] = $navigation;
        $data['portal_workspaces'] = $this->localizedWorkspaces(
            $this->navigation->visibleWorkspaces($capabilities, $navigation),
        );
        $data['portal_assets'] = $assetEntry->toArray();
        $data['active_navigation'] ??= '';

        return $data;
    }

    /**
     * Put core's portal navigation into the language of the request, leaving contributed entries as declared.
     *
     * Only an entry owned by core and listed in `CORE_NAVIGATION_WORDING` is resolved, and its `group`
     * follows its workspace heading, so the shell and the dashboard destinations built from the same rows
     * speak one language. Without a registered translation extension the declared text is kept.
     *
     * @param   list<array<string, int|string>>  $rows  Visible navigation rows.
     *
     * @return  list<array<string, int|string>>  The same rows, in the same order, with core wording resolved.
     *
     * @since   2.0.0
     */
    private function localizedNavigation(array $rows): array
    {
        $translation = $this->translation();
        if ($translation === null) {
            return $rows;
        }
        foreach ($rows as $index => $row) {
            if (($row['owner'] ?? null) !== ContributionOwner::CORE) {
                continue;
            }
            $wording = self::CORE_NAVIGATION_WORDING[(string) ($row['id'] ?? '')] ?? null;
            if ($wording !== null) {
                $row['label'] = $this->resolve($translation, $wording['label'], (string) $row['label']);
                $row['description'] = $this->resolve(
                    $translation,
                    $wording['description'],
                    (string) ($row['description'] ?? ''),
                );
            }
            $group = self::CORE_WORKSPACE_WORDING[(string) ($row['workspace'] ?? '')] ?? null;
            if ($group !== null) {
                $row['group'] = $this->resolve($translation, $group['label'], (string) ($row['group'] ?? ''));
            }
            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * Put core's portal group heading and description into the language of the request.
     *
     * @param   list<array{id: string, label: string, description: string, priority: int, dom_id: string}>  $rows
     *          Visible workspace groups from the registry.
     *
     * @return  list<array{id: string, label: string, description: string, priority: int, dom_id: string}>
     *          The same groups with core wording resolved and contributed groups unchanged.
     *
     * @since   2.0.0
     */
    private function localizedWorkspaces(array $rows): array
    {
        $translation = $this->translation();
        if ($translation === null) {
            return $rows;
        }
        foreach ($rows as $index => $row) {
            $wording = self::CORE_WORKSPACE_WORDING[$row['id']] ?? null;
            if ($wording === null) {
                continue;
            }
            $row['label'] = $this->resolve($translation, $wording['label'], $row['label']);
            $row['description'] = $this->resolve($translation, $wording['description'], $row['description']);
            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * Answer the translation surface the portal templates resolve messages through.
     *
     * @return  ?TranslationTwigExtension  The registered extension, or null when the environment has none.
     *
     * @since   2.0.0
     */
    private function translation(): ?TranslationTwigExtension
    {
        return $this->twig->hasExtension(TranslationTwigExtension::class)
            ? $this->twig->getExtension(TranslationTwigExtension::class)
            : null;
    }

    /**
     * Resolve one catalogue message, keeping the declared text when no catalogue layer carries it.
     *
     * @param   TranslationTwigExtension  $translation  Translation surface bound to the request locale.
     * @param   string                    $identifier   Catalogue identifier of the wording.
     * @param   string                    $declared     Source-language text the contribution declared.
     *
     * @return  string  The localized wording, or the declared text when the identifier is unresolved.
     *
     * @since   2.0.0
     */
    private function resolve(TranslationTwigExtension $translation, string $identifier, string $declared): string
    {
        $resolved = $translation->translate($identifier);

        return $resolved === $identifier ? $declared : $resolved;
    }
}
