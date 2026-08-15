<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application\Dashboard;

use InvalidArgumentException;
use Kumwe\CMS\Application\Presentation\Dashboard\DashboardPreferenceState;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\PresentationPreference;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use RuntimeException;

/**
 * Maps typed application preference state into server-rendered dashboard form models.
 *
 * This presentation boundary owns message identifiers, inherited personal fallbacks, form ordering, and the
 * bounded access-group browser state. It receives no request input and performs no persistence or authorization.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceFormPresenter
{
    /**
     * Build personal and authorized access-group forms from typed application state.
     *
     * @param   DashboardPreferenceState   $state                Authorized bounded query result.
     * @param   list<string>               $fallbackWidgetIds    Currently rendered effective personal widgets.
     * @param   list<string>               $fallbackShortcutIds  Currently rendered effective personal shortcuts.
     * @param   ?DashboardWorkflowCatalog  $workflowCatalog      Complete request-local live workflow projection.
     * @param   list<DashboardWidget>      $coreWidgets          Current non-workflow widget candidates.
     *
     * @return  DashboardPreferenceFormProjection  Forms and explicit bounded access-group browser state.
     *
     * @throws  InvalidArgumentException  When a rendered fallback is malformed or outside its KIS list bound.
     * @throws  RuntimeException  When a typed preference unexpectedly contains a non-list value.
     *
     * @since   2.0.0
     */
    public function present(
        DashboardPreferenceState $state,
        array $fallbackWidgetIds = [],
        array $fallbackShortcutIds = [],
        ?DashboardWorkflowCatalog $workflowCatalog = null,
        array $coreWidgets = [],
    ): DashboardPreferenceFormProjection {
        self::assertFallbackList($fallbackWidgetIds, 64, 'widget');
        self::assertFallbackList($fallbackShortcutIds, 32, 'shortcut');
        $coreCatalog = self::coreCatalog($coreWidgets);
        $workflowPage = $workflowCatalog?->page($state->accessGroupQuery);
        $forms = [$this->formModel(
            CustomizationScope::User,
            $state->userScopeId,
            'core.interface_standard.dashboard.personal_eyebrow',
            'core.interface_standard.dashboard.personal_label',
            true,
            'core.interface_standard.dashboard.personal_help',
            null,
            $state->personalWidgets,
            $state->personalShortcuts,
            $fallbackWidgetIds,
            $fallbackShortcutIds,
            $workflowCatalog,
            $workflowPage,
            $coreCatalog,
        )];
        foreach ($state->accessGroups as $accessGroup) {
            $forms[] = $this->formModel(
                CustomizationScope::RoleWorkspace,
                $accessGroup->group->id,
                'core.interface_standard.dashboard.access_group_eyebrow',
                $accessGroup->group->name,
                false,
                'core.interface_standard.dashboard.access_group_help',
                $accessGroup->group->code,
                $accessGroup->widgets,
                $accessGroup->shortcuts,
                [],
                [],
                $workflowCatalog,
                $workflowPage,
                $coreCatalog,
            );
        }

        return new DashboardPreferenceFormProjection(
            $forms,
            $state->accessGroupBrowseLimit
                ? ['dashboard.preferences.access-group-browse-limit']
                : [],
            $state->accessGroupAdministration,
            $state->accessGroupQuery,
            count($state->accessGroups),
            $state->accessGroupHasPrevious,
            $state->accessGroupHasNext,
            $state->accessGroupBrowseLimit,
            $workflowPage,
        );
    }

    /**
     * Build one exact form model from two optional typed rows and inherited personal fallbacks.
     *
     * @param   CustomizationScope              $scope                User or access-group hierarchy layer.
     * @param   string                          $scopeId              Actor or stable `role:<uuid>` identity.
     * @param   string                          $scopeLabel           Catalogue identifier for the eyebrow.
     * @param   string                          $label                Catalogue ID or operator role name.
     * @param   bool                            $messageIds           Whether label is a catalogue identifier.
     * @param   string                          $help                 Catalogue identifier for form help.
     * @param   ?string                         $groupCode            Canonical role code, or null for personal scope.
     * @param   ?PresentationPreference         $widgetPreference     Exact stored card row, or null when inherited.
     * @param   ?PresentationPreference         $shortcutPreference   Exact stored shortcut row, or null when inherited.
     * @param   list<string>                    $fallbackWidgetIds    Personal widgets used when absent.
     * @param   list<string>                    $fallbackShortcutIds  Personal shortcuts used when absent.
     * @param   ?DashboardWorkflowCatalog       $workflowCatalog      Complete live workflow projection.
     * @param   ?DashboardWorkflowPage          $workflowPage         Current bounded workflow candidate page.
     * @param   array<string, DashboardWidget>  $coreCatalog          Current core widgets keyed by identifier.
     *
     * @return  array{
     *              scope: string,
     *              scope_id: string,
     *              scope_label: string,
     *              label: string,
     *              message_ids: bool,
     *              help: string,
     *              group_code: ?string,
     *              available_widgets: list<array<string, mixed>>,
     *              available_shortcuts: list<array<string, mixed>>,
     *              selected_widget_ids: list<string>,
     *              widget_order: array<string, int>,
     *              widget_version: int,
     *              selected_shortcut_ids: list<string>,
     *              shortcut_order: array<string, int>,
     *              shortcut_version: int
     *          }  Exact form model.
     *
     * @throws  RuntimeException  When a typed preference unexpectedly contains a non-list value.
     *
     * @since   2.0.0
     */
    private function formModel(
        CustomizationScope $scope,
        string $scopeId,
        string $scopeLabel,
        string $label,
        bool $messageIds,
        string $help,
        ?string $groupCode,
        ?PresentationPreference $widgetPreference,
        ?PresentationPreference $shortcutPreference,
        array $fallbackWidgetIds,
        array $fallbackShortcutIds,
        ?DashboardWorkflowCatalog $workflowCatalog,
        ?DashboardWorkflowPage $workflowPage,
        array $coreCatalog,
    ): array {
        [$widgets, $widgetVersion] = self::selection($widgetPreference, $fallbackWidgetIds);
        [$shortcuts, $shortcutVersion] = self::selection($shortcutPreference, $fallbackShortcutIds);
        $availableWidgets = [];
        $availableShortcuts = [];
        if ($workflowCatalog instanceof DashboardWorkflowCatalog && $workflowPage instanceof DashboardWorkflowPage) {
            $liveWidgets = [...$coreCatalog, ...$workflowCatalog->modelMap()];
            $widgets = self::liveIdentifiers($widgets, $liveWidgets);
            $shortcuts = self::identifiers($workflowCatalog->modelsFor($shortcuts));
            $pageCatalog = [];
            foreach ($workflowPage->candidates as $candidate) {
                $pageCatalog[$candidate->id] = $candidate;
            }
            $availableWidgets = self::modelsToArrays(self::selectionFirst(
                self::modelsFor($widgets, $liveWidgets),
                [...$coreCatalog, ...$pageCatalog],
            ));
            $availableShortcuts = self::modelsToArrays(self::selectionFirst(
                $workflowCatalog->modelsFor($shortcuts),
                $pageCatalog,
            ));
        }

        return [
            'scope' => $scope->value,
            'scope_id' => $scopeId,
            'scope_label' => $scopeLabel,
            'label' => $label,
            'message_ids' => $messageIds,
            'help' => $help,
            'group_code' => $groupCode,
            'available_widgets' => $availableWidgets,
            'available_shortcuts' => $availableShortcuts,
            'selected_widget_ids' => $widgets,
            'widget_order' => self::orderMap($widgets),
            'widget_version' => $widgetVersion,
            'selected_shortcut_ids' => $shortcuts,
            'shortcut_order' => self::orderMap($shortcuts),
            'shortcut_version' => $shortcutVersion,
        ];
    }

    /**
     * Validate and index the bounded caller-owned non-workflow candidates.
     *
     * @param   list<DashboardWidget>  $widgets  Current core widget models.
     *
     * @return  array<string, DashboardWidget>  Models keyed in caller order.
     *
     * @throws  InvalidArgumentException  When the list is malformed, repeated, or carries a workflow.
     *
     * @since   2.0.0
     */
    private static function coreCatalog(array $widgets): array
    {
        if (!array_is_list($widgets) || count($widgets) > 64) {
            throw new InvalidArgumentException('Dashboard preference core widgets must be a bounded list.');
        }
        $catalog = [];
        foreach ($widgets as $widget) {
            if (
                !$widget instanceof DashboardWidget
                || $widget->isWorkflow()
                || isset($catalog[$widget->id])
            ) {
                throw new InvalidArgumentException('Dashboard preference core widgets contain an invalid item.');
            }
            $catalog[$widget->id] = $widget;
        }

        return $catalog;
    }

    /**
     * Keep stored identifiers that still resolve to a current core or workflow model.
     *
     * @param   list<string>                    $identifiers  Stored or inherited preference order.
     * @param   array<string, DashboardWidget>  $catalog      Complete current live model lookup.
     *
     * @return  list<string>  Live identifiers in original order.
     *
     * @since   2.0.0
     */
    private static function liveIdentifiers(array $identifiers, array $catalog): array
    {
        return array_values(array_filter(
            $identifiers,
            static fn (string $identifier): bool => isset($catalog[$identifier]),
        ));
    }

    /**
     * Resolve identifiers against a current live model lookup.
     *
     * @param   list<string>                    $identifiers  Selected identifiers.
     * @param   array<string, DashboardWidget>  $catalog      Models keyed by identifier.
     *
     * @return  list<DashboardWidget>  Matching models in selection order.
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
     * Put a form's selected live models before its current candidate page without duplicates.
     *
     * @param   list<DashboardWidget>           $selected  Live selected models in stored order.
     * @param   array<string, DashboardWidget>  $catalog   Core and current page candidates.
     *
     * @return  list<DashboardWidget>  Bounded form-specific choice list.
     *
     * @since   2.0.0
     */
    private static function selectionFirst(array $selected, array $catalog): array
    {
        $choices = [];
        foreach ($selected as $widget) {
            $choices[$widget->id] = $widget;
        }
        foreach ($catalog as $widget) {
            $choices[$widget->id] ??= $widget;
        }
        if (count($choices) > 256) {
            throw new InvalidArgumentException('Dashboard preference choices exceed the bounded form contract.');
        }

        return array_values($choices);
    }

    /**
     * Return canonical identifiers from a workflow model list.
     *
     * @param   list<DashboardWidget>  $widgets  Live workflow models.
     *
     * @return  list<string>  Identifiers in model order.
     *
     * @since   2.0.0
     */
    private static function identifiers(array $widgets): array
    {
        return array_map(static fn (DashboardWidget $widget): string => $widget->id, $widgets);
    }

    /**
     * Export one form-specific bounded choice list for the strict template contract.
     *
     * @param   list<DashboardWidget>  $widgets  Live current models.
     *
     * @return  list<array<string, mixed>>  Safe widget documents.
     *
     * @since   2.0.0
     */
    private static function modelsToArrays(array $widgets): array
    {
        return array_map(static fn (DashboardWidget $widget): array => $widget->toArray(), $widgets);
    }

    /**
     * Resolve one stored list or its presentation-only inherited fallback.
     *
     * @param   ?PresentationPreference  $preference  Exact row, or null when the layer inherits.
     * @param   list<string>             $fallback    Effective personal choices rendered when absent.
     *
     * @return  array{list<string>, int}  Exact or inherited list and its optimistic row version.
     *
     * @throws  RuntimeException  When a typed preference unexpectedly contains a non-list value.
     *
     * @since   2.0.0
     */
    private static function selection(?PresentationPreference $preference, array $fallback): array
    {
        if ($preference === null) {
            return [$fallback, 0];
        }
        $value = $preference->value()->value();
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('A dashboard preference state contains a non-list value.');
        }
        foreach ($value as $identifier) {
            if (!is_string($identifier)) {
                throw new RuntimeException('A dashboard preference state contains a non-string identifier.');
            }
        }

        /** @var list<string> $value */
        return [$value, $preference->version()];
    }

    /**
     * Map exact stored order without consulting an effective selection-first catalogue.
     *
     * @param   list<string>  $identifiers  Exact stored identifiers in presentation order.
     *
     * @return  array<string, int>  Identifier to one-based position.
     *
     * @since   2.0.0
     */
    private static function orderMap(array $identifiers): array
    {
        $order = [];
        foreach ($identifiers as $index => $identifier) {
            $order[$identifier] = $index + 1;
        }

        return $order;
    }

    /**
     * Validate one effective personal fallback against the semantic grammar and KIS list bound.
     *
     * @param   list<string>  $identifiers  Effective identifiers rendered by dashboard composition.
     * @param   int           $maximum      KIS selected-list bound for the slot.
     * @param   string        $kind         Stable list kind used in a failure message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the fallback is malformed, duplicated, or exceeds its slot bound.
     *
     * @since   2.0.0
     */
    private static function assertFallbackList(array $identifiers, int $maximum, string $kind): void
    {
        if (!array_is_list($identifiers) || count($identifiers) > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'The dashboard preference %s fallback exceeds the KIS limit.',
                $kind,
            ));
        }
        $seen = [];
        foreach ($identifiers as $identifier) {
            if (!is_string($identifier) || isset($seen[$identifier])) {
                throw new InvalidArgumentException('A dashboard preference fallback contains an invalid item.');
            }
            try {
                SurfaceId::fromString($identifier);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException(
                    'A dashboard preference fallback contains an invalid item.',
                    0,
                    $exception,
                );
            }
            $seen[$identifier] = true;
        }
    }
}
