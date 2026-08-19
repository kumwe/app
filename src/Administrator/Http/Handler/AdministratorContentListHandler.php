<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Content\Application\ContentBrowseQuery;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Site\Application\PublicPageLocator;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the administrator content browser: one filtered, sorted, paged screen of entries.
 *
 * The browser keeps its whole state in the query string, so a filtered listing can be bookmarked and
 * shared. This handler's job is to turn those loose parameters into a validated `ContentBrowseQuery`,
 * hand that to `ContentService`, and rebuild the previous and next links from the same object — which
 * is what guarantees a page link reproduces exactly the listing the operator is looking at. Paging
 * flags come from the service rather than a total count, because readability is decided per record
 * and a count taken in SQL would not match what the operator may actually see.
 *
 * @since  2.0.0
 */
final readonly class AdministratorContentListHandler implements RequestHandlerInterface
{
    /**
     * Wire the browser to the services supplying entries, filter vocabulary and public links.
     *
     * @param  ContentService         $content      Answers the browse query with the readable page.
     * @param  ContentModelService    $models       Supplies the content types offered as a filter.
     * @param  AdministratorRenderer  $renderer     Renders the `content-list` template.
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
     * Render one page of the content browser for the query string as submitted.
     *
     * The `filters` payload is the parsed query laid over the screen's defaults, so a control always
     * has a current value to render even when the URL omitted it. A neighbouring page URL is null
     * rather than a dead link when that page does not exist.
     *
     * @param   ServerRequestInterface  $request  Administrator request whose query string carries the state.
     *
     * @return  ResponseInterface  The rendered listing, marked `no-store` because it carries a CSRF token.
     *
     * @throws  \InvalidArgumentException  When a filter, sort or page value in the query string is refused.
     * @throws  \RuntimeException  When the configured content repository cannot answer browse queries.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $query = $this->query($request);
        $page = $this->content->browse(AdministratorRequest::context($request), $query);
        $parameters = $query->toQueryParameters();

        return new HtmlResponse($this->renderer->render('content-list', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'entries' => array_map(fn (ContentRecord $record): array => $this->present($record), $page->items),
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

    /**
     * Build the validated browse query from the request's query-string parameters.
     *
     * Reading through `string()` and `integer()` first means the value object only ever sees trimmed
     * strings and positive integers, so its own checks are about vocabulary and range rather than
     * type. Non-string keys are dropped, since a query string cannot name a filter numerically.
     *
     * @param   ServerRequestInterface  $request  Request whose query string carries the browser state.
     *
     * @return  ContentBrowseQuery  The filters, ordering and page the listing is built from.
     *
     * @throws  \InvalidArgumentException  When a value is off the accepted vocabulary or out of range.
     *
     * @since   2.0.0
     */
    private function query(ServerRequestInterface $request): ContentBrowseQuery
    {
        $query = [];
        foreach ($request->getQueryParams() as $key => $value) {
            if (is_string($key)) {
                $query[$key] = $value;
            }
        }
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

    /**
     * Read one query-string parameter as a trimmed string, falling back when it is unusable.
     *
     * An array-valued parameter — what `?q[]=a` produces — falls back rather than failing, because
     * these values arrive from links and bookmarks rather than a form just filled in.
     *
     * @param   array<string, mixed>  $query    Query parameters keyed by their public name.
     * @param   string                $key      Public parameter name, such as `q` or `scope`.
     * @param   string                $default  Value to use when the parameter is absent or not a string.
     *
     * @return  string  The trimmed value, or the default.
     *
     * @since   2.0.0
     */
    private function string(array $query, string $key, string $default = ''): string
    {
        $value = $query[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    /**
     * Read one query-string parameter as a positive integer, rejecting anything else.
     *
     * A malformed page or page size falls back to the default instead of failing the request, so a
     * hand-edited or truncated URL still renders a listing.
     *
     * @param   array<string, mixed>  $query    Query parameters keyed by their public name.
     * @param   string                $key      Public parameter name, such as `page` or `per_page`.
     * @param   int                   $default  Value to use when the parameter is absent or malformed.
     *
     * @return  int  The parsed value, or the default.
     *
     * @since   2.0.0
     */
    private function integer(array $query, string $key, int $default): int
    {
        $value = $query[$key] ?? null;
        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int) $value : $default;
    }

    /**
     * Build the browser URL that reproduces a query, for the previous and next page links.
     *
     * @param   ContentBrowseQuery  $query  Query the link should land on.
     *
     * @return  string  Absolute path, gaining a query string only where the query differs from defaults.
     *
     * @since   2.0.0
     */
    private function url(ContentBrowseQuery $query): string
    {
        $parameters = http_build_query($query->toQueryParameters(), '', '&', PHP_QUERY_RFC3986);
        return '/administrator/content' . ($parameters === '' ? '' : '?' . $parameters);
    }

    /**
     * Flatten one record into the row shape the listing template iterates over.
     *
     * @param   ContentRecord  $record  Entry to present as a row.
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
