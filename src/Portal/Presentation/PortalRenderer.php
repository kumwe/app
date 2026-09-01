<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Presentation;

use Kumwe\Extension\Spi\Binding\Http\PortalRouteRenderer;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
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
     * Bind the renderer to its isolated Twig environment and portal-only contribution registries.
     *
     * @param  Environment                 $twig        Portal Twig environment.
     * @param  PortalNavigationRegistry    $navigation  Capability and live-trust-filtered menu.
     * @param  PortalTemplateRegistry      $templates   Explicit portal template authority.
     * @param  PortalNavigationVisibility  $visibility  Request-session navigation predicate.
     * @param  ?ViteAssetManifest          $assets      Built portal asset manifest, or null for fallbacks.
     * @param  ?object                     $extensionRequestProvenance Private composition-root authority
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

        return array_values(array_filter(
            $this->navigation->visible($capabilities),
            fn (array $item): bool => $this->visibility->visible($session, $item),
        ));
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
            : $this->navigation->visible($capabilities);

        $assetEntry = ($this->assets ?? new ViteAssetManifest(''))->entry(
            'assets/portal/main.ts',
            '/assets/portal.css',
        );

        $data['portal_session'] = $session;
        $data['portal_site'] = $session?->identity->context->site->identifier();
        $data['portal_organization'] = $session?->identity->context->membership?->organization()->identifier();
        $data['portal_workspace'] = $session?->identity->context->membership?->workspace()?->identifier();
        $data['portal_navigation'] = $navigation;
        $data['portal_workspaces'] = $this->navigation->visibleWorkspaces($capabilities, $navigation);
        $data['portal_assets'] = $assetEntry->toArray();
        $data['active_navigation'] ??= '';

        return $data;
    }
}
