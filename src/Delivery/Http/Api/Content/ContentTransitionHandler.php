<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Content;

use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Domain\ContentStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class ContentTransitionHandler implements RequestHandlerInterface
{
    public function __construct(private ContentService $content, private ContentApiResponder $responder)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $id = ContentApiRequest::routeId($request);
            $stored = $this->content->get($id);
            $expectedVersion = ContentApiRequest::expectedVersion($request, $stored->entry->version());
            $body = ContentApiRequest::json($request);

            return $this->responder->record($this->content->transition(
                ContentApiRequest::principal($request)->subject(),
                $id,
                $expectedVersion,
                ContentStatus::from(ContentApiRequest::requiredString($body, 'status')),
            ));
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }
}
