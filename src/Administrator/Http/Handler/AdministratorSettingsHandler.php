<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Site\Application\SiteSettings;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorSettingsHandler implements RequestHandlerInterface
{
    public function __construct(
        private SiteSettings $settings,
        private AdministratorRenderer $renderer,
        private ?ContentService $content = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);

        if (strtoupper($request->getMethod()) === 'POST') {
            $form = AdministratorRequest::form($request);
            $this->settings->updateAll(AdministratorRequest::context($request), [
                'site_name' => AdministratorRequest::required($form, 'site_name'),
                'homepage_content_id' => AdministratorRequest::required($form, 'homepage_content_id'),
                'default_locale' => AdministratorRequest::required($form, 'default_locale'),
                'timezone' => AdministratorRequest::required($form, 'timezone'),
                'search_indexing_enabled' => ($form['search_indexing_enabled'] ?? '') === '1',
            ]);

            return new RedirectResponse('/administrator/settings?saved=1', 303);
        }

        $context = AdministratorRequest::context($request);
        $content = $this->content;
        $pages = $content === null ? [] : array_map(
            static fn (ContentRecord $record): array => $record->toArray(),
            array_values(array_filter(
                $content->list($context, 500),
                fn (ContentRecord $record): bool => $record->contentTypeId === ContentService::CORE_PAGE_TYPE_ID
                    && $content->publishedById($record->entry->id(), $context->site()) !== null,
            )),
        );

        return new HtmlResponse($this->renderer->render('settings', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'settings' => $this->settings->managed($context),
            'pages' => $pages,
            'saved' => ($request->getQueryParams()['saved'] ?? null) === '1',
        ]), 200, ['Cache-Control' => 'no-store']);
    }
}
