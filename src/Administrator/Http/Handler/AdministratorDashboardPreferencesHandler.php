<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardComposer;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardPreferenceService;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceVersionConflict;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Delivers administrator dashboard preference mutations against the live visible widget catalog.
 *
 * @since  2.0.0
 */
final readonly class AdministratorDashboardPreferencesHandler implements RequestHandlerInterface
{
    /**
     * Bind strict dashboard preference delivery to the administrator navigation projection.
     *
     * @param  DashboardPreferenceService  $preferences  Shared KIS form mutation boundary.
     * @param  AdministratorRenderer       $renderer     Capability-filtered live navigation projection.
     *
     * @since  2.0.0
     */
    public function __construct(
        private DashboardPreferenceService $preferences,
        private AdministratorRenderer $renderer,
    ) {
    }

    /**
     * Save or reset one personal or access-group dashboard preference and redirect to its form notice.
     *
     * @param   ServerRequestInterface  $request  Authenticated, authorized, and CSRF-checked request.
     *
     * @return  ResponseInterface  No-store 303 redirect carrying only a closed result code.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = AdministratorRequest::context($request);
        $capabilities = AdministratorRequest::capabilityMap($request);
        $shortcuts = DashboardComposer::workflowIdentifiers(
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            $this->renderer->visibleNavigation($capabilities),
        );
        $widgets = ['core.dashboard.administrator-context', ...$shortcuts];
        if (isset($capabilities['content.read'])) {
            array_unshift(
                $widgets,
                'core.dashboard.content-summary',
                'core.dashboard.recent-content',
            );
        }

        try {
            $this->preferences->mutate(
                $context,
                SurfaceArea::Administrator,
                SurfaceId::fromString('core.administrator.dashboard'),
                ContributionOwner::core(),
                AdministratorRequest::form($request),
                $widgets,
                $shortcuts,
            );
        } catch (PresentationPreferenceVersionConflict) {
            return self::redirect('conflict');
        } catch (InvalidArgumentException) {
            return self::redirect('invalid');
        }

        return new RedirectResponse(
            '/administrator?dashboard-saved=1#dashboard-customization',
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
            '/administrator?dashboard-error=' . $error . '#dashboard-customization',
            303,
            ['Cache-Control' => 'no-store'],
        );
    }
}
