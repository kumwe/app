<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Dashboard;

use InvalidArgumentException;
use Kumwe\CMS\Application\Presentation\Dashboard\DashboardPreferenceMutation;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;

/**
 * Translates one flat browser form into the typed dashboard preference application command.
 *
 * The decoder owns protocol field names and indexed checkbox reconstruction. It never authorizes a target
 * or trusts submitted identifiers as live; those decisions remain in `DashboardPreferenceService`.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceFormDecoder
{
    /**
     * Largest live catalogue one flat dashboard form may describe.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAXIMUM_FORM_ITEMS = 256;

    /**
     * Decode one save or reset form without depending on an HTTP implementation.
     *
     * @param   array<array-key, mixed>  $form  Candidate flat dashboard preference fields.
     *
     * @return  DashboardPreferenceMutation  Typed protocol-neutral application command.
     *
     * @throws  InvalidArgumentException  When action, target, version, items, selection, or order is invalid.
     *
     * @since   2.0.0
     */
    public function decode(array $form): DashboardPreferenceMutation
    {
        $form = self::stringForm($form);
        [$slot, $reset] = self::action($form['action'] ?? null);
        $scope = match ($form['scope'] ?? null) {
            CustomizationScope::User->value => CustomizationScope::User,
            CustomizationScope::RoleWorkspace->value => CustomizationScope::RoleWorkspace,
            default => throw new InvalidArgumentException('The dashboard preference scope is invalid.'),
        };
        $scopeId = $form['scope_id'] ?? null;
        if (!is_string($scopeId)) {
            throw new InvalidArgumentException('The dashboard preference scope identity is invalid.');
        }
        $expectedVersion = self::version($form['expected_version'] ?? null);
        if ($reset) {
            return new DashboardPreferenceMutation($slot, $scope, $scopeId, $expectedVersion, true, [], []);
        }
        [$submittedIds, $selectedIds] = self::selections($form);

        return new DashboardPreferenceMutation(
            $slot,
            $scope,
            $scopeId,
            $expectedVersion,
            false,
            $submittedIds,
            $selectedIds,
        );
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
     * Reconstruct submitted and selected identifiers from indexed browser fields.
     *
     * @param   array<string, string>  $form  Validated flat string form.
     *
     * @return  array{list<string>, list<string>}  Catalogue order followed by checked display order.
     *
     * @throws  InvalidArgumentException  When indices, identifiers, selection, or ordering are ambiguous.
     *
     * @since   2.0.0
     */
    private static function selections(array $form): array
    {
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
        ksort($items, SORT_NUMERIC);
        ksort($orders, SORT_NUMERIC);
        if (array_keys($orders) !== $indices) {
            throw new InvalidArgumentException('Every dashboard preference item requires one order.');
        }

        $result = [];
        $positions = [];
        foreach ($selected as $index => $_selected) {
            if (!isset($items[$index], $orders[$index])) {
                throw new InvalidArgumentException('A selected dashboard preference item is malformed.');
            }
            $result[$orders[$index]] = $items[$index];
            $positions[] = $orders[$index];
        }
        if (count(array_unique($positions, SORT_REGULAR)) !== count($positions)) {
            throw new InvalidArgumentException('Selected dashboard preference item order must be unique.');
        }
        ksort($result, SORT_NUMERIC);

        return [array_values($items), array_values($result)];
    }

    /**
     * Decode one zero-based bounded index.
     *
     * @param   string  $value  Canonical decimal string.
     * @param   string  $field  Field name used in a stable error.
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
     * @param   string  $value  Candidate canonical decimal string.
     * @param   string  $field  Field name used in a stable error.
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
     * Reject non-flat values even when a caller bypasses the canonical request reader.
     *
     * @param   array<array-key, mixed>  $form  Candidate form.
     *
     * @return  array<string, string>  Validated flat form preserving every field.
     *
     * @throws  InvalidArgumentException  When a key or value is not a string.
     *
     * @since   2.0.0
     */
    private static function stringForm(array $form): array
    {
        foreach ($form as $field => $value) {
            if (!is_string($field) || !is_string($value)) {
                throw new InvalidArgumentException('A dashboard preference form must be flat strings.');
            }
        }

        return $form;
    }
}
