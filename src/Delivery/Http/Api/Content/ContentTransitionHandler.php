<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Content;

use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Workflow\Application\ContentTransitionAuthorizer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class ContentTransitionHandler implements RequestHandlerInterface
{
    public function __construct(
        private ContentService $content,
        private ContentApiResponder $responder,
        private ContentTransitionAuthorizer $authorization,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $id = ContentApiRequest::routeId($request);
            $context = ApiExecutionContext::fromRequest($request);
            $stored = $this->content->get($context, $id);
            $expectedVersion = ContentApiRequest::expectedVersion($request, $stored->entry->version());
            $body = ContentApiRequest::json($request);
            $target = ContentStatus::from(ContentApiRequest::requiredString($body, 'status'));
            $principal = ContentApiRequest::principal($request);
            $this->authorization->assertAllowed($principal, $stored->entry->status(), $target);

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
