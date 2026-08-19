<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Content;

use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Serves the single content entry addressed by `/api/v1/content/{id}`, across all three of its verbs.
 *
 * Reading, revising and trashing one entry open the same way — resolve the route identifier, establish
 * the execution context, load the record — so one handler owns all three rather than three handlers
 * repeating it. Only the mutating verbs consult the `If-Match` precondition, and they judge it against
 * the version of the record just loaded, which is what makes a stale editor form fail with 412 instead
 * of quietly overwriting a newer revision.
 *
 * @since  2.0.0
 */
final readonly class ContentItemHandler implements RequestHandlerInterface
{
    /**
     * Wire the route to the service that performs the work and the responder that renders the answer.
     *
     * @param  ContentService       $content    Application service performing the read, revision and trash.
     * @param  ContentApiResponder  $responder  Renders stored records and maps failures onto problem documents.
     *
     * @since  2.0.0
     */
    public function __construct(private ContentService $content, private ContentApiResponder $responder)
    {
    }

    /**
     * Read, revise or trash the addressed entry according to the request method.
     *
     * `GET` looks the entry up with trashed records included, so a client can still read what it has
     * just deleted, while `PATCH` and `DELETE` see live entries only. `PATCH` merges rather than
     * replaces: `title`, `slug` and `data` fall back to the stored values when the body omits them, and
     * a body carrying neither `publish_at` nor `unpublish_at` leaves the publication window untouched.
     * Anything thrown on the way is handed to the responder, which answers the failures it recognises
     * and rethrows the rest.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` route attribute names the entry and
     *          whose method selects the operation.
     *
     * @return  ResponseInterface  The resulting record as JSON tagged with the version it now carries, or
     *          a problem document saying why the operation was refused.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $id = ContentApiRequest::routeId($request);
            $method = strtoupper($request->getMethod());
            $context = ApiExecutionContext::fromRequest($request);
            $stored = $this->content->get($context, $id, $method === 'GET');

            if ($method === 'GET') {
                return $this->responder->record($stored);
            }

            $expectedVersion = ContentApiRequest::expectedVersion($request, $stored->entry->version());

            if ($method === 'DELETE') {
                return $this->responder->record($this->content->trash(
                    $context,
                    $id,
                    $expectedVersion,
                ));
            }

            $body = ContentApiRequest::json($request);

            return $this->responder->record($this->content->update(
                $context,
                $id,
                $expectedVersion,
                array_key_exists('title', $body)
                    ? ContentApiRequest::requiredString($body, 'title')
                    : $stored->entry->title(),
                array_key_exists('slug', $body)
                    ? ContentApiRequest::requiredString($body, 'slug')
                    : $stored->entry->slug(),
                array_key_exists('data', $body) ? ContentApiRequest::data($body) : $stored->entry->data(),
                ContentApiRequest::publicationWindow($body),
            ));
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }
}
