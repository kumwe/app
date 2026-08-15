<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application\Dashboard;

use InvalidArgumentException;
use Kumwe\CMS\Application\Presentation\Dashboard\DashboardPreferenceMutation;
use Kumwe\CMS\Application\Presentation\Dashboard\DashboardPreferenceQuery;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use Kumwe\CMS\InterfaceStandard\SurfaceId;

/**
 * Request-local projection of the ordinary filtered navigation catalogue for dashboard selection.
 *
 * This is not a contribution registry or persistence store. It validates and indexes the renderer's current
 * capability-, trust-, lifecycle- and policy-filtered rows once, then supplies bounded search pages and exact
 * membership checks. Filtering for area and the dashboard self link happens before search and paging.
 *
 * @since  2.0.0
 */
final readonly class DashboardWorkflowCatalog
{
    /**
     * Candidate workflows rendered on one independent no-JavaScript page.
     *
     * @var    int
     * @since  2.0.0
     */
    public const PAGE_SIZE = 32;

    /**
     * Live workflow models keyed in deterministic renderer order.
     *
     * @var    array<string, DashboardWidget>
     * @since  2.0.0
     */
    private array $models;

    /**
     * Non-sensitive catalogue diagnostics.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $diagnostics;

    /**
     * Project one renderer-filtered navigation list into the canonical live workflow catalogue.
     *
     * @param   SurfaceArea                      $area        Administrator or portal dashboard area.
     * @param   SurfaceId                        $surface     Exact dashboard surface whose own link is removed.
     * @param   list<array<string, int|string>>  $navigation  Current renderer-filtered navigation rows.
     *
     * @throws  InvalidArgumentException  When area, list shape, or a retained row is malformed or repeated.
     *
     * @since   2.0.0
     */
    public function __construct(SurfaceArea $area, SurfaceId $surface, array $navigation)
    {
        if (!in_array($area, [SurfaceArea::Administrator, SurfaceArea::Portal], true)) {
            throw new InvalidArgumentException(
                'Dashboard workflow projection is available only to administrator and portal areas.',
            );
        }
        if (!array_is_list($navigation)) {
            throw new InvalidArgumentException('Filtered dashboard navigation must be a list.');
        }

        $home = $area === SurfaceArea::Administrator ? '/administrator' : '/portal';
        $homeId = $area === SurfaceArea::Administrator ? 'core.dashboard' : 'core.portal-home';
        $models = [];
        $diagnostics = [];
        foreach ($navigation as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Filtered dashboard navigation contains an invalid row.');
            }
            $widget = DashboardWidget::fromNavigation($item);
            $path = self::pathWithoutQuery($widget->href ?? '');
            if (
                rtrim($path, '/') === $home
                || ($item['surface'] ?? null) === $surface->value()
                || $widget->id === $homeId
            ) {
                continue;
            }
            if (!str_starts_with($path, $home . '/')) {
                $diagnostics[] = 'dashboard.navigation.area-mismatch';
                continue;
            }
            if (isset($models[$widget->id])) {
                throw new InvalidArgumentException('Filtered dashboard navigation contains a duplicate identifier.');
            }
            $models[$widget->id] = $widget;
        }

        $this->models = $models;
        $this->diagnostics = array_values(array_unique($diagnostics));
    }

    /**
     * Return every current workflow identifier in deterministic navigation order.
     *
     * @return  list<string>  Canonical identifiers from the complete filtered request catalogue.
     *
     * @since   2.0.0
     */
    public function identifiers(): array
    {
        return array_keys($this->models);
    }

    /**
     * Return the complete current model lookup for preference resolution and stale-value pruning.
     *
     * @return  array<string, DashboardWidget>  Live models keyed by canonical identifier.
     *
     * @since   2.0.0
     */
    public function modelMap(): array
    {
        return $this->models;
    }

    /**
     * Search the full live catalogue and return one bounded deterministic candidate page.
     *
     * @param   DashboardPreferenceQuery  $query  Independent normalized group/workflow browse state.
     *
     * @return  DashboardWorkflowPage  At most 32 candidates plus closed cursor evidence.
     *
     * @since   2.0.0
     */
    public function page(DashboardPreferenceQuery $query): DashboardWorkflowPage
    {
        $offset = ($query->workflowPage - 1) * self::PAGE_SIZE;
        $matched = 0;
        $window = [];
        foreach ($this->models as $widget) {
            if ($query->workflowSearch !== '' && !self::matches($widget, $query->workflowSearch)) {
                continue;
            }
            if ($matched++ < $offset) {
                continue;
            }
            $window[] = $widget;
            if (count($window) > self::PAGE_SIZE) {
                break;
            }
        }
        $rawHasNext = count($window) > self::PAGE_SIZE;
        $candidates = array_slice($window, 0, self::PAGE_SIZE);
        $atLimit = $query->workflowPage === DashboardPreferenceQuery::MAXIMUM_PAGE;

        return new DashboardWorkflowPage(
            $query,
            $candidates,
            $rawHasNext && !$atLimit,
            $rawHasNext && $atLimit,
        );
    }

    /**
     * Resolve a bounded selected identifier list against current live workflow models.
     *
     * @param   list<string>  $identifiers  Stored identifiers in preference order.
     *
     * @return  list<DashboardWidget>  Surviving live workflows in the same order.
     *
     * @since   2.0.0
     */
    public function modelsFor(array $identifiers): array
    {
        if (!array_is_list($identifiers) || count($identifiers) > 64) {
            throw new InvalidArgumentException('A dashboard workflow selection is malformed or unbounded.');
        }
        $result = [];
        $seen = [];
        foreach ($identifiers as $identifier) {
            if (!is_string($identifier) || isset($seen[$identifier])) {
                throw new InvalidArgumentException('A dashboard workflow selection contains an invalid item.');
            }
            $seen[$identifier] = true;
            if (isset($this->models[$identifier])) {
                $result[] = $this->models[$identifier];
            }
        }

        return $result;
    }

    /**
     * Validate only the submitted bounded form identifiers against this complete live catalogue.
     *
     * The application service can then receive the submitted list itself as its bounded allowlist instead of
     * copying the complete navigation catalogue into a mutation call.
     *
     * @param   DashboardPreferenceMutation  $mutation       Typed bounded browser command.
     * @param   list<string>                 $coreWidgetIds  Current non-workflow widget identifiers.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a submitted item is not a live candidate for its slot.
     *
     * @since   2.0.0
     */
    public function assertMutation(DashboardPreferenceMutation $mutation, array $coreWidgetIds): void
    {
        $core = self::coreIdentifierMap($coreWidgetIds);
        foreach ($mutation->submittedIds as $identifier) {
            if (
                !isset($this->models[$identifier])
                && !($mutation->slot === CustomizationSlot::DashboardCards && isset($core[$identifier]))
            ) {
                throw new InvalidArgumentException('A dashboard preference contains an unknown identifier.');
            }
        }
    }

    /**
     * Match normalized search against the stable code and current visible text fields.
     *
     * @param   DashboardWidget  $widget  Live workflow candidate.
     * @param   string           $search  Normalized non-empty query.
     *
     * @return  bool  True when any documented candidate field contains the query.
     *
     * @since   2.0.0
     */
    private static function matches(DashboardWidget $widget, string $search): bool
    {
        $haystack = mb_strtolower(implode("\n", [
            $widget->id,
            $widget->title,
            $widget->description,
            $widget->group,
        ]), 'UTF-8');

        return str_contains($haystack, mb_strtolower($search, 'UTF-8'));
    }

    /**
     * Validate a bounded caller-owned core widget allowlist.
     *
     * @param   list<string>  $identifiers  Current core widget identifiers.
     *
     * @return  array<string, true>  Exact lookup.
     *
     * @throws  InvalidArgumentException  When the list is malformed, repeated, or outside its slot bound.
     *
     * @since   2.0.0
     */
    private static function coreIdentifierMap(array $identifiers): array
    {
        if (!array_is_list($identifiers) || count($identifiers) > 64) {
            throw new InvalidArgumentException('Dashboard core widget identifiers are malformed or unbounded.');
        }
        $result = [];
        foreach ($identifiers as $identifier) {
            if (!is_string($identifier) || isset($result[$identifier])) {
                throw new InvalidArgumentException('Dashboard core widget identifiers contain an invalid item.');
            }
            SurfaceId::fromString($identifier);
            $result[$identifier] = true;
        }

        return $result;
    }

    /**
     * Remove query and fragment suffixes before area and self-link comparison.
     *
     * @param   string  $href  Validated root-relative workflow href.
     *
     * @return  string  Path portion before query or fragment.
     *
     * @since   2.0.0
     */
    private static function pathWithoutQuery(string $href): string
    {
        $parts = preg_split('/[?#]/', $href, 2);

        return $parts === false ? $href : ($parts[0] ?? $href);
    }
}
