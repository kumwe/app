<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application\Dashboard;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationDenied;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceValue;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use Kumwe\CMS\Presentation\Application\Preference\PresentationAccessGroup;
use Kumwe\CMS\Presentation\Application\Preference\PresentationAccessGroupRepository;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceManager;
use RuntimeException;

/**
 * Builds exact dashboard-preference forms and executes strictly allowlisted personal or role mutations.
 *
 * Form reads and writes share `PresentationPreferenceManager`, so delivery cannot bypass live surface policy,
 * exact-scope authorization, optimistic versions, audit, or transaction boundaries. Submitted identifiers are
 * presentation choices only: the caller supplies the current capability-filtered catalog and no href, markup,
 * query, component, or policy expression has a representation here.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceService
{
    /**
     * Largest live catalog one flat dashboard form may describe.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAXIMUM_FORM_ITEMS = 256;

    /**
     * Bind preference delivery to the canonical mutation boundary and role projection.
     *
     * @param  PresentationPreferenceManager      $preferences  Authorized export and mutation service.
     * @param  PresentationAccessGroupRepository  $groups       Read-only canonical role projection.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PresentationPreferenceManager $preferences,
        private PresentationAccessGroupRepository $groups,
    ) {
    }

    /**
     * Build personal and optionally manageable role forms from exact rows and rendered personal defaults.
     *
     * A missing personal row adopts the currently rendered effective list, so an unchanged first save cannot
     * erase a default dashboard. A missing access-group row remains empty because it represents no additional
     * group layer. Each exact role is authorized through `export()` and denied roles are omitted. Stored order
     * is preserved independently rather than inferred from the viewing actor's selection-first live catalog.
     *
     * @param   ExecutionContext   $context              Authenticated actor requesting preference forms.
     * @param   SurfaceArea       $area                 Administrator or portal delivery area.
     * @param   SurfaceId         $surface              Exact dashboard surface.
     * @param   ContributionOwner $owner                Current owner of the dashboard surface.
     * @param   bool              $includeAccessGroups  Whether authorized access-group forms are considered.
     * @param   list<string>      $fallbackWidgetIds    Currently rendered effective personal widget choices.
     * @param   list<string>      $fallbackShortcutIds  Currently rendered effective personal shortcut choices.
     *
     * @return  list<array{
     *              scope: string,
     *              scope_id: string,
     *              scope_label: string,
     *              label: string,
     *              message_ids: bool,
     *              help: string,
     *              selected_widget_ids: list<string>,
     *              widget_order: array<string, int>,
     *              widget_version: int,
     *              selected_shortcut_ids: list<string>,
     *              shortcut_order: array<string, int>,
     *              shortcut_version: int
     *          }>  Personal form followed by authorized role forms in repository order.
     *
     * @throws  InvalidArgumentException  When area or actor context cannot address a dashboard preference.
     * @throws  RuntimeException  When an exported manager document violates its typed list contract.
     *
     * @since   2.0.0
     */
    public function formModels(
        ExecutionContext $context,
        SurfaceArea $area,
        SurfaceId $surface,
        ContributionOwner $owner,
        bool $includeAccessGroups = false,
        array $fallbackWidgetIds = [],
        array $fallbackShortcutIds = [],
    ): array {
        self::assertArea($area);
        if ($context->principal() === null) {
            throw new InvalidArgumentException('Dashboard preference forms require an authenticated human actor.');
        }
        self::assertFallbackList($fallbackWidgetIds, 64, 'widget');
        self::assertFallbackList($fallbackShortcutIds, 32, 'shortcut');

        $forms = [$this->formModel(
            $context,
            $surface,
            $owner,
            CustomizationScope::User,
            $context->actorId(),
            'core.interface_standard.dashboard.personal_eyebrow',
            'core.interface_standard.dashboard.personal_label',
            true,
            'core.interface_standard.dashboard.personal_help',
            $fallbackWidgetIds,
            $fallbackShortcutIds,
        )];
        if (!$includeAccessGroups) {
            return $forms;
        }

        foreach ($this->groups->listAll() as $group) {
            try {
                $forms[] = $this->formModel(
                    $context,
                    $surface,
                    $owner,
                    CustomizationScope::RoleWorkspace,
                    $group->id,
                    'core.interface_standard.dashboard.access_group_eyebrow',
                    $group->name,
                    false,
                    'core.interface_standard.dashboard.access_group_help',
                    [],
                    [],
                );
            } catch (AuthorizationDenied) {
                continue;
            }
        }

        return $forms;
    }

    /**
     * Parse and execute one dashboard-card or navigation-shortcut save or reset.
     *
     * Both actor-facing areas may target the actor's user row or a canonical `role:<uuid>` row, whose live
     * existence and exact `users.manage` authorization are rechecked by the manager. Save order comes only
     * from a complete, duplicate-free indexed form projection intersected with the caller's current live
     * catalog; reset ignores stale selection fields so recovery remains available after a contribution vanishes.
     *
     * @param   ExecutionContext      $context             Authenticated actor performing the mutation.
     * @param   SurfaceArea          $area                Administrator or portal delivery area.
     * @param   SurfaceId            $surface             Exact dashboard surface.
     * @param   ContributionOwner    $owner               Current owner of the dashboard surface.
     * @param   array<string, string> $form                Flattened form from the area's canonical request reader.
     * @param   list<string>         $allowedWidgetIds    Current live capability-filtered widget identifiers.
     * @param   list<string>         $allowedShortcutIds  Current live capability-filtered navigation identifiers.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When action, target, version, items, selection, or ordering is invalid.
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When manager policy refuses the target.
     * @throws  \Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceVersionConflict
     *          When the exact stored row changed after the form was rendered.
     *
     * @since   2.0.0
     */
    public function mutate(
        ExecutionContext $context,
        SurfaceArea $area,
        SurfaceId $surface,
        ContributionOwner $owner,
        array $form,
        array $allowedWidgetIds,
        array $allowedShortcutIds,
    ): void {
        self::assertArea($area);
        self::assertStringForm($form);
        [$slot, $reset] = self::action($form['action'] ?? null);
        $key = self::key($context, $surface, $slot, $form);
        $expectedVersion = self::version($form['expected_version'] ?? null);
        if ($reset) {
            if ($expectedVersion < 1) {
                throw new InvalidArgumentException('A dashboard preference reset requires a positive version.');
            }
            $this->preferences->reset($context, $owner, $key, $expectedVersion);
            return;
        }

        $allowed = $slot === CustomizationSlot::DashboardCards
            ? $allowedWidgetIds
            : $allowedShortcutIds;
        $maximum = $slot === CustomizationSlot::DashboardCards ? 64 : 32;
        $selected = self::selectedIds($form, $allowed, $maximum);
        $this->preferences->put($context, $owner, $key, $selected, $expectedVersion);
    }

    /**
     * Build one form model from two exact exports and optional missing-personal-row fallbacks.
     *
     * @param   ExecutionContext   $context     Actor whose authorization is evaluated by the manager.
     * @param   SurfaceId         $surface     Dashboard surface being customized.
     * @param   ContributionOwner $owner       Current surface owner.
     * @param   CustomizationScope $scope      User or access-group hierarchy layer.
     * @param   string            $scopeId     Actor UUID or stable `role:<uuid>` identity.
     * @param   string            $scopeLabel  Catalogue identifier for the scope eyebrow.
     * @param   string            $label       Catalogue identifier or operator-authored role name.
     * @param   bool              $messageIds  Whether the label is a catalogue identifier.
     * @param   string            $help        Catalogue identifier for explanatory help.
     * @param   list<string>      $fallbackWidgetIds    Effective widgets used only when the personal row is absent.
     * @param   list<string>      $fallbackShortcutIds  Effective shortcuts used only when the personal row is absent.
     *
     * @return  array{
     *              scope: string,
     *              scope_id: string,
     *              scope_label: string,
     *              label: string,
     *              message_ids: bool,
     *              help: string,
     *              selected_widget_ids: list<string>,
     *              widget_order: array<string, int>,
     *              widget_version: int,
     *              selected_shortcut_ids: list<string>,
     *              shortcut_order: array<string, int>,
     *              shortcut_version: int
     *          }  Exact form model.
     *
     * @throws  AuthorizationDenied  When the actor may not inspect an access-group row.
     * @throws  RuntimeException  When an exported manager document is malformed.
     *
     * @since   2.0.0
     */
    private function formModel(
        ExecutionContext $context,
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationScope $scope,
        string $scopeId,
        string $scopeLabel,
        string $label,
        bool $messageIds,
        string $help,
        array $fallbackWidgetIds,
        array $fallbackShortcutIds,
    ): array {
        [$widgets, $widgetVersion] = $this->exportedList(
            $context,
            $surface,
            $owner,
            $scope,
            $scopeId,
            CustomizationSlot::DashboardCards,
        );
        [$shortcuts, $shortcutVersion] = $this->exportedList(
            $context,
            $surface,
            $owner,
            $scope,
            $scopeId,
            CustomizationSlot::NavigationShortcuts,
        );
        if ($scope === CustomizationScope::User && $widgetVersion === 0) {
            $widgets = $fallbackWidgetIds;
        }
        if ($scope === CustomizationScope::User && $shortcutVersion === 0) {
            $shortcuts = $fallbackShortcutIds;
        }

        return [
            'scope' => $scope->value,
            'scope_id' => $scopeId,
            'scope_label' => $scopeLabel,
            'label' => $label,
            'message_ids' => $messageIds,
            'help' => $help,
            'selected_widget_ids' => $widgets,
            'widget_order' => self::orderMap($widgets),
            'widget_version' => $widgetVersion,
            'selected_shortcut_ids' => $shortcuts,
            'shortcut_order' => self::orderMap($shortcuts),
            'shortcut_version' => $shortcutVersion,
        ];
    }

    /**
     * Export and reassert one manager-owned exact list document.
     *
     * @param   ExecutionContext   $context  Actor whose exact-scope read is authorized.
     * @param   SurfaceId         $surface  Dashboard surface being customized.
     * @param   ContributionOwner $owner    Current surface owner.
     * @param   CustomizationScope $scope   User or role/workspace layer.
     * @param   string            $scopeId  Actor or access-group identity.
     * @param   CustomizationSlot $slot     Dashboard cards or navigation shortcuts.
     *
     * @return  array{list<string>, int}  Exact stored value and version, or empty value and zero when absent.
     *
     * @throws  AuthorizationDenied  When the actor may not inspect the exact layer.
     * @throws  RuntimeException  When the manager violates its portable export contract.
     *
     * @since   2.0.0
     */
    private function exportedList(
        ExecutionContext $context,
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationScope $scope,
        string $scopeId,
        CustomizationSlot $slot,
    ): array {
        $document = $this->preferences->export(
            $context,
            $owner,
            new PresentationPreferenceKey($surface, $slot, $scope, $scopeId),
        );
        if ($document === null) {
            return [[], 0];
        }
        $version = $document['version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new RuntimeException('A dashboard preference export contains an invalid version.');
        }
        $value = PresentationPreferenceValue::from($slot, $document['value'] ?? null)->value();
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('A dashboard preference export contains a non-list value.');
        }

        /** @var list<string> $value */
        return [$value, $version];
    }

    /**
     * Map exact stored order without consulting an effective selection-first catalog.
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
     * Resolve one exact supported mutation action.
     *
     * @param   mixed  $action  Candidate flat-form action value.
     *
     * @return  array{CustomizationSlot, bool}  Selected slot and whether the operation is a reset.
     *
     * @throws  InvalidArgumentException  When the action is absent or unsupported.
     *
     * @since   2.0.0
     */
    private static function action(mixed $action): array
    {
        return match ($action) {
            'dashboard-cards.save' => [CustomizationSlot::DashboardCards, false],
            'dashboard-cards.reset' => [CustomizationSlot::DashboardCards, true],
            'navigation-shortcuts.save' => [CustomizationSlot::NavigationShortcuts, false],
            'navigation-shortcuts.reset' => [CustomizationSlot::NavigationShortcuts, true],
            default => throw new InvalidArgumentException('The dashboard preference action is invalid.'),
        };
    }

    /**
     * Build the only preference targets supported by dashboard form delivery.
     *
     * @param   ExecutionContext      $context  Authenticated actor performing the mutation.
     * @param   SurfaceId            $surface  Exact dashboard surface.
     * @param   CustomizationSlot    $slot     Dashboard cards or navigation shortcuts.
     * @param   array<string, string> $form     Flat form carrying scope and scope identity.
     *
     * @return  PresentationPreferenceKey  Exact admitted user or role access-group key.
     *
     * @throws  InvalidArgumentException  When scope is unsupported, foreign, or malformed.
     *
     * @since   2.0.0
     */
    private static function key(
        ExecutionContext $context,
        SurfaceId $surface,
        CustomizationSlot $slot,
        array $form,
    ): PresentationPreferenceKey {
        if ($context->principal() === null) {
            throw new InvalidArgumentException('Dashboard preference mutation requires an authenticated actor.');
        }
        $scope = $form['scope'] ?? null;
        $scopeId = $form['scope_id'] ?? null;
        if ($scope === CustomizationScope::User->value) {
            if (!is_string($scopeId) || $scopeId !== $context->actorId()) {
                throw new InvalidArgumentException('A dashboard user preference may target only the actor.');
            }

            return new PresentationPreferenceKey($surface, $slot, CustomizationScope::User, $scopeId);
        }
        if ($scope !== CustomizationScope::RoleWorkspace->value) {
            throw new InvalidArgumentException('The dashboard preference scope is invalid.');
        }
        if (!is_string($scopeId) || PresentationAccessGroup::roleIdFromIdentifier($scopeId) === null) {
            throw new InvalidArgumentException('The dashboard access-group identity is invalid.');
        }

        return new PresentationPreferenceKey($surface, $slot, CustomizationScope::RoleWorkspace, $scopeId);
    }

    /**
     * Decode a canonical non-negative optimistic version without integer truncation.
     *
     * @param   mixed  $value  Candidate decimal version.
     *
     * @return  int  Canonical version including zero for row creation.
     *
     * @throws  InvalidArgumentException  When syntax or range is invalid.
     *
     * @since   2.0.0
     */
    private static function version(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException('The dashboard preference version is invalid.');
        }
        $maximum = (string) PHP_INT_MAX;
        if (
            strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
        ) {
            throw new InvalidArgumentException('The dashboard preference version is out of range.');
        }

        return (int) $value;
    }

    /**
     * Reconstruct the selected identifiers in submitted order after live-catalog validation.
     *
     * Every posted item has one positive order and indices are canonical and contiguous. Selected orders must
     * be unique, but need not themselves be contiguous: sparse values are normalized after sorting so checking
     * one item in an independently ordered access-group form remains a simple operation. Unselected catalog
     * choices may legitimately retain colliding loop positions. Only checked entries enter the bounded result.
     *
     * @param   array<string, string> $form        Flat indexed dashboard form.
     * @param   list<string>         $allowedIds  Caller-supplied current live catalog.
     * @param   int                  $maximum     KIS selected-list bound for the slot.
     *
     * @return  list<string>  Selected live identifiers in submitted one-based order.
     *
     * @throws  InvalidArgumentException  When fields, indices, identifiers, selection, or order are invalid.
     *
     * @since   2.0.0
     */
    private static function selectedIds(array $form, array $allowedIds, int $maximum): array
    {
        $allowed = self::allowlist($allowedIds);
        $items = [];
        $orders = [];
        $selected = [];
        $seenItems = [];
        foreach ($form as $field => $value) {
            if (preg_match('/^(item|selected|order)_(0|[1-9][0-9]*)$/D', $field, $match) !== 1) {
                if (preg_match('/^(?:item|selected|order)_/D', $field) === 1) {
                    throw new InvalidArgumentException('A dashboard preference item index is invalid.');
                }
                continue;
            }
            $index = self::boundedPositiveOrZero($match[2], 'item index');
            if ($match[1] === 'item') {
                if (!isset($allowed[$value])) {
                    throw new InvalidArgumentException('A dashboard preference contains an unknown identifier.');
                }
                if (isset($seenItems[$value])) {
                    throw new InvalidArgumentException('A dashboard preference identifier is duplicated.');
                }
                $items[$index] = $value;
                $seenItems[$value] = true;
                continue;
            }
            if ($match[1] === 'selected') {
                if ($value !== '1') {
                    throw new InvalidArgumentException('A dashboard preference selection flag is invalid.');
                }
                $selected[$index] = true;
                continue;
            }
            $orders[$index] = self::boundedPositive($value, 'item order');
        }
        if (count($items) > self::MAXIMUM_FORM_ITEMS) {
            throw new InvalidArgumentException('A dashboard preference form contains too many items.');
        }

        $indices = array_keys($items);
        sort($indices, SORT_NUMERIC);
        if ($indices !== ($items === [] ? [] : range(0, count($items) - 1))) {
            throw new InvalidArgumentException('Dashboard preference item indices must be contiguous.');
        }
        ksort($orders, SORT_NUMERIC);
        if (array_keys($orders) !== $indices) {
            throw new InvalidArgumentException('Every dashboard preference item requires one order.');
        }

        $result = [];
        $selectedPositions = [];
        foreach ($selected as $index => $_selected) {
            if (!isset($items[$index], $orders[$index])) {
                throw new InvalidArgumentException('A selected dashboard preference item is malformed.');
            }
            $result[$orders[$index]] = $items[$index];
            $selectedPositions[] = $orders[$index];
        }
        if (count($result) > $maximum) {
            throw new InvalidArgumentException('A dashboard preference selection exceeds the KIS limit.');
        }
        if (count(array_unique($selectedPositions, SORT_REGULAR)) !== count($selectedPositions)) {
            throw new InvalidArgumentException(
                'Selected dashboard preference item order must be unique.',
            );
        }
        ksort($result, SORT_NUMERIC);

        return array_values($result);
    }

    /**
     * Validate and index the caller's live semantic catalog.
     *
     * @param   list<string>  $identifiers  Current capability-filtered identifiers.
     *
     * @return  array<string, true>  Unique exact lookup.
     *
     * @throws  InvalidArgumentException  When the list is unbounded, malformed, or duplicated.
     *
     * @since   2.0.0
     */
    private static function allowlist(array $identifiers): array
    {
        if (!array_is_list($identifiers) || count($identifiers) > self::MAXIMUM_FORM_ITEMS) {
            throw new InvalidArgumentException('A dashboard preference allowlist is malformed or unbounded.');
        }
        $allowed = [];
        foreach ($identifiers as $identifier) {
            if (!is_string($identifier) || isset($allowed[$identifier])) {
                throw new InvalidArgumentException('A dashboard preference allowlist contains an invalid item.');
            }
            try {
                SurfaceId::fromString($identifier);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException(
                    'A dashboard preference allowlist contains an invalid item.',
                    0,
                    $exception,
                );
            }
            $allowed[$identifier] = true;
        }

        return $allowed;
    }

    /**
     * Validate one effective personal fallback against the same semantic and KIS list limits as mutations.
     *
     * @param   list<string>  $identifiers  Effective identifiers rendered by the dashboard composer.
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
        self::allowlist($identifiers);
        if (count($identifiers) > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'The dashboard preference %s fallback exceeds the KIS limit.',
                $kind,
            ));
        }
    }

    /**
     * Decode one zero-based bounded index.
     *
     * @param   string $value  Canonical decimal string.
     * @param   string $field  Field name used in a stable error.
     *
     * @return  int  Value between zero and the form-item bound.
     *
     * @throws  InvalidArgumentException  When the number exceeds the bound.
     *
     * @since   2.0.0
     */
    private static function boundedPositiveOrZero(string $value, string $field): int
    {
        $number = self::version($value);
        if ($number >= self::MAXIMUM_FORM_ITEMS) {
            throw new InvalidArgumentException(sprintf('The dashboard preference %s is out of range.', $field));
        }

        return $number;
    }

    /**
     * Decode one one-based bounded order.
     *
     * @param   string $value  Candidate canonical decimal string.
     * @param   string $field  Field name used in a stable error.
     *
     * @return  int  Value between one and the form-item bound.
     *
     * @throws  InvalidArgumentException  When syntax or range is invalid.
     *
     * @since   2.0.0
     */
    private static function boundedPositive(string $value, string $field): int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The dashboard preference %s is invalid.', $field));
        }
        $number = self::version($value);
        if ($number > self::MAXIMUM_FORM_ITEMS) {
            throw new InvalidArgumentException(sprintf('The dashboard preference %s is out of range.', $field));
        }

        return $number;
    }

    /**
     * Reject non-flat values even when a non-HTTP caller bypasses the canonical request reader.
     *
     * @param   array<array-key, mixed>  $form  Candidate form.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a key or value is not a string.
     *
     * @since   2.0.0
     */
    private static function assertStringForm(array $form): void
    {
        foreach ($form as $field => $value) {
            if (!is_string($field) || !is_string($value)) {
                throw new InvalidArgumentException('A dashboard preference form must be flat strings.');
            }
        }
    }

    /**
     * Restrict the service to the two actor-facing dashboard areas.
     *
     * @param   SurfaceArea  $area  Candidate delivery area.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When public or template delivery is requested.
     *
     * @since   2.0.0
     */
    private static function assertArea(SurfaceArea $area): void
    {
        if (!in_array($area, [SurfaceArea::Administrator, SurfaceArea::Portal], true)) {
            throw new InvalidArgumentException(
                'Dashboard preference delivery is available only to administrator and portal areas.',
            );
        }
    }
}
