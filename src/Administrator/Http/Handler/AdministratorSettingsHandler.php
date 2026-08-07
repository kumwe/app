<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Administrator\Presentation\SitePresentationFormMapper;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Media\Application\MediaAsset;
use Kumwe\CMS\Media\Application\MediaService;
use Kumwe\CMS\Navigation\Application\MenuRecord;
use Kumwe\CMS\Navigation\Application\NavigationService;
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
        private ?SitePresentationFormMapper $presentation = null,
        private ?MediaService $media = null,
        private ?NavigationService $navigation = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'POST') {
            $form = AdministratorRequest::form($request);
            try {
                $this->settings->updateAll(AdministratorRequest::context($request), [
                    'site_name' => AdministratorRequest::required($form, 'site_name'),
                    'homepage_content_id' => AdministratorRequest::required($form, 'homepage_content_id'),
                    'default_locale' => AdministratorRequest::required($form, 'default_locale'),
                    'timezone' => AdministratorRequest::required($form, 'timezone'),
                    'search_indexing_enabled' => ($form['search_indexing_enabled'] ?? '') === '1',
                    'presentation' => ($this->presentation ?? new SitePresentationFormMapper())->map($form),
                ]);
            } catch (InvalidArgumentException $exception) {
                return $this->render($request, 422, $exception->getMessage());
            }

            return new RedirectResponse('/administrator/settings?saved=1', 303);
        }

        return $this->render($request);
    }

    private function render(
        ServerRequestInterface $request,
        int $status = 200,
        ?string $error = null,
    ): ResponseInterface {
        $session = AdministratorRequest::session($request);

        $context = AdministratorRequest::context($request);
        $capabilities = AdministratorRequest::capabilityMap($request);
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
            'capabilities' => $capabilities,
            'settings' => $this->settings->managed($context),
            'pages' => $pages,
            'menus' => !isset($capabilities['navigation.manage']) || $this->navigation === null ? [] : array_map(
                static fn (MenuRecord $menu): array => $menu->toArray(),
                $this->navigation->menus($context),
            ),
            'media_assets' => !isset($capabilities['content.read']) || $this->media === null ? [] : array_map(
                static fn (MediaAsset $asset): array => $asset->toArray(),
                $this->media->browse($context, kind: 'image', perPage: 48)->items,
            ),
            'saved' => ($request->getQueryParams()['saved'] ?? null) === '1',
            'error' => $error,
        ]), $status, ['Cache-Control' => 'no-store']);
    }
}
