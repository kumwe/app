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

/**
 * Serves the administrator landing screen: the site's content at a glance, plus the ways to add to it.
 *
 * The dashboard answers three questions in one render — how much content exists and in what state, what
 * changed most recently, and what an operator can create next. Counts are computed from the records the
 * actor may actually read rather than from a `COUNT(*)`, so the totals never describe entries the screen
 * would refuse to show. The listing is deliberately capped and truncated to a handful of rows: this is a
 * summary, and `AdministratorContentListHandler` is where the full, filterable browser lives. The screen
 * itself asks only for `administrator.access`: an actor without `content.read` still lands here and gets
 * the shell with their own navigation, while the content summaries degrade to a permission-reduced state
 * without a single denied service call being made on their behalf.
 *
 * @since  2.0.0
 */
final readonly class AdministratorDashboardHandler implements RequestHandlerInterface
{
    /**
     * Wire the dashboard to the services supplying its counters, vocabulary and public links.
     *
     * @param  ContentService         $content      Supplies the readable records every counter is derived from.
     * @param  ContentModelService    $models       Supplies the content types offered as create shortcuts.
     * @param  AdministratorRenderer  $renderer     Renders the `dashboard` template.
     * @param  ?PublicPageLocator     $publicPages  Resolves each row's public URL; null renders none.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentService $content,
        private ContentModelService $models,
        private AdministratorRenderer $renderer,
        private ?PublicPageLocator $publicPages = null,
    ) {
    }

    /**
     * Summarise the site's content and render the dashboard for it.
     *
     * Up to 500 readable records are walked once, most recently updated first, so a larger site is
     * summarised by that leading slice. A trashed record is counted as trashed and nothing else, and a
     * live record in a state the screen has no counter for — anything a site-defined workflow adds —
     * raises only the total. The published percentage is taken over the live entries rather than the
     * total, with the divisor floored at one so an empty site renders zero per cent rather than
     * dividing by zero. An actor whose capability map carries no `content.read` is served the same
     * screen without the content and content-type reads ever being attempted: the summaries render
     * empty and the template shows its permission-reduced state instead of a denial.
     *
     * @param   ServerRequestInterface  $request  Administrator request, already authenticated and authorized.
     *
     * @return  ResponseInterface  The rendered dashboard, marked `no-store` because it carries a CSRF token.
     *
     * @throws  \InvalidArgumentException  When the route was mounted without administrator session middleware.
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When `content.read` is refused.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);

        $context = AdministratorRequest::context($request);
        $capabilities = AdministratorRequest::capabilityMap($request);
        $readsContent = isset($capabilities['content.read']);
        $records = $readsContent ? $this->content->list($context, 500, true) : [];
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
            'capabilities' => $capabilities,
            'counts' => $counts,
            'published_percent' => min(100, (int) round(($counts['published'] / $active) * 100)),
            'entries' => array_map(
                fn (ContentRecord $record): array => $this->present($record),
                array_slice($records, 0, 6),
            ),
            'content_types' => $readsContent
                ? array_map(
                    static fn (ContentTypeDefinition $type): array => $type->toArray(),
                    $this->models->contentTypes($context),
                )
                : [],
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Flatten one record into the row shape the dashboard template iterates over.
     *
     * @param   ContentRecord  $record  Entry to present as a recent-activity row.
     *
     * @return  array<string, mixed>  The record's own fields plus `public_url`, null when not reachable.
     *
     * @since   2.0.0
     */
    private function present(ContentRecord $record): array
    {
        return $record->toArray() + ['public_url' => $this->publicPages?->publicPathFor($record)];
    }
}
