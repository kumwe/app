<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Content;

use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Serves the content collection route: listing the entries a caller may read, and creating new ones.
 *
 * One URL carries both operations, so both live in one handler: `GET` lists, and anything else is
 * treated as a create, which leaves method filtering to the router that mounts this handler. The
 * handler is a pure adapter — it reads the request through `ContentApiRequest`, calls
 * `ContentService`, and hands the record or the failure to `ContentApiResponder`. Ordering,
 * authorization, slug rules and schema validation all belong to the service.
 *
 * @since  2.0.0
 */
final readonly class ContentCollectionHandler implements RequestHandlerInterface
{
    /**
     * Wire the handler to the service it fronts and the responder that shapes what it writes back.
     *
     * @param  ContentService       $content    Application service every listing and create is dispatched to.
     * @param  ContentApiResponder  $responder  Renders records and maps failures onto problem documents.
     *
     * @since  2.0.0
     */
    public function __construct(private ContentService $content, private ContentApiResponder $responder)
    {
    }

    /**
     * List the readable entries of the caller's site, or create an entry from the request body.
     *
     * Listing is capped at a hundred readable records and includes trashed entries only when the
     * query string carries `include_deleted=1`; any other value leaves them out. Only the create path
     * runs inside the responder's failure mapping, so a `GET` that arrives without an authenticated
     * execution context escapes as a fault rather than as a problem document. A created entry is
     * answered 201 with a `Location` header pointing at its item route.
     *
     * @param   ServerRequestInterface  $request  API request for the content collection route.
     *
     * @return  ResponseInterface  The listing under an `items` key, the created record, or the problem
     *          document the responder produced.
     *
     * @throws  \InvalidArgumentException  When a `GET` carries no authenticated execution context, or
     *          its principal does not match that context.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'GET') {
            $includeDeleted = ($request->getQueryParams()['include_deleted'] ?? null) === '1';

            return new JsonResponse([
                'items' => array_map(
                    static fn (ContentRecord $record): array => $record->toArray(),
                    $this->content->list(ApiExecutionContext::fromRequest($request), 100, $includeDeleted),
                ),
            ], 200, ['Cache-Control' => 'no-store']);
        }

        try {
            $body = ContentApiRequest::json($request);
            $record = $this->content->create(
                ApiExecutionContext::fromRequest($request),
                ContentApiRequest::requiredString($body, 'title'),
                ContentApiRequest::requiredString($body, 'slug'),
                ContentApiRequest::data($body),
                ContentApiRequest::publicationWindow($body),
                ContentApiRequest::optionalString($body, 'content_type', ContentService::CORE_PAGE_TYPE_ID),
            );

            return $this->responder->record($record, 201, [
                'Location' => '/api/v1/content/' . $record->entry->id(),
            ]);
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }
}
