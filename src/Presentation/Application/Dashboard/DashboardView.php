<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application\Dashboard;

use InvalidArgumentException;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceResolution;

/**
 * Immutable composition of selected dashboard widgets, shortcuts, choices, and safe preference evidence.
 *
 * @since  2.0.0
 */
final readonly class DashboardView
{
    /**
     * Maximum live models exposed by either selectable catalog.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_CATALOG_MODELS = 256;

    /**
     * Selected widgets in effective preference order.
     *
     * @var    list<DashboardWidget>
     * @since  2.0.0
     */
    public array $widgets;

    /**
     * Selected live widgets followed by core and current workflow-page candidates without duplicates.
     *
     * @var    list<DashboardWidget>
     * @since  2.0.0
     */
    public array $availableWidgets;

    /**
     * Selected navigation shortcuts in effective preference order.
     *
     * @var    list<DashboardWidget>
     * @since  2.0.0
     */
    public array $shortcuts;

    /**
     * Selected live shortcuts followed by current workflow-page candidates without duplicates.
     *
     * @var    list<DashboardWidget>
     * @since  2.0.0
     */
    public array $availableShortcuts;

    /**
     * Effective selected widget identifiers in render order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $selectedWidgetIds;

    /**
     * Effective selected shortcut identifiers in render order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $selectedShortcutIds;

    /**
     * Unique non-sensitive compatibility and fallback diagnostic codes.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $diagnostics;

    /**
     * Whether stored dashboard-card preferences participated in selection.
     *
     * @var    bool
     * @since  2.0.0
     */
    public bool $widgetsCustomized;

    /**
     * Winning dashboard-card preference scope, or null for the KIS default.
     *
     * @var    ?CustomizationScope
     * @since  2.0.0
     */
    public ?CustomizationScope $widgetSource;

    /**
     * Winning dashboard-card record version, null for a default or synthetic multi-group union.
     *
     * @var    ?int
     * @since  2.0.0
     */
    public ?int $widgetVersion;

    /**
     * Whether stored navigation-shortcut preferences participated in selection.
     *
     * @var    bool
     * @since  2.0.0
     */
    public bool $shortcutsCustomized;

    /**
     * Winning navigation-shortcut preference scope, or null for the KIS default.
     *
     * @var    ?CustomizationScope
     * @since  2.0.0
     */
    public ?CustomizationScope $shortcutSource;

    /**
     * Winning shortcut record version, null for a default or synthetic multi-group union.
     *
     * @var    ?int
     * @since  2.0.0
     */
    public ?int $shortcutVersion;

    /**
     * Capture one complete dashboard composition and preference resolution evidence.
     *
     * @param   list<DashboardWidget>             $widgets             Selected widgets.
     * @param   list<DashboardWidget>             $availableWidgets    Live selectable widgets.
     * @param   list<DashboardWidget>             $shortcuts           Selected workflow shortcuts.
     * @param   list<DashboardWidget>             $availableShortcuts  Live selectable workflow shortcuts.
     * @param   list<string>                      $diagnostics         Non-sensitive stable diagnostic codes.
     * @param   PresentationPreferenceResolution  $widgetPreference    Dashboard-card preference evidence.
     * @param   PresentationPreferenceResolution  $shortcutPreference  Navigation-shortcut preference evidence.
     *
     * @throws  InvalidArgumentException  When lists contain invalid, repeated, or inconsistent models.
     *
     * @since   2.0.0
     */
    public function __construct(
        array $widgets,
        array $availableWidgets,
        array $shortcuts,
        array $availableShortcuts,
        array $diagnostics,
        PresentationPreferenceResolution $widgetPreference,
        PresentationPreferenceResolution $shortcutPreference,
    ) {
        $this->widgets = self::assertWidgetList($widgets, 'selected widget');
        $this->availableWidgets = self::assertWidgetList($availableWidgets, 'available widget');
        $this->shortcuts = self::assertWidgetList($shortcuts, 'selected shortcut', true);
        $this->availableShortcuts = self::assertWidgetList($availableShortcuts, 'available shortcut', true);
        $this->selectedWidgetIds = self::identifiers($this->widgets);
        $this->selectedShortcutIds = self::identifiers($this->shortcuts);
        self::assertSubset($this->selectedWidgetIds, self::identifiers($this->availableWidgets), 'widget');
        self::assertSubset($this->selectedShortcutIds, self::identifiers($this->availableShortcuts), 'shortcut');
        $this->diagnostics = self::assertDiagnostics($diagnostics);
        $this->widgetsCustomized = $widgetPreference->customized();
        $this->widgetSource = $widgetPreference->source;
        $this->widgetVersion = $widgetPreference->version;
        $this->shortcutsCustomized = $shortcutPreference->customized();
        $this->shortcutSource = $shortcutPreference->source;
        $this->shortcutVersion = $shortcutPreference->version;
    }

    /**
     * Export the stable graphical template and preference-form contract.
     *
     * @return  array<string, mixed>  Bounded widget, choice, diagnostic, and preference-evidence document.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'widgets' => array_map(
                static fn (DashboardWidget $widget): array => $widget->toArray(),
                $this->widgets,
            ),
            'available_widgets' => array_map(
                static fn (DashboardWidget $widget): array => $widget->toArray(),
                $this->availableWidgets,
            ),
            'shortcuts' => array_map(
                static fn (DashboardWidget $widget): array => $widget->toArray(),
                $this->shortcuts,
            ),
            'available_shortcuts' => array_map(
                static fn (DashboardWidget $widget): array => $widget->toArray(),
                $this->availableShortcuts,
            ),
            'selected_widget_ids' => $this->selectedWidgetIds,
            'selected_shortcut_ids' => $this->selectedShortcutIds,
            'diagnostics' => $this->diagnostics,
            'customized' => [
                'dashboard_cards' => $this->widgetsCustomized,
                'navigation_shortcuts' => $this->shortcutsCustomized,
            ],
            'source' => [
                'dashboard_cards' => $this->widgetSource?->value,
                'navigation_shortcuts' => $this->shortcutSource?->value,
            ],
            'version' => [
                'dashboard_cards' => $this->widgetVersion,
                'navigation_shortcuts' => $this->shortcutVersion,
            ],
        ];
    }

    /**
     * Validate a unique list of widget models and optionally require navigation workflows.
     *
     * @param   array<array-key, mixed>  $widgets       Candidate widget list.
     * @param   string                   $kind          Human list kind for failures.
     * @param   bool                     $workflowOnly  Whether every entry must be a workflow widget.
     *
     * @return  list<DashboardWidget>  Validated original list.
     *
     * @throws  InvalidArgumentException  When the list is unbounded, non-sequential, invalid, or repeated.
     *
     * @since   2.0.0
     */
    private static function assertWidgetList(array $widgets, string $kind, bool $workflowOnly = false): array
    {
        if (!array_is_list($widgets) || count($widgets) > self::MAX_CATALOG_MODELS) {
            throw new InvalidArgumentException(sprintf('A dashboard %s list is malformed or unbounded.', $kind));
        }
        $seen = [];
        foreach ($widgets as $widget) {
            if (
                !$widget instanceof DashboardWidget
                || ($workflowOnly && !$widget->isWorkflow())
                || isset($seen[$widget->id])
            ) {
                throw new InvalidArgumentException(sprintf('A dashboard %s list contains an invalid entry.', $kind));
            }
            $seen[$widget->id] = true;
        }

        /** @var list<DashboardWidget> $widgets */
        return $widgets;
    }

    /**
     * Return widget identifiers without changing their effective order.
     *
     * @param   list<DashboardWidget>  $widgets  Validated widget models.
     *
     * @return  list<string>  Canonical identifiers in the same order.
     *
     * @since   2.0.0
     */
    private static function identifiers(array $widgets): array
    {
        return array_map(static fn (DashboardWidget $widget): string => $widget->id, $widgets);
    }

    /**
     * Require selected identifiers to remain available in the live catalog.
     *
     * @param   list<string>  $selected   Effective selected identifiers.
     * @param   list<string>  $available  Live selectable identifiers.
     * @param   string        $kind       Human list kind for failures.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a selected entry is absent from the corresponding catalog.
     *
     * @since   2.0.0
     */
    private static function assertSubset(array $selected, array $available, string $kind): void
    {
        if (array_diff($selected, $available) !== []) {
            throw new InvalidArgumentException(sprintf(
                'Selected dashboard %s entries must remain in the live catalog.',
                $kind,
            ));
        }
    }

    /**
     * Validate and deduplicate stable non-sensitive diagnostic codes.
     *
     * @param   array<array-key, mixed>  $diagnostics  Candidate codes.
     *
     * @return  list<string>  Unique codes in first-occurrence order.
     *
     * @throws  InvalidArgumentException  When a code is malformed or the list is unbounded.
     *
     * @since   2.0.0
     */
    private static function assertDiagnostics(array $diagnostics): array
    {
        if (!array_is_list($diagnostics) || count($diagnostics) > 64) {
            throw new InvalidArgumentException('Dashboard diagnostics must be a bounded list.');
        }
        $result = [];
        foreach ($diagnostics as $diagnostic) {
            if (
                !is_string($diagnostic)
                || strlen($diagnostic) > 191
                || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $diagnostic) !== 1
            ) {
                throw new InvalidArgumentException('A dashboard diagnostic code is invalid.');
            }
            $result[$diagnostic] = $diagnostic;
        }

        return array_values($result);
    }
}
