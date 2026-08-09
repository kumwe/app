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

/**
 * Serves the administrator site-settings screen: one form that renders and rewrites the whole document.
 *
 * Settings are saved as a single document rather than field by field, so a value the domain refuses
 * leaves nothing half applied — the handler catches that rejection and re-renders the same form with
 * 422 and the message instead of redirecting, which keeps the operator on the screen they were
 * filling in. The choices the form's pickers offer come from optional collaborators, and an absent
 * one simply leaves its picker empty, so a minimal wiring still serves the core settings.
 *
 * @since  2.0.0
 */
final readonly class AdministratorSettingsHandler implements RequestHandlerInterface
{
    /**
     * Wire the settings form to the settings document and to the sources its pickers offer.
     *
     * @param  SiteSettings                 $settings      Reads the managed settings document and writes it back.
     * @param  AdministratorRenderer        $renderer      Renders the `settings` template.
     * @param  ?ContentService              $content       Supplies the pages offered as the homepage; null offers
     *         none.
     * @param  ?SitePresentationFormMapper  $presentation  Folds the theme fields into the presentation document; a
     *         default instance is built when null.
     * @param  ?MediaService                $media         Supplies the images the picker browses; null offers none.
     * @param  ?NavigationService           $navigation    Supplies the menus offered as the primary menu; null
     *         offers none.
     *
     * @since  2.0.0
     */
    public function __construct(
        private SiteSettings $settings,
        private AdministratorRenderer $renderer,
        private ?ContentService $content = null,
        private ?SitePresentationFormMapper $presentation = null,
        private ?MediaService $media = null,
        private ?NavigationService $navigation = null,
    ) {
    }

    /**
     * Render the settings form, or save the submitted document and redirect back to it.
     *
     * A refused value re-renders the form at 422 carrying the message, so the operator keeps the
     * screen and learns what failed; a clean save answers 303 to `?saved=1` instead, so a refresh
     * cannot repost the document. Only `InvalidArgumentException` is caught, so a refused authorization
     * still surfaces as an error rather than being presented to the operator as a form mistake.
     *
     * @param   ServerRequestInterface  $request  Administrator request; the method decides render or save.
     *
     * @return  ResponseInterface  The rendered form, the same form at 422 when a value was refused, or a 303
     *          redirect after a successful save.
     *
     * @throws  \InvalidArgumentException  When the request carries no administrator session or execution context.
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When `settings.manage` is refused.
     *
     * @since   2.0.0
     */
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

    /**
     * Build the settings screen with every picker filled from the collaborators that are wired.
     *
     * The homepage picker offers only pages that are publicly reachable at this instant, because a
     * homepage pointing at a draft would render nothing to a visitor. The menu and media pickers are
     * left empty when the actor lacks `navigation.manage` or `content.read`, so the form never offers
     * choices the actor is not allowed to read.
     *
     * @param   ServerRequestInterface  $request  Request carrying the administrator session and execution context.
     * @param   int                     $status   Status to answer with; 422 when re-rendering a refused save.
     * @param   ?string                 $error    Message to show above the form, or null on a clean render.
     *
     * @return  ResponseInterface  The rendered form, marked `no-store` because it carries the CSRF token.
     *
     * @throws  \InvalidArgumentException  When the request carries no administrator session or execution context.
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When `settings.manage` is refused.
     *
     * @since   2.0.0
     */
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
