<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Http\Handler;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use Kumwe\CMS\Portal\Http\PortalRequest;
use Kumwe\CMS\Portal\Presentation\PortalRenderer;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardComposer;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardPreferenceService;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceVersionConflict;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Delivers portal dashboard preference mutations against the live session-visible widget catalog.
 *
 * @since  2.0.0
 */
final readonly class PortalDashboardPreferencesHandler implements RequestHandlerInterface
{
    /**
     * Bind strict dashboard preference delivery to the portal navigation projection.
     *
     * @param  DashboardPreferenceService  $preferences  Shared KIS form mutation boundary.
     * @param  PortalRenderer              $renderer     Session-filtered live navigation projection.
     *
     * @since  2.0.0
     */
    public function __construct(
        private DashboardPreferenceService $preferences,
        private PortalRenderer $renderer,
    ) {
    }

    /**
     * Save or reset one personal or access-group dashboard preference and redirect to its form notice.
     *
     * @param   ServerRequestInterface  $request  Authenticated, authorized, and CSRF-checked portal request.
     *
     * @return  ResponseInterface  No-store 303 redirect carrying only a closed result code.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = PortalRequest::session($request);
        $context = PortalRequest::context($request);
        $capabilities = PortalRequest::capabilityMap($request);
        $shortcuts = DashboardComposer::workflowIdentifiers(
            SurfaceArea::Portal,
            SurfaceId::fromString('core.portal.home'),
            $this->renderer->visibleNavigation($session),
        );
        $widgets = $shortcuts;
        if (isset($capabilities['portal.access'])) {
            array_unshift($widgets, 'core.dashboard.access-context');
        }

        try {
            $this->preferences->mutate(
                $context,
                SurfaceArea::Portal,
                SurfaceId::fromString('core.portal.home'),
                ContributionOwner::core(),
                PortalRequest::form($request),
                $widgets,
                $shortcuts,
            );
        } catch (PresentationPreferenceVersionConflict) {
            return self::redirect('conflict');
        } catch (InvalidArgumentException) {
            return self::redirect('invalid');
        }

        return new RedirectResponse(
            '/portal?dashboard-saved=1#dashboard-customization',
            303,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * Redirect one closed preference failure to the dashboard customization disclosure.
     *
     * @param   string  $error  Closed error code selected by this handler.
     *
     * @return  ResponseInterface  No-store 303 redirect.
     *
     * @since   2.0.0
     */
    private static function redirect(string $error): ResponseInterface
    {
        return new RedirectResponse(
            '/portal?dashboard-error=' . $error . '#dashboard-customization',
            303,
            ['Cache-Control' => 'no-store'],
        );
    }
}
