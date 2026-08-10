<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Presentation;

use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Portal\Application\PortalSession;
use Kumwe\CMS\Portal\Contribution\PortalNavigationRegistry;
use Kumwe\CMS\Portal\Contribution\PortalTemplateRegistry;
use Kumwe\CMS\Presentation\Twig\IsolatedTwigEnvironmentFactory;
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
     * @param  Environment               $twig        Portal Twig environment.
     * @param  PortalNavigationRegistry  $navigation  Capability and live-trust-filtered menu.
     * @param  PortalTemplateRegistry    $templates   Explicit portal template authority.
     * @param  PortalNavigationVisibility $visibility Request-session navigation predicate.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Environment $twig,
        private PortalNavigationRegistry $navigation,
        private PortalTemplateRegistry $templates,
        private PortalNavigationVisibility $visibility,
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
        $navigation = $this->navigation->visible($capabilities);
        if ($session instanceof PortalSession) {
            $navigation = array_values(array_filter(
                $navigation,
                fn (array $item): bool => $this->visibility->visible($session, $item),
            ));
        }

        return $data + [
            'portal_session' => $session,
            'portal_site' => $session?->identity->context->site->identifier(),
            'portal_organization' => $session?->identity->context->membership?->organization()->identifier(),
            'portal_workspace' => $session?->identity->context->membership?->workspace()?->identifier(),
            'portal_navigation' => $navigation,
            'portal_workspaces' => $this->navigation->visibleWorkspaces($capabilities, $navigation),
            'active_navigation' => '',
        ];
    }
}
