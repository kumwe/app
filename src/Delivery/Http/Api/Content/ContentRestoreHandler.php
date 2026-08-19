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
 * Serves `POST /api/v1/content/{id}/restore`, the undo for an entry that was moved to the trash.
 *
 * Trashing only marks a row, so bringing an entry back is a lifecycle move of its own rather than a
 * re-creation, and it gets its own route guarded by `content.restore` instead of riding on `PATCH`.
 * The lookup here deliberately includes trashed records — a live-only read could not see the very
 * entry being restored — and the `If-Match` precondition is settled against the version that record
 * carries while trashed.
 *
 * @since  2.0.0
 */
final readonly class ContentRestoreHandler implements RequestHandlerInterface
{
    /**
     * Wire the route to the service that performs the restore and the responder that renders the answer.
     *
     * @param  ContentService       $content    Application service lifting the trash marker off the entry.
     * @param  ContentApiResponder  $responder  Renders stored records and maps failures onto problem documents.
     *
     * @since  2.0.0
     */
    public function __construct(private ContentService $content, private ContentApiResponder $responder)
    {
    }

    /**
     * Lift the trash marker off the addressed entry and answer with the record as it now stands.
     *
     * The precondition is settled before the restore is attempted, so a stale `If-Match` fails with 412
     * either way. An entry that turns out never to have been trashed is then returned untouched and
     * without consuming a version, while a real restore advances it — which is why a second request
     * quoting the tag from the first is refused rather than repeated. Failures are handed to the
     * responder, which answers the ones it recognises and rethrows the rest.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` route attribute names the entry to
     *          restore and whose `If-Match` header quotes the version the caller loaded.
     *
     * @return  ResponseInterface  The live record as JSON tagged with its version, or a problem document
     *          saying why the restore was refused.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $id = ContentApiRequest::routeId($request);
            $context = ApiExecutionContext::fromRequest($request);
            $stored = $this->content->get($context, $id, true);

            return $this->responder->record($this->content->restore(
                $context,
                $id,
                ContentApiRequest::expectedVersion($request, $stored->entry->version()),
            ));
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }
}
