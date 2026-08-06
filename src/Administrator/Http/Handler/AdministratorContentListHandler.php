<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Content\Application\ContentBrowseQuery;
use Kumwe\CMS\Content\Application\ContentModelService;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Domain\ContentTypeDefinition;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorContentListHandler implements RequestHandlerInterface
{
    public function __construct(
        private ContentService $content,
        private ContentModelService $models,
        private AdministratorRenderer $renderer,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $query = $this->query($request);
        $page = $this->content->browse(AdministratorRequest::context($request), $query);
        $parameters = $query->toQueryParameters();

        return new HtmlResponse($this->renderer->render('content-list', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'entries' => array_map(static fn (ContentRecord $record): array => $record->toArray(), $page->items),
            'content_types' => array_map(
                static fn (ContentTypeDefinition $type): array => $type->toArray(),
                $this->models->contentTypes(AdministratorRequest::context($request)),
            ),
            'filters' => $parameters + [
                'q' => '',
                'status' => '',
                'type' => '',
                'scope' => 'active',
                'sort' => 'updated_desc',
                'page' => 1,
                'per_page' => 25,
            ],
            'has_previous' => $page->hasPrevious,
            'has_next' => $page->hasNext,
            'previous_url' => $page->hasPrevious ? $this->url($query->withPage($query->page - 1)) : null,
            'next_url' => $page->hasNext ? $this->url($query->withPage($query->page + 1)) : null,
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    private function query(ServerRequestInterface $request): ContentBrowseQuery
    {
        $query = $request->getQueryParams();
        return new ContentBrowseQuery(
            $this->string($query, 'q'),
            $this->string($query, 'status'),
            $this->string($query, 'type'),
            $this->string($query, 'scope', 'active'),
            $this->string($query, 'sort', 'updated_desc'),
            $this->integer($query, 'page', 1),
            $this->integer($query, 'per_page', 25),
        );
    }

    /** @param array<string, mixed> $query */
    private function string(array $query, string $key, string $default = ''): string
    {
        $value = $query[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    /** @param array<string, mixed> $query */
    private function integer(array $query, string $key, int $default): int
    {
        $value = $query[$key] ?? null;
        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int) $value : $default;
    }

    private function url(ContentBrowseQuery $query): string
    {
        $parameters = http_build_query($query->toQueryParameters(), '', '&', PHP_QUERY_RFC3986);
        return '/administrator/content' . ($parameters === '' ? '' : '?' . $parameters);
    }
}
