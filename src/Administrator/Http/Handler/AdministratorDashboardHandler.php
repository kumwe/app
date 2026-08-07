<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Application\ContentModelService;
use Kumwe\CMS\Content\Domain\ContentTypeDefinition;
use Kumwe\CMS\Site\Application\PublicPageLocator;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorDashboardHandler implements RequestHandlerInterface
{
    public function __construct(
        private ContentService $content,
        private ContentModelService $models,
        private AdministratorRenderer $renderer,
        private ?PublicPageLocator $publicPages = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);

        $context = AdministratorRequest::context($request);
        $records = $this->content->list($context, 500, true);
        $counts = ['total' => 0, 'published' => 0, 'draft' => 0, 'review' => 0, 'trashed' => 0];
        foreach ($records as $record) {
            $counts['total']++;
            if ($record->deletedAt !== null) {
                $counts['trashed']++;
                continue;
            }
            $status = $record->entry->statusKey();
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $active = max(1, $counts['total'] - $counts['trashed']);

        return new HtmlResponse($this->renderer->render('dashboard', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'counts' => $counts,
            'published_percent' => min(100, (int) round(($counts['published'] / $active) * 100)),
            'entries' => array_map(
                fn (ContentRecord $record): array => $this->present($record),
                array_slice($records, 0, 6),
            ),
            'content_types' => array_map(
                static fn (ContentTypeDefinition $type): array => $type->toArray(),
                $this->models->contentTypes($context),
            ),
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /** @return array<string, mixed> */
    private function present(ContentRecord $record): array
    {
        return $record->toArray() + ['public_url' => $this->publicPages?->publicPathFor($record)];
    }
}
