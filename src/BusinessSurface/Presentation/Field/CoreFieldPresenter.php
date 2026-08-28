<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Presentation\Field;

use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\Conversion\Value\ConvertedMoneyValue;
use Kumwe\Conversion\Decimal\ExactDecimal;
use Kumwe\Conversion\Value\MoneyValue;
use Kumwe\Conversion\Value\QuantityValue;
use Kumwe\App\BusinessRecord\Domain\ZonedDateTimeValue;

/**
 * Semantic presenter for the complete core business field-type catalogue.
 *
 * Exact decimals are kept as strings, composite values are formatted from their string members, and a
 * secret is represented only as an empty write-only editor. Read-only, computed and server-only fields
 * always use output widgets even when a caller asks for an edit context. Structured values remain typed
 * for retained form input and are rendered through canonical JSON, never through executable markup.
 *
 * A converted amount is recognised before the field's own type is consulted, because it is not a value
 * of that type: it is a presentation of one. It renders as read-only output whose text is the whole of
 * its provenance, and its structured evidence travels beside it for surfaces that can lay it out. No
 * editor is ever offered for one, which is the presentation-side half of the rule that no write path
 * accepts a converted amount as a stored value.
 *
 * @since  2.0.0
 */
final readonly class CoreFieldPresenter implements FieldPresenter
{
    /**
     * Present one core field using an allow-listed semantic widget.
     *
     * @param   FieldPresentationRequest  $request  Validated declarative field presentation input.
     *
     * @return  FieldPresentation  Markup-free field view model.
     *
     * @throws  InvalidBusinessDefinition  When a structured disclosed value cannot be canonically encoded.
     * @throws  \InvalidArgumentException  When the resulting semantic model is malformed or unbounded, or
     *          when a value marked as converted cannot prove the conversion it claims.
     *
     * @since   2.0.0
     */
    public function present(FieldPresentationRequest $request): FieldPresentation
    {
        $field = $request->field;
        $converted = ConvertedMoneyValue::detect($request->value);
        if ($converted !== null) {
            return new FieldPresentation(
                $field->handle,
                $field->label,
                $request->context,
                FieldWidget::Output,
                $converted->toPortableString(),
                null,
                false,
                $field->required,
                $request->errors,
                [],
                [],
                $converted->toArray(),
            );
        }
        $editing = $request->permitsEditing();
        $secret = $field->type === 'core.secret';
        $widget = $editing ? $this->widget($field->type) : FieldWidget::Output;
        if ($secret && $editing) {
            $widget = FieldWidget::Secret;
        }

        return new FieldPresentation(
            $field->handle,
            $field->label,
            $request->context,
            $widget,
            $secret ? '' : $this->display($request->value, $field->type, $request->locale),
            $secret ? null : $this->input($request->value, $field->type),
            $editing,
            $field->required,
            $request->errors,
            $this->options($request),
            $this->attributes($request),
        );
    }

    /**
     * Choose the core widget for one exact type.
     *
     * @param   string  $type  Core field-type identifier.
     *
     * @return  FieldWidget  Semantic editor widget.
     *
     * @since   2.0.0
     */
    private function widget(string $type): FieldWidget
    {
        return match ($type) {
            'core.rich_text' => FieldWidget::Textarea,
            'core.integer' => FieldWidget::Integer,
            'core.decimal' => FieldWidget::Decimal,
            'core.boolean' => FieldWidget::Checkbox,
            'core.enum' => FieldWidget::Select,
            'core.date' => FieldWidget::Date,
            'core.local_time' => FieldWidget::Time,
            'core.instant' => FieldWidget::DateTime,
            'core.email' => FieldWidget::Email,
            'core.url' => FieldWidget::Url,
            'core.phone' => FieldWidget::Phone,
            'core.money' => FieldWidget::Money,
            'core.quantity' => FieldWidget::Quantity,
            'core.zoned_datetime' => FieldWidget::ZonedDateTime,
            'core.media_reference' => FieldWidget::MediaReference,
            'core.entity_reference' => FieldWidget::EntityReference,
            'core.embedded_value', 'core.bounded_json' => FieldWidget::Json,
            'core.ordered_lines' => FieldWidget::Collection,
            'core.secret' => FieldWidget::Secret,
            default => FieldWidget::Text,
        };
    }

    /**
     * Format a disclosed typed value without converting an exact number to binary floating point.
     *
     * @param   mixed   $value   Typed application value.
     * @param   string  $type    Core field-type identifier.
     * @param   string  $locale  Locale hint used only for exact grouping punctuation.
     *
     * @return  string  Human-readable escaped text.
     *
     * @throws  InvalidBusinessDefinition  When a structured value cannot be encoded.
     *
     * @since   2.0.0
     */
    private function display(mixed $value, string $type, string $locale): string
    {
        if ($value === null) {
            return '';
        }
        if ($type === 'core.boolean' && is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if ($type === 'core.decimal' && ($value instanceof ExactDecimal || is_string($value))) {
            return $this->exact($value instanceof ExactDecimal ? $value->value() : $value, $locale);
        }
        if ($type === 'core.money' && ($value instanceof MoneyValue || is_array($value))) {
            $money = $value instanceof MoneyValue ? $value->toArray() : $value;
            return $this->exact($this->text($money['amount'] ?? ''), $locale)
                . ' ' . $this->text($money['currency'] ?? '');
        }
        if ($type === 'core.quantity' && ($value instanceof QuantityValue || is_array($value))) {
            $quantity = $value instanceof QuantityValue ? $value->toArray() : $value;
            return $this->exact($this->text($quantity['amount'] ?? ''), $locale)
                . ' ' . $this->text($quantity['unit'] ?? '');
        }
        if ($type === 'core.zoned_datetime' && $value instanceof ZonedDateTimeValue) {
            return $value->toArray()['instant'] . ' · ' . $value->timezone;
        }
        if ($value instanceof DateTimeImmutable) {
            return match ($type) {
                'core.date' => $value->format('Y-m-d'),
                'core.local_time' => $value->format('H:i:s.u'),
                default => $value->format('Y-m-d\TH:i:s.u\Z'),
            };
        }
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }

        return CanonicalDefinitionJson::encode($value);
    }

    /**
     * Convert normalized domain values into the exact wire shape native editors submit.
     *
     * @param   mixed   $value  Normalized application value.
     * @param   string  $type   Exact field-type identifier.
     *
     * @return  mixed  Scalar, list or closed object accepted by the form input mapper.
     *
     * @since   2.0.0
     */
    private function input(mixed $value, string $type): mixed
    {
        return match (true) {
            $value instanceof ExactDecimal => $value->value(),
            $value instanceof MoneyValue,
            $value instanceof QuantityValue,
            $value instanceof ZonedDateTimeValue => $value->toArray(),
            $value instanceof DateTimeImmutable && $type === 'core.date' => $value->format('Y-m-d'),
            $value instanceof DateTimeImmutable && $type === 'core.local_time' => $value->format('H:i:s.u'),
            $value instanceof DateTimeImmutable => $value->format('Y-m-d\TH:i:s.u\Z'),
            default => $value,
        };
    }

    /**
     * Add locale-appropriate digit grouping to an exact decimal string.
     *
     * @param   string  $value   Exact decimal wire value.
     * @param   string  $locale  Locale hint.
     *
     * @return  string  Grouped value with the original digits and scale unchanged.
     *
     * @since   2.0.0
     */
    private function exact(string $value, string $locale): string
    {
        if (preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/D', $value, $parts) !== 1) {
            return $value;
        }
        $commaDecimal = preg_match('/^(de|fr|es|pt|it|nl)(?:[-_]|$)/i', $locale) === 1;
        $groups = strrev(implode($commaDecimal ? '.' : ',', str_split(strrev($parts[2]), 3)));
        $fraction = ($parts[3] ?? '') === '' ? '' : ($commaDecimal ? ',' : '.') . $parts[3];

        return $parts[1] . $groups . $fraction;
    }

    /**
     * Map enum configuration onto safe selector options.
     *
     * @param   FieldPresentationRequest  $request  Presentation input carrying field configuration.
     *
     * @return  list<array{value: string, label: string}>  Bounded options, empty for non-enums.
     *
     * @since   2.0.0
     */
    private function options(FieldPresentationRequest $request): array
    {
        if ($request->field->type !== 'core.enum') {
            return [];
        }
        $declared = $request->field->configuration['options'] ?? [];
        if (!is_array($declared) || !array_is_list($declared)) {
            throw new \InvalidArgumentException('A generated enum field has invalid presentation options.');
        }
        $options = [];
        foreach ($declared as $option) {
            if (!is_string($option)) {
                throw new \InvalidArgumentException('A generated enum field has invalid presentation options.');
            }
            $value = $option;
            $options[] = ['value' => $value, 'label' => ucfirst(str_replace('_', ' ', $value))];
        }

        return $options;
    }

    /**
     * Read one normalized composite string member without casting arbitrary values.
     *
     * @param   mixed  $value  Money or quantity member.
     *
     * @return  string  Exact normalized text.
     *
     * @throws  \InvalidArgumentException  When a composite member is not a normalized scalar.
     *
     * @since   2.0.0
     */
    private function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('A generated composite field value is malformed.');
    }

    /**
     * Carry declarative input bounds to core templates.
     *
     * @param   FieldPresentationRequest  $request  Presentation input carrying length and precision.
     *
     * @return  array<string, int|string|bool>  Allow-listed widget attributes.
     *
     * @since   2.0.0
     */
    private function attributes(FieldPresentationRequest $request): array
    {
        $attributes = [];
        if ($request->field->length !== null) {
            $attributes['maxlength'] = $request->field->length;
        }
        if ($request->field->type === 'core.integer') {
            $attributes['step'] = '1';
            $attributes['inputmode'] = 'numeric';
        }
        if ($request->field->type === 'core.decimal') {
            $attributes['inputmode'] = 'decimal';
        }
        if ($request->field->type === 'core.rich_text') {
            $attributes['rows'] = 8;
        }

        return $attributes;
    }
}
