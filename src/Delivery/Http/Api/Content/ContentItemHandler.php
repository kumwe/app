<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Content;

use Kumwe\CMS\Content\Application\ContentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class ContentItemHandler implements RequestHandlerInterface
{
    public function __construct(private ContentService $content, private ContentApiResponder $responder)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $id = ContentApiRequest::routeId($request);
            $method = strtoupper($request->getMethod());
            $stored = $this->content->get($id, $method === 'GET');

            if ($method === 'GET') {
                return $this->responder->record($stored);
            }

            $expectedVersion = ContentApiRequest::expectedVersion($request, $stored->entry->version());

            if ($method === 'DELETE') {
                return $this->responder->record($this->content->trash(
                    ContentApiRequest::principal($request)->subject(),
                    $id,
                    $expectedVersion,
                ));
            }

            $body = ContentApiRequest::json($request);

            return $this->responder->record($this->content->update(
                ContentApiRequest::principal($request)->subject(),
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
