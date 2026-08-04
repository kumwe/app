<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Content;

use Kumwe\CMS\Content\Application\ContentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class ContentRestoreHandler implements RequestHandlerInterface
{
    public function __construct(private ContentService $content, private ContentApiResponder $responder)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $id = ContentApiRequest::routeId($request);
            $stored = $this->content->get($id, true);

            return $this->responder->record($this->content->restore(
                ContentApiRequest::principal($request)->subject(),
                $id,
                ContentApiRequest::expectedVersion($request, $stored->entry->version()),
            ));
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }
}
