<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Content;

use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Serves `POST /api/v1/content/{id}/transition`, where the JSON API moves an entry through its workflow.
 *
 * Rewriting an entry and moving it between workflow states are deliberately separate operations, so a
 * `PATCH` can never publish and this route never touches the body an author wrote. The route itself is
 * mounted behind `content.read`; the capability that actually gates the move is the one the entry's
 * pinned workflow declares on that edge, which `ContentService` resolves and enforces.
 *
 * @since  2.0.0
 */
final readonly class ContentTransitionHandler implements RequestHandlerInterface
{
    /**
     * Wire the route to the service that performs the move and the responder that renders the answer.
     *
     * @param  ContentService       $content    Application service enforcing the workflow edge and its capability.
     * @param  ContentApiResponder  $responder  Renders stored records and maps failures onto problem documents.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentService $content,
        private ContentApiResponder $responder,
    ) {
    }

    /**
     * Move the addressed entry to the workflow state the request body names.
     *
     * The target arrives as the `status` field and is passed on as a plain state key, so a built-in
     * status and a state declared by a site's own workflow travel the same route. The entry is loaded
     * live only, which puts trashed entries out of reach, and the `If-Match` precondition is judged
     * against the version that lookup returned. Failures are handed to the responder, which answers the
     * ones it recognises and rethrows the rest.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` route attribute names the entry and
     *          whose body carries the target `status`.
     *
     * @return  ResponseInterface  The moved record as JSON tagged with its new version, or a problem
     *          document saying why the move was refused.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $id = ContentApiRequest::routeId($request);
            $context = ApiExecutionContext::fromRequest($request);
            $stored = $this->content->get($context, $id);
            $expectedVersion = ContentApiRequest::expectedVersion($request, $stored->entry->version());
            $body = ContentApiRequest::json($request);
            $target = ContentApiRequest::requiredString($body, 'status');

            return $this->responder->record($this->content->transition(
                $context,
                $id,
                $expectedVersion,
                $target,
            ));
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }
}
