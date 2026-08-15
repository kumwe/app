<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application\Dashboard;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Presentation\Preference\PresentationAccessGroupRepository;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceContext;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceResolution;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceResolver;
use RuntimeException;

/**
 * Composes an access-aware administrator or portal dashboard from safe data and filtered navigation.
 *
 * The shell registries remain the authority for capability, extension trust, and request-policy filtering.
 * This service accepts only their already-filtered navigation rows, removes self and cross-area destinations,
 * projects the survivors into workflow widgets, and intersects every stored selection with that live catalog.
 * Preference values therefore select semantic identifiers only; they never supply data, markup, or hrefs.
 *
 * @since  2.0.0
 */
final readonly class DashboardComposer
{
    /**
     * Maximum selectable dashboard cards admitted by the KIS preference schema.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_WIDGET_DEFAULTS = 64;

    /**
     * Maximum selectable navigation shortcuts admitted by the KIS preference schema.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_SHORTCUT_DEFAULTS = 32;

    /**
     * Number of visible navigation destinations prepopulated when no shortcut default is supplied.
     *
     * @var    int
     * @since  2.0.0
     */
    private const DERIVED_SHORTCUT_LIMIT = 6;

    /**
     * Maximum renderer-filtered navigation rows projected into one dashboard catalog.
     *
     * The shell may aggregate more rows across many extensions. Keeping the first deterministic registry
     * rows bounds preference delivery without making a large installation's dashboard fail completely.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_NAVIGATION_ROWS = 128;

    /**
     * Bind composition to live presentation preferences and canonical access-group projections.
     *
     * @param  PresentationPreferenceResolver    $preferences  Group-aware preference resolver.
     * @param  PresentationAccessGroupRepository $groups       Current identity-role projection.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PresentationPreferenceResolver $preferences,
        private PresentationAccessGroupRepository $groups,
    ) {
    }

    /**
     * Compose one dashboard for the authenticated actor and delivery area.
     *
     * A null widget default selects every live candidate in catalog order, bounded to the preference schema.
     * A null shortcut default selects the first six live non-self navigation rows. Explicit empty lists remain
     * empty. Group and user preferences are resolved next; their identifiers are intersected with live models
     * in preference order. A non-empty customized selection whose every entry became stale falls back to the
     * current default, while an intentionally empty selection never does.
     *
     * @param   SurfaceArea                    $area                Administrator or portal dashboard area.
     * @param   SurfaceId                      $surface             Exact owner-bound KIS dashboard surface.
     * @param   ContributionOwner              $owner               Current surface owner.
     * @param   ExecutionContext               $context             Authenticated site and actor context.
     * @param   list<array<string, int|string>> $filteredNavigation Capability-, trust-, and policy-filtered rows.
     * @param   list<DashboardWidget>           $coreWidgets         Safe summary, activity, or context widgets.
     * @param   ?list<string>                   $defaultWidgetIds    Curated widget defaults, or null to derive.
     * @param   ?list<string>                   $defaultShortcutIds  Curated shortcut defaults, or null to derive.
     *
     * @return  DashboardView  Selected models, live catalogs, and non-sensitive preference evidence.
     *
     * @throws  InvalidArgumentException  When area, context, widgets, navigation, or defaults are malformed.
     * @throws  RuntimeException  When preference storage violates its typed list contract.
     *
     * @since   2.0.0
     */
    public function compose(
        SurfaceArea $area,
        SurfaceId $surface,
        ContributionOwner $owner,
        ExecutionContext $context,
        array $filteredNavigation,
        array $coreWidgets = [],
        ?array $defaultWidgetIds = null,
        ?array $defaultShortcutIds = null,
    ): DashboardView {
        if (!in_array($area, [SurfaceArea::Administrator, SurfaceArea::Portal], true)) {
            throw new InvalidArgumentException(
                'Dashboard composition is available only to administrator and portal areas.',
            );
        }
        $principal = $context->principal();
        if ($principal === null) {
            throw new InvalidArgumentException('Dashboard composition requires an authenticated human actor.');
        }

        $diagnostics = [];
        $widgetCatalog = $this->coreCatalog($coreWidgets);
        $shortcutCatalog = self::navigationCatalog($area, $surface, $filteredNavigation, $diagnostics);
        foreach ($shortcutCatalog as $id => $widget) {
            if (isset($widgetCatalog[$id])) {
                throw new InvalidArgumentException('A core dashboard widget collides with a navigation identifier.');
            }
            $widgetCatalog[$id] = $widget;
        }

        if ($defaultWidgetIds === null) {
            $defaultWidgetIds = array_slice(array_keys($widgetCatalog), 0, self::MAX_WIDGET_DEFAULTS);
            if (count($widgetCatalog) > self::MAX_WIDGET_DEFAULTS) {
                $diagnostics[] = 'dashboard.widgets.default-truncated';
            }
        }
        self::assertIdentifierList($defaultWidgetIds, self::MAX_WIDGET_DEFAULTS, 'widget defaults');
        if ($defaultShortcutIds === null) {
            $defaultShortcutIds = array_slice(array_keys($shortcutCatalog), 0, self::DERIVED_SHORTCUT_LIMIT);
        }
        self::assertIdentifierList($defaultShortcutIds, self::MAX_SHORTCUT_DEFAULTS, 'shortcut defaults');

        $preferenceContext = PresentationPreferenceContext::fromExecutionContext($area, $context);
        $groups = $this->groups->listForUser($principal->subject());
        $widgetPreference = $this->preferences->resolveListForAccessGroups(
            $surface,
            $owner,
            CustomizationSlot::DashboardCards,
            $defaultWidgetIds,
            $preferenceContext,
            $groups,
        );
        $shortcutPreference = $this->preferences->resolveListForAccessGroups(
            $surface,
            $owner,
            CustomizationSlot::NavigationShortcuts,
            $defaultShortcutIds,
            $preferenceContext,
            $groups,
        );
        $diagnostics = [
            ...$diagnostics,
            ...$widgetPreference->diagnostics,
            ...$shortcutPreference->diagnostics,
        ];

        $widgets = self::selectedModels(
            self::resolvedList($widgetPreference, CustomizationSlot::DashboardCards),
            $widgetCatalog,
            $defaultWidgetIds,
            $widgetPreference->customized(),
            'dashboard.widgets',
            $diagnostics,
        );
        $shortcuts = self::selectedModels(
            self::resolvedList($shortcutPreference, CustomizationSlot::NavigationShortcuts),
            $shortcutCatalog,
            $defaultShortcutIds,
            $shortcutPreference->customized(),
            'dashboard.shortcuts',
            $diagnostics,
        );

        return new DashboardView(
            $widgets,
            self::selectionFirst($widgets, $widgetCatalog),
            $shortcuts,
            self::selectionFirst($shortcuts, $shortcutCatalog),
            array_values(array_unique($diagnostics)),
            $widgetPreference,
            $shortcutPreference,
        );
    }

    /**
     * Project renderer-filtered navigation through the exact workflow boundary used by composition.
     *
     * Dashboard preference mutations call this pure projection again for the current request. They therefore
     * inherit the same deterministic catalog bound, self-link removal, area confinement, identifier validation,
     * and duplicate rejection as GET composition without accepting hrefs or identifiers from submitted data.
     * Diagnostics are intentionally omitted because mutation delivery exposes only closed redirect results.
     *
     * @param   SurfaceArea                     $area        Administrator or portal dashboard area.
     * @param   SurfaceId                       $surface     Exact dashboard surface whose own links are removed.
     * @param   list<array<string, int|string>> $navigation Renderer-filtered live navigation rows.
     *
     * @return  list<string>  Live workflow identifiers in their deterministic dashboard-catalog order.
     *
     * @throws  InvalidArgumentException  When area, list shape, or a retained navigation row is invalid.
     *
     * @since   2.0.0
     */
    public static function workflowIdentifiers(
        SurfaceArea $area,
        SurfaceId $surface,
        array $navigation,
    ): array {
        if (!in_array($area, [SurfaceArea::Administrator, SurfaceArea::Portal], true)) {
            throw new InvalidArgumentException(
                'Dashboard workflow projection is available only to administrator and portal areas.',
            );
        }
        $diagnostics = [];

        return array_keys(self::navigationCatalog($area, $surface, $navigation, $diagnostics));
    }

    /**
     * Validate caller-supplied non-navigation widgets and index them in catalog order.
     *
     * @param   list<DashboardWidget>  $widgets  Summary, activity, or context models.
     *
     * @return  array<string, DashboardWidget>  Models keyed by canonical identifier.
     *
     * @throws  InvalidArgumentException  When the list is unbounded, repeated, or carries a workflow href.
     *
     * @since   2.0.0
     */
    private function coreCatalog(array $widgets): array
    {
        if (!array_is_list($widgets) || count($widgets) > 64) {
            throw new InvalidArgumentException('Caller-supplied dashboard widgets must be a bounded list.');
        }
        $catalog = [];
        foreach ($widgets as $widget) {
            if (!$widget instanceof DashboardWidget || $widget->isWorkflow() || $widget->href !== null) {
                throw new InvalidArgumentException(
                    'Caller-supplied dashboard widgets cannot carry navigation workflows or destinations.',
                );
            }
            if (isset($catalog[$widget->id])) {
                throw new InvalidArgumentException('A caller-supplied dashboard widget identifier is duplicated.');
            }
            $catalog[$widget->id] = $widget;
        }

        return $catalog;
    }

    /**
     * Project safe same-area navigation rows and remove home-page self links.
     *
     * The first 128 deterministic registry rows form the bounded catalog and truncation adds a stable
     * diagnostic. Cross-area paths are dropped rather than rendered. A malformed retained row still raises
     * because it violates the filtered-navigation contract between the shell and this service.
     *
     * @param   SurfaceArea                     $area         Dashboard delivery area.
     * @param   SurfaceId                       $surface      Current dashboard surface excluded from workflows.
     * @param   list<array<string, int|string>> $navigation   Filtered navigation rows in registry order.
     * @param   list<string>                    $diagnostics  Non-sensitive codes accumulated by the caller.
     *
     * @return  array<string, DashboardWidget>  Workflow models keyed in navigation order.
     *
     * @throws  InvalidArgumentException  When the list shape or a retained row is malformed or duplicated.
     *
     * @since   2.0.0
     */
    private static function navigationCatalog(
        SurfaceArea $area,
        SurfaceId $surface,
        array $navigation,
        array &$diagnostics,
    ): array {
        if (!array_is_list($navigation)) {
            throw new InvalidArgumentException('Filtered dashboard navigation must be a list.');
        }
        if (count($navigation) > self::MAX_NAVIGATION_ROWS) {
            $navigation = array_slice($navigation, 0, self::MAX_NAVIGATION_ROWS);
            $diagnostics[] = 'dashboard.navigation.catalog-truncated';
        }
        $home = self::homePath($area);
        $catalog = [];
        foreach ($navigation as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Filtered dashboard navigation contains an invalid row.');
            }
            $widget = DashboardWidget::fromNavigation($item);
            $path = self::pathWithoutQuery($widget->href ?? '');
            if (
                rtrim($path, '/') === $home
                || ($item['surface'] ?? null) === $surface->value()
                || $widget->id === self::homeNavigationId($area)
            ) {
                continue;
            }
            if (!str_starts_with($path, $home . '/')) {
                $diagnostics[] = 'dashboard.navigation.area-mismatch';
                continue;
            }
            if (isset($catalog[$widget->id])) {
                throw new InvalidArgumentException('Filtered dashboard navigation contains a duplicate identifier.');
            }
            $catalog[$widget->id] = $widget;
        }

        return $catalog;
    }

    /**
     * Select live models in preference order and safely handle stale stored identifiers.
     *
     * A partially stale selection remains partial; defaults are not appended. Only a customized, non-empty
     * selection with no surviving model falls back to the live portion of the immutable default. Empty stored
     * lists therefore remain a deliberate request for an empty dashboard or shortcut bar.
     *
     * @param   list<string>                    $selected     Resolved identifiers in preference order.
     * @param   array<string, DashboardWidget>  $catalog      Live candidate catalog.
     * @param   list<string>                    $defaults     Immutable access-aware defaults.
     * @param   bool                            $customized   Whether a stored layer supplied the selection.
     * @param   string                          $diagnostic   Non-sensitive diagnostic code prefix.
     * @param   list<string>                    $diagnostics  Diagnostic codes accumulated by the caller.
     *
     * @return  list<DashboardWidget>  Effective selected models.
     *
     * @since   2.0.0
     */
    private static function selectedModels(
        array $selected,
        array $catalog,
        array $defaults,
        bool $customized,
        string $diagnostic,
        array &$diagnostics,
    ): array {
        $models = self::modelsFor($selected, $catalog);
        if (count($models) !== count($selected)) {
            $diagnostics[] = $diagnostic . '.selection-pruned';
        }
        if ($customized && $selected !== [] && $models === []) {
            $models = self::modelsFor($defaults, $catalog);
            $diagnostics[] = $diagnostic . '.selection-fallback';
        }

        return $models;
    }

    /**
     * Resolve identifiers against a live catalog without changing caller order.
     *
     * @param   list<string>                    $identifiers  Ordered semantic identifiers.
     * @param   array<string, DashboardWidget>  $catalog      Live candidates keyed by identifier.
     *
     * @return  list<DashboardWidget>  Live matching models in identifier order.
     *
     * @since   2.0.0
     */
    private static function modelsFor(array $identifiers, array $catalog): array
    {
        $models = [];
        foreach ($identifiers as $identifier) {
            if (isset($catalog[$identifier])) {
                $models[] = $catalog[$identifier];
            }
        }

        return $models;
    }

    /**
     * Order a selectable catalog with current selections first and remaining items in catalog order.
     *
     * @param   list<DashboardWidget>            $selected  Current selected models.
     * @param   array<string, DashboardWidget>   $catalog   Live candidates in deterministic catalog order.
     *
     * @return  list<DashboardWidget>  Selection-first catalog without duplicates.
     *
     * @since   2.0.0
     */
    private static function selectionFirst(array $selected, array $catalog): array
    {
        $ordered = [];
        foreach ($selected as $widget) {
            $ordered[$widget->id] = $widget;
        }
        foreach ($catalog as $widget) {
            $ordered[$widget->id] ??= $widget;
        }

        return array_values($ordered);
    }

    /**
     * Extract one typed list from a dashboard preference resolution.
     *
     * @param   PresentationPreferenceResolution  $resolution  Group-aware list resolution.
     * @param   CustomizationSlot                 $slot        Expected list slot for failure context.
     *
     * @return  list<string>  Validated semantic identifiers.
     *
     * @throws  RuntimeException  When a resolver violates the typed preference-value contract.
     *
     * @since   2.0.0
     */
    private static function resolvedList(
        PresentationPreferenceResolution $resolution,
        CustomizationSlot $slot,
    ): array {
        $value = $resolution->value->value();
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException(sprintf('The resolved %s dashboard preference is not a list.', $slot->value));
        }
        foreach ($value as $identifier) {
            if (!is_string($identifier)) {
                throw new RuntimeException(sprintf(
                    'The resolved %s dashboard preference contains a non-string identifier.',
                    $slot->value,
                ));
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * Validate one explicit immutable default list before preference resolution.
     *
     * @param   array<array-key, mixed>  $identifiers  Candidate dotted widget or navigation identifiers.
     * @param   int                      $maximum      Slot-specific maximum count.
     * @param   string                   $kind         Human list kind for failures.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the list is repeated, malformed, or unbounded.
     *
     * @since   2.0.0
     */
    private static function assertIdentifierList(array $identifiers, int $maximum, string $kind): void
    {
        if (!array_is_list($identifiers) || count($identifiers) > $maximum) {
            throw new InvalidArgumentException(sprintf('Dashboard %s must be a bounded list.', $kind));
        }
        $seen = [];
        foreach ($identifiers as $identifier) {
            if (
                !is_string($identifier)
                || isset($seen[$identifier])
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Dashboard %s contain an invalid or duplicate identifier.',
                    $kind,
                ));
            }
            SurfaceId::fromString($identifier);
            $seen[$identifier] = true;
        }
    }

    /**
     * Return the canonical dashboard home path for one supported delivery area.
     *
     * @param   SurfaceArea  $area  Validated administrator or portal area.
     *
     * @return  string  `/administrator` or `/portal`.
     *
     * @since   2.0.0
     */
    private static function homePath(SurfaceArea $area): string
    {
        return $area === SurfaceArea::Administrator ? '/administrator' : '/portal';
    }

    /**
     * Return the core navigation identifier reserved for one dashboard home.
     *
     * @param   SurfaceArea  $area  Validated administrator or portal area.
     *
     * @return  string  `core.dashboard` or `core.portal-home`.
     *
     * @since   2.0.0
     */
    private static function homeNavigationId(SurfaceArea $area): string
    {
        return $area === SurfaceArea::Administrator ? 'core.dashboard' : 'core.portal-home';
    }

    /**
     * Remove query and fragment suffixes before area and self-link comparison.
     *
     * Full href validation remains in `DashboardWidget::fromNavigation()`, so this helper only identifies
     * the path prefix and cannot turn an unsafe destination into an admitted one.
     *
     * @param   string  $href  Candidate filtered navigation href.
     *
     * @return  string  Path portion before `?` or `#`.
     *
     * @since   2.0.0
     */
    private static function pathWithoutQuery(string $href): string
    {
        $parts = preg_split('/[?#]/', $href, 2);

        return $parts === false ? $href : ($parts[0] ?? $href);
    }
}
