<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Content;

use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class ContentCollectionHandler implements RequestHandlerInterface
{
    public function __construct(private ContentService $content, private ContentApiResponder $responder)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'GET') {
            $includeDeleted = ($request->getQueryParams()['include_deleted'] ?? null) === '1';

            return new JsonResponse([
                'items' => array_map(
                    static fn (ContentRecord $record): array => $record->toArray(),
                    $this->content->list(100, $includeDeleted),
                ),
            ], 200, ['Cache-Control' => 'no-store']);
        }

        try {
            $body = ContentApiRequest::json($request);
            $record = $this->content->create(
                ContentApiRequest::principal($request)->subject(),
                ContentApiRequest::requiredString($body, 'title'),
                ContentApiRequest::requiredString($body, 'slug'),
                ContentApiRequest::data($body),
                ContentApiRequest::publicationWindow($body),
            );

            return $this->responder->record($record, 201, [
                'Location' => '/api/v1/content/' . $record->entry->id(),
            ]);
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }
}
