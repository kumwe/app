<?php

declare(strict_types=1);

namespace Kumwe\CMS\InterfaceStandard;

use InvalidArgumentException;
use JsonException;

/**
 * One normalized value from the closed KIS presentation-preference vocabulary.
 *
 * The selected customization slot determines the only shape this value may carry. Arbitrary markup,
 * code, selectors, URLs, component names, and policy instructions have no admitted representation, so
 * a persisted value can be handed to a renderer without becoming an executable customization channel.
 *
 * @since  2.0.0
 */
final readonly class PresentationPreferenceValue
{
    /**
     * Hold a value that the slot-specific validator has normalized.
     *
     * @param  mixed  $value  Canonical scalar, list, or bounded object for the selected slot.
     *
     * @since  2.0.0
     */
    private function __construct(private mixed $value)
    {
    }

    /**
     * Validate and normalize one customization value according to its slot.
     *
     * @param   CustomizationSlot  $slot   Closed presentation choice whose value is being admitted.
     * @param   mixed              $value  Candidate decoded from a request, import, or database row.
     *
     * @return  self  Immutable, JSON-compatible value safe for that slot.
     *
     * @throws  InvalidArgumentException  When the value falls outside the slot's bounded vocabulary.
     *
     * @since   2.0.0
     */
    public static function from(CustomizationSlot $slot, mixed $value): self
    {
        $normalized = match ($slot) {
            CustomizationSlot::Columns,
            CustomizationSlot::DashboardCards => self::semanticNameList($value, 64),
            CustomizationSlot::Density => self::choice($value, ['comfortable', 'compact', 'touch'], $slot),
            CustomizationSlot::SavedViews => self::savedView($value),
            CustomizationSlot::Layout => self::choice(
                $value,
                ['standard', 'catalog-wide', 'detail-wide'],
                $slot,
            ),
            CustomizationSlot::ThemeMode => self::choice($value, ['light', 'dark', 'system'], $slot),
            CustomizationSlot::LandingWorkspace => self::dottedName($value),
            CustomizationSlot::NavigationShortcuts => self::dottedNameList($value, 32),
            CustomizationSlot::LabelsHelp => self::labelsAndHelp($value),
        };

        try {
            json_encode($normalized, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('A KIS presentation preference must be JSON-compatible.', 0, $exception);
        }

        return new self($normalized);
    }

    /**
     * Return the normalized scalar, list, or object representation.
     *
     * @return  mixed  Canonical value admitted for the selected slot.
     *
     * @since   2.0.0
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * Encode the canonical value for a JSON persistence column or portable export.
     *
     * @return  string  Lossless JSON representation of the admitted value.
     *
     * @since   2.0.0
     */
    public function toJson(): string
    {
        return json_encode($this->value, JSON_THROW_ON_ERROR);
    }

    /**
     * Admit one literal from a closed slot vocabulary.
     *
     * @param   mixed              $value    Candidate scalar value.
     * @param   list<string>       $allowed  Exact values the slot admits.
     * @param   CustomizationSlot  $slot     Slot used in a deterministic failure message.
     *
     * @return  string  Admitted literal.
     *
     * @throws  InvalidArgumentException  When the candidate is not one of the allowed strings.
     *
     * @since   2.0.0
     */
    private static function choice(mixed $value, array $allowed, CustomizationSlot $slot): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'The KIS %s preference contains an unsupported value.',
                $slot->value,
            ));
        }

        return $value;
    }

    /**
     * Normalize the bounded saved-view document.
     *
     * @param   mixed  $value  Candidate saved-view object.
     *
     * @return  array{name: string, sort: list<string>, page_size: int}  Canonical saved-view value.
     *
     * @throws  InvalidArgumentException  When fields, sort names, or page size violate the schema.
     *
     * @since   2.0.0
     */
    private static function savedView(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('A KIS saved-views preference must be an object.');
        }
        self::assertExactKeys($value, ['name', 'page_size', 'sort'], 'saved-views');
        $name = $value['name'];
        $pageSize = $value['page_size'];
        if (!is_string($name)) {
            throw new InvalidArgumentException('A KIS saved view name must be plain text.');
        }
        self::plainText($name, 'saved view name');
        if (!is_int($pageSize) || $pageSize < 10 || $pageSize > 200) {
            throw new InvalidArgumentException('A KIS saved view page size must be between 10 and 200.');
        }

        return [
            'name' => $name,
            'sort' => self::semanticNameList($value['sort'], 64),
            'page_size' => $pageSize,
        ];
    }

    /**
     * Normalize a list of unique semantic field or card names.
     *
     * @param   mixed  $value    Candidate list.
     * @param   int    $maximum  Maximum entries admitted by the selected schema definition.
     *
     * @return  list<string>  Original deterministic order with every item validated.
     *
     * @throws  InvalidArgumentException  When the value is not a bounded unique semantic-name list.
     *
     * @since   2.0.0
     */
    private static function semanticNameList(mixed $value, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new InvalidArgumentException('A KIS preference must contain a bounded list of semantic names.');
        }
        $seen = [];
        foreach ($value as $item) {
            if (!is_string($item) || !self::isSemanticName($item) || isset($seen[$item])) {
                throw new InvalidArgumentException(
                    'A KIS preference semantic-name list contains an invalid or duplicate item.',
                );
            }
            $seen[$item] = true;
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * Normalize a list of unique dotted surface or workspace names.
     *
     * @param   mixed  $value    Candidate list.
     * @param   int    $maximum  Maximum entries admitted by the selected schema definition.
     *
     * @return  list<string>  Original deterministic order with every item validated.
     *
     * @throws  InvalidArgumentException  When the value is not a bounded unique dotted-name list.
     *
     * @since   2.0.0
     */
    private static function dottedNameList(mixed $value, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new InvalidArgumentException('A KIS preference must contain a bounded list of dotted names.');
        }
        $seen = [];
        $normalized = [];
        foreach ($value as $item) {
            $name = self::dottedName($item);
            if (isset($seen[$name])) {
                throw new InvalidArgumentException('A KIS preference dotted-name list contains a duplicate item.');
            }
            $seen[$name] = true;
            $normalized[] = $name;
        }

        return $normalized;
    }

    /**
     * Normalize administrator-owned label and help overrides.
     *
     * @param   mixed  $value  Candidate semantic-name to plain-text map.
     *
     * @return  array<string, string>  Deterministically keyed safe translation overrides.
     *
     * @throws  InvalidArgumentException  When keys, values, or the property count violate the schema.
     *
     * @since   2.0.0
     */
    private static function labelsAndHelp(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value) || count($value) < 1 || count($value) > 64) {
            throw new InvalidArgumentException('A KIS labels-help preference must contain 1 to 64 entries.');
        }
        foreach ($value as $key => $text) {
            if (!is_string($key) || !self::isSemanticName($key) || !is_string($text)) {
                throw new InvalidArgumentException('A KIS labels-help preference contains an invalid entry.');
            }
            self::plainText($text, 'label or help text');
        }
        ksort($value, SORT_STRING);

        /** @var array<string, string> $value */
        return $value;
    }

    /**
     * Validate one dotted KIS identifier.
     *
     * @param   mixed  $value  Candidate workspace or surface name.
     *
     * @return  string  Validated lowercase dotted identifier.
     *
     * @throws  InvalidArgumentException  When the value is not a dotted KIS name.
     *
     * @since   2.0.0
     */
    private static function dottedName(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('A KIS dotted-name preference must be a string.');
        }
        SurfaceId::fromString($value);

        return $value;
    }

    /**
     * Determine whether a value matches the preference schema's semantic-name definition.
     *
     * @param   string  $value  Candidate column, card, label, or sort identifier.
     *
     * @return  bool  True only for a bounded lowercase semantic name.
     *
     * @since   2.0.0
     */
    private static function isSemanticName(string $value): bool
    {
        return mb_strlen($value) <= 191
            && preg_match('/^[a-z][a-z0-9]*(?:(?:\.|-)[a-z0-9]+)*$/D', $value) === 1;
    }

    /**
     * Reject markup-shaped, empty, control-bearing, or overlong operator text.
     *
     * @param   string  $value  Candidate user-facing text.
     * @param   string  $field  Human name used in the failure message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value violates the schema's plain-text definition.
     *
     * @since   2.0.0
     */
    private static function plainText(string $value, string $field): void
    {
        if (
            mb_strlen($value) < 1
            || mb_strlen($value) > 255
            || preg_match('/[<>{}\x00-\x1F\x7F]/u', $value) === 1
        ) {
            throw new InvalidArgumentException(sprintf('A KIS %s must be bounded plain text.', $field));
        }
    }

    /**
     * Require an object to carry exactly the schema's named fields.
     *
     * @param   array<string, mixed>  $value     Candidate object.
     * @param   list<string>          $expected  Complete sorted field list.
     * @param   string                $field     Object name used in the failure message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a required field is absent or an unknown field is present.
     *
     * @since   2.0.0
     */
    private static function assertExactKeys(array $value, array $expected, string $field): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException(sprintf('A KIS %s preference has unknown or missing fields.', $field));
        }
    }
}
