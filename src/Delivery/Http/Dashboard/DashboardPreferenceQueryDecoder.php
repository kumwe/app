<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Dashboard;

use InvalidArgumentException;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceQuery;
use Kumwe\App\InterfaceStandard\SurfaceArea;

/**
 * Decodes independent dashboard group/workflow GET state and builds fixed same-area continuation URLs.
 *
 * No submitted return URL is accepted. Malformed hand-edited page or search values fall back to their
 * neutral defaults, and every generated link contains only the validated page and normalized search.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceQueryDecoder
{
    /**
     * Decode a defensive browser query into the typed application value.
     *
     * @param   array<array-key, mixed>  $query  Untrusted GET parameters.
     *
     * @return  DashboardPreferenceQuery  Validated independent group and workflow browser state.
     *
     * @since   2.0.0
     */
    public function decode(array $query): DashboardPreferenceQuery
    {
        return new DashboardPreferenceQuery(
            self::page($query['dashboard_group_page'] ?? null),
            self::search($query['dashboard_group_search'] ?? null, DashboardPreferenceQuery::MAXIMUM_SEARCH_LENGTH),
            self::page($query['dashboard_workflow_page'] ?? null),
            self::search(
                $query['dashboard_workflow_search'] ?? null,
                DashboardPreferenceQuery::MAXIMUM_WORKFLOW_SEARCH_LENGTH,
            ),
        );
    }

    /**
     * Return the fixed dashboard GET action for one delivery area.
     *
     * @param   SurfaceArea  $area  Administrator or portal area.
     *
     * @return  string  Fixed root-relative browse path.
     *
     * @throws  InvalidArgumentException  When the area has no dashboard preference delivery.
     *
     * @since   2.0.0
     */
    public function browseAction(SurfaceArea $area): string
    {
        return match ($area) {
            SurfaceArea::Administrator => '/administrator',
            SurfaceArea::Portal => '/portal',
            default => throw new InvalidArgumentException('The area has no dashboard preference route.'),
        };
    }

    /**
     * Return the fixed same-area preference POST action with validated continuation state.
     *
     * @param   SurfaceArea               $area   Administrator or portal area.
     * @param   DashboardPreferenceQuery  $query  Validated page and search to preserve.
     *
     * @return  string  Fixed root-relative mutation path and optional validated query string.
     *
     * @throws  InvalidArgumentException  When the area has no dashboard preference delivery.
     *
     * @since   2.0.0
     */
    public function mutationAction(SurfaceArea $area, DashboardPreferenceQuery $query): string
    {
        $path = match ($area) {
            SurfaceArea::Administrator => '/administrator/dashboard/preferences',
            SurfaceArea::Portal => '/portal/dashboard/preferences',
            default => throw new InvalidArgumentException('The area has no dashboard preference route.'),
        };

        return self::append($path, $query);
    }

    /**
     * Build one fixed same-area page link with the customization disclosure targeted.
     *
     * @param   SurfaceArea               $area   Administrator or portal area.
     * @param   DashboardPreferenceQuery  $query  Validated target page and search.
     *
     * @return  string  Fixed root-relative dashboard link.
     *
     * @since   2.0.0
     */
    public function browseHref(SurfaceArea $area, DashboardPreferenceQuery $query): string
    {
        return self::append($this->browseAction($area), $query) . '#dashboard-customization';
    }

    /**
     * Build the fixed successful mutation redirect while preserving validated catalogue state.
     *
     * @param   SurfaceArea               $area   Administrator or portal area.
     * @param   DashboardPreferenceQuery  $query  Validated page and search to preserve.
     *
     * @return  string  Fixed same-area success redirect.
     *
     * @since   2.0.0
     */
    public function successHref(SurfaceArea $area, DashboardPreferenceQuery $query): string
    {
        return self::append($this->browseAction($area), $query, ['dashboard-saved' => '1'])
            . '#dashboard-customization';
    }

    /**
     * Build one fixed failed mutation redirect from the closed failure vocabulary.
     *
     * @param   SurfaceArea               $area   Administrator or portal area.
     * @param   DashboardPreferenceQuery  $query  Validated page and search to preserve.
     * @param   string                    $error  Closed `conflict` or `invalid` code.
     *
     * @return  string  Fixed same-area failure redirect.
     *
     * @throws  InvalidArgumentException  When an unknown error code is requested.
     *
     * @since   2.0.0
     */
    public function errorHref(SurfaceArea $area, DashboardPreferenceQuery $query, string $error): string
    {
        if (!in_array($error, ['conflict', 'invalid'], true)) {
            throw new InvalidArgumentException('The dashboard preference redirect error is invalid.');
        }

        return self::append($this->browseAction($area), $query, ['dashboard-error' => $error])
            . '#dashboard-customization';
    }

    /**
     * Parse a canonical positive decimal without allowing overflow or ambiguous encodings.
     *
     * @param   mixed  $value  Candidate page field.
     *
     * @return  int  Positive page or the neutral first page.
     *
     * @since   2.0.0
     */
    private static function page(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return 1;
        }
        $page = (int) $value;

        return $page > 0
            && $page <= DashboardPreferenceQuery::MAXIMUM_PAGE
            && (string) $page === $value
            ? $page
            : 1;
    }

    /**
     * Normalize one field-bounded search without accepting nested values.
     *
     * @param   mixed  $value    Candidate search field.
     * @param   int    $maximum  Field-specific character ceiling.
     *
     * @return  string  Normalized search or the neutral empty search.
     *
     * @since   2.0.0
     */
    private static function search(mixed $value, int $maximum): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return '';
        }
        // Collapse Unicode whitespace before trimming: trim() strips ASCII only, so a leading or
        // trailing non-ASCII space would otherwise survive as an edge ASCII space the query refuses.
        $normalized = preg_replace('/\s+/u', ' ', $value);
        if (!is_string($normalized)) {
            return '';
        }
        $normalized = trim($normalized);
        if (
            mb_strlen($normalized, 'UTF-8') > $maximum
            || preg_match('/[\x00-\x1f\x7f]/u', $normalized) === 1
        ) {
            return '';
        }

        return $normalized;
    }

    /**
     * Append only normalized dashboard state and a caller-owned closed result flag.
     *
     * @param   string                    $path   Fixed same-area path selected by this decoder.
     * @param   DashboardPreferenceQuery  $query  Validated group and workflow browser state.
     * @param   array<string, string>     $extra  Closed server-selected result flag.
     *
     * @return  string  Root-relative path with deterministic RFC 3986 query encoding.
     *
     * @since   2.0.0
     */
    private static function append(string $path, DashboardPreferenceQuery $query, array $extra = []): string
    {
        $parameters = [];
        if ($query->search !== '') {
            $parameters['dashboard_group_search'] = $query->search;
        }
        if ($query->page > 1) {
            $parameters['dashboard_group_page'] = (string) $query->page;
        }
        if ($query->workflowSearch !== '') {
            $parameters['dashboard_workflow_search'] = $query->workflowSearch;
        }
        if ($query->workflowPage > 1) {
            $parameters['dashboard_workflow_page'] = (string) $query->workflowPage;
        }
        $parameters = [...$parameters, ...$extra];

        return $parameters === []
            ? $path
            : $path . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
