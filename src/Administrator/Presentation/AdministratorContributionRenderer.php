<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Presentation;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Object-capability renderer fixed to one signed administrator route owner and view.
 *
 * Extension code supplies only the bounded view model and the active request. The owner, view,
 * capability-filtered shell data and CSRF value all come from the host, so a handler cannot select a
 * different extension template or fabricate an administrator rendering context.
 *
 * @since  2.0.0
 */
final readonly class AdministratorContributionRenderer implements AdministratorRouteRenderer
{
    /** @since 2.0.0 */
    public function __construct(
        private AdministratorRenderer $renderer,
        private string $extension,
        private string $view,
        private object $provenance,
    ) {
    }

    /**
     * Render the one signed view after requiring host-issued request provenance.
     *
     * @param   array<string, mixed>    $model    Extension view model.
     * @param   ServerRequestInterface  $request  Active administrator request.
     *
     * @since   2.0.0
     */
    public function render(array $model, ServerRequestInterface $request): string
    {
        $session = $request->getAttribute(AdministratorSession::REQUEST_ATTRIBUTE);
        $context = $request->getAttribute(ExecutionContext::REQUEST_ATTRIBUTE);
        if (
            !$session instanceof AdministratorSession
            || !$context instanceof ExecutionContext
            || !$context->hasProvenance($this->provenance)
            || $context->principal() !== $session->principal
            || $context->sessionId() !== $session->id
            || $context->surface() !== AuthenticatedSurface::Administrator
        ) {
            throw new InvalidArgumentException(
                'An active host-issued administrator session and execution context are required.',
            );
        }

        $capabilities = [];
        foreach ($session->principal->capabilities() as $capability) {
            $capabilities[$capability->value()] = true;
        }

        $model['csrf'] = $session->csrfToken;
        $model['capabilities'] = $capabilities;
        $model['active_navigation'] = $this->view;

        return $this->renderer->renderExtension($this->extension, $this->view, $model);
    }
}
