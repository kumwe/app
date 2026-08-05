<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use JsonException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Application\ContentModelService;
use Kumwe\CMS\Content\Domain\ContentTypeDefinition;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorContentEditorHandler implements RequestHandlerInterface
{
    public function __construct(
        private ContentService $content,
        private ContentModelService $models,
        private AdministratorRenderer $renderer,
    ) {
    }

    /** @throws JsonException */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $id = $request->getAttribute('id');
        $entry = null;

        if (is_string($id) && $id !== '') {
            $entry = $this->content->get(AdministratorRequest::context($request), $id, true)->toArray();
            $entry['data_json'] = json_encode(
                $entry['data'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        }

        $context = AdministratorRequest::context($request);
        $types = array_map(
            static fn (ContentTypeDefinition $type): array => $type->toArray(),
            $this->models->contentTypes($context),
        );
        $workflow = null;
        if (is_array($entry)) {
            $workflow = $this->models->workflow(
                $context,
                (string) $entry['workflow_id'],
                (int) $entry['workflow_version'],
            )->toArray();
        }

        return new HtmlResponse($this->renderer->render('content-form', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'entry' => $entry,
            'content_types' => $types,
            'workflow' => $workflow,
        ]), 200, ['Cache-Control' => 'no-store']);
    }
}
