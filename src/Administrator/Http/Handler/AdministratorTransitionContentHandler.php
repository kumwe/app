<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Content\Application\ContentService;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Moves one content entry through its workflow from the status controls of the administrator editor.
 *
 * The route is mounted on `POST /administrator/content/{id}/transition` behind the CSRF middleware and
 * demands only `content.read`, because the capability that really decides the move is declared by the
 * workflow edge itself and resolved inside `ContentService::transition()` — submitting for review and
 * publishing are deliberately not the same grant. This handler therefore contributes no policy of its
 * own; it reads the form, asks for the move, and returns the operator to the entry.
 *
 * @since  2.0.0
 */
final readonly class AdministratorTransitionContentHandler implements RequestHandlerInterface
{
    /**
     * Wire the status controls to the service that owns the workflow rules.
     *
     * @param  ContentService  $content  Resolves the edge, authorizes it, and applies the state change.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentService $content,
    ) {
    }

    /**
     * Apply the requested status change and return the operator to the entry's editor.
     *
     * The redirect goes back to the editor rather than to the content list, because the operator is
     * normally still working on this entry and the reload is what shows the new state together with
     * the transitions now available from it.
     *
     * @param   ServerRequestInterface  $request  Administrator POST carrying `version` and `status`, `{id}` in
     *          the route.
     *
     * @return  ResponseInterface  A 303 redirect to the entry's edit screen.
     *
     * @throws  \InvalidArgumentException  When the route carries no identifier, or `version` or `status` is
     *          missing or malformed.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the edge's own capability is refused.
     * @throws  \Kumwe\App\Content\Application\ContentNotFound  When no entry matches within reach of the context.
     * @throws  \Kumwe\App\Content\Application\ContentModelNotFound  When the entry's pinned workflow version is
     *          no longer published.
     * @throws  \Kumwe\App\Workflow\Domain\InvalidWorkflowTransition  When the workflow declares no such edge.
     * @throws  \Kumwe\App\Content\Domain\VersionConflict  When another writer moved the entry on first.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $id = AdministratorRequest::routeId($request);
        $this->content->transition(
            AdministratorRequest::context($request),
            $id,
            AdministratorRequest::positiveInteger($form, 'version'),
            AdministratorRequest::required($form, 'status'),
        );

        return new RedirectResponse('/administrator/content/' . $id . '/edit', 303);
    }
}
