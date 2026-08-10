<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Application;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresentation;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldWidget;

/**
 * Maps browser form input through the authorized field presentation rather than mass assignment.
 *
 * The mapper accepts only handles present and editable in the server-produced form schema. Composite
 * values keep their nested shape, exact numbers stay strings, booleans and integers are narrowly parsed,
 * and JSON text areas are decoded with explicit depth and byte limits. Unknown, read-only, computed,
 * server-only and conditionally hidden handles are rejected before a command reaches the record service.
 *
 * @since  2.0.0
 */
final readonly class BusinessFormInputMapper
{
    /**
     * Maximum canonical payload size accepted from a generated browser form.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_BYTES = 1_048_576;

    /**
     * Map one decoded form values object against its authorized presentation list.
     *
     * @param   array<string, mixed>     $values  Parsed values under field handles.
     * @param   list<FieldPresentation>  $fields  Server-produced fields for this exact actor and record.
     *
     * @return  array<string, mixed>  Typed patch safe to hand to a business-record command.
     *
     * @throws  InvalidArgumentException  When input is unbounded, unknown, read-only, or has the wrong shape.
     * @throws  JsonException  When a structured text editor carries invalid JSON.
     *
     * @since   2.0.0
     */
    public function map(array $values, array $fields): array
    {
        if (count($values) > 256) {
            throw new InvalidArgumentException('A generated business form exceeds its field bound.');
        }
        $encoded = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new InvalidArgumentException('A generated business form exceeds one mebibyte.');
        }
        $allowed = [];
        foreach ($fields as $field) {
            if (!$field instanceof FieldPresentation) {
                throw new InvalidArgumentException('A generated business form schema is invalid.');
            }
            if ($field->editable) {
                $allowed[$field->handle] = $field;
            }
        }
        if (array_diff(array_keys($values), array_keys($allowed)) !== []) {
            throw new InvalidArgumentException('A generated business form contains an unavailable field.');
        }

        $mapped = [];
        foreach ($values as $handle => $value) {
            $field = $allowed[$handle];
            $normal = $this->value($field, $value);
            if ($field->widget === FieldWidget::Secret && $normal === '') {
                continue;
            }
            $mapped[$handle] = $normal;
        }

        return $mapped;
    }

    /**
     * Map input against field models already exported by `BusinessSurfaceService::form()`.
     *
     * Browser controllers receive arrays rather than strategy objects so the same model can go to Twig.
     * This entry point revalidates every relevant member and then applies the identical coercion rules as
     * `map()`; a caller cannot invent an editable field model because the controller obtains the list from
     * the shared service immediately before calling this method.
     *
     * @param   array<string, mixed>        $values  Parsed values under field handles.
     * @param   list<array<string, mixed>>  $fields  Exported server-produced semantic field models.
     *
     * @return  array<string, mixed>  Typed patch safe for a business-record command.
     *
     * @throws  InvalidArgumentException  When a model is malformed or an input handle is unavailable.
     * @throws  JsonException  When structured input carries malformed JSON.
     *
     * @since   2.0.0
     */
    public function mapSurface(array $values, array $fields): array
    {
        $models = [];
        foreach ($fields as $field) {
            $widget = isset($field['widget']) && is_string($field['widget'])
                ? FieldWidget::tryFrom($field['widget'])
                : null;
            $handle = $field['handle'] ?? null;
            if (
                !is_string($handle) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1
                || $widget === null || !is_bool($field['editable'] ?? null)
                || !is_bool($field['required'] ?? null)
            ) {
                throw new InvalidArgumentException('A generated business form model is invalid.');
            }
            $models[$handle] = [
                'widget' => $widget,
                'editable' => $field['editable'],
                'required' => $field['required'],
            ];
        }
        if (count($values) > 256 || array_diff(array_keys($values), array_keys($models)) !== []) {
            throw new InvalidArgumentException('A generated business form contains an unavailable field.');
        }
        $encoded = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new InvalidArgumentException('A generated business form exceeds one mebibyte.');
        }
        $mapped = [];
        foreach ($values as $handle => $value) {
            $model = $models[$handle];
            if (!$model['editable']) {
                throw new InvalidArgumentException('A read-only business field cannot be submitted.');
            }
            $normal = $this->surfaceValue($model['widget'], $model['required'], $value);
            if ($model['widget'] === FieldWidget::Secret && $normal === '') {
                continue;
            }
            $mapped[$handle] = $normal;
        }

        return $mapped;
    }

    /**
     * Normalize one browser representation according to its semantic widget.
     *
     * @param   FieldPresentation  $field  Authorized field presentation.
     * @param   mixed              $value  Parsed browser value.
     *
     * @return  mixed  Narrow typed value expected by the record value codec.
     *
     * @throws  InvalidArgumentException  When the value contradicts its widget.
     * @throws  JsonException  When structured JSON text is malformed.
     *
     * @since   2.0.0
     */
    private function value(FieldPresentation $field, mixed $value): mixed
    {
        if ($value === '' && !$field->required && $field->widget !== FieldWidget::Secret) {
            return null;
        }

        return match ($field->widget) {
            FieldWidget::Integer => $this->integer($value),
            FieldWidget::Checkbox => $this->boolean($value),
            FieldWidget::Money => $this->composite($value, 'currency'),
            FieldWidget::Quantity => $this->composite($value, 'unit'),
            FieldWidget::ZonedDateTime => $this->zoned($value),
            FieldWidget::Json, FieldWidget::Collection => $this->structured($value),
            FieldWidget::Output => throw new InvalidArgumentException(
                'A read-only business field cannot be submitted.',
            ),
            default => $this->string($value),
        };
    }

    /**
     * Normalize a value from an exported field model.
     *
     * @param   FieldWidget  $widget    Validated semantic widget.
     * @param   bool         $required  Whether an empty value is permitted.
     * @param   mixed        $value     Parsed browser value.
     *
     * @return  mixed  Narrow typed value.
     *
     * @throws  InvalidArgumentException  When the value contradicts the widget.
     * @throws  JsonException  When structured JSON text is malformed.
     *
     * @since   2.0.0
     */
    private function surfaceValue(FieldWidget $widget, bool $required, mixed $value): mixed
    {
        if ($value === '' && !$required && $widget !== FieldWidget::Secret) {
            return null;
        }

        return match ($widget) {
            FieldWidget::Integer => $this->integer($value),
            FieldWidget::Checkbox => $this->boolean($value),
            FieldWidget::Money => $this->composite($value, 'currency'),
            FieldWidget::Quantity => $this->composite($value, 'unit'),
            FieldWidget::ZonedDateTime => $this->zoned($value),
            FieldWidget::Json, FieldWidget::Collection => $this->structured($value),
            FieldWidget::Output => throw new InvalidArgumentException(
                'A read-only business field cannot be submitted.',
            ),
            default => $this->string($value),
        };
    }

    /**
     * Parse a canonical signed integer without PHP's permissive numeric coercion.
     *
     * @param   mixed  $value  Browser value.
     *
     * @return  int  Parsed platform integer.
     *
     * @throws  InvalidArgumentException  When malformed or outside platform integer range.
     *
     * @since   2.0.0
     */
    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException('A generated integer field is invalid.');
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($parsed)) {
            throw new InvalidArgumentException('A generated integer field is outside the supported range.');
        }

        return $parsed;
    }

    /**
     * Parse the closed browser boolean representations.
     *
     * @param   mixed  $value  Browser value.
     *
     * @return  bool  Parsed boolean.
     *
     * @throws  InvalidArgumentException  When no supported representation matches.
     *
     * @since   2.0.0
     */
    private function boolean(mixed $value): bool
    {
        return match ($value) {
            true, '1', 1 => true,
            false, '0', 0 => false,
            default => throw new InvalidArgumentException('A generated boolean field is invalid.'),
        };
    }

    /**
     * Parse an exact amount paired with a currency or unit.
     *
     * @param   mixed   $value      Browser value.
     * @param   string  $qualifier  `currency` or `unit`.
     *
     * @return  array<string, string>  Closed exact composite.
     *
     * @throws  InvalidArgumentException  When shape or members are invalid.
     *
     * @since   2.0.0
     */
    private function composite(mixed $value, string $qualifier): array
    {
        if (
            !is_array($value) || array_is_list($value)
            || array_diff(array_keys($value), ['amount', $qualifier]) !== []
            || !is_string($value['amount'] ?? null)
            || preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value['amount']) !== 1
            || !is_string($value[$qualifier] ?? null)
            || $value[$qualifier] === '' || strlen($value[$qualifier]) > 32
        ) {
            throw new InvalidArgumentException('A generated exact composite field is invalid.');
        }

        return ['amount' => $value['amount'], $qualifier => $value[$qualifier]];
    }

    /**
     * Parse a zoned date-time composite without browser timezone inference.
     *
     * @param   mixed  $value  Browser value.
     *
     * @return  array{instant: string, timezone: string}  Closed composite.
     *
     * @throws  InvalidArgumentException  When shape or members are invalid.
     *
     * @since   2.0.0
     */
    private function zoned(mixed $value): array
    {
        if (
            !is_array($value) || array_is_list($value)
            || array_diff(array_keys($value), ['instant', 'timezone']) !== []
            || !is_string($value['instant'] ?? null) || $value['instant'] === ''
            || !is_string($value['timezone'] ?? null)
            || preg_match('/^[A-Za-z_+-]+(?:\/[A-Za-z0-9_+-]+){1,2}$/D', $value['timezone']) !== 1
        ) {
            throw new InvalidArgumentException('A generated zoned date-time field is invalid.');
        }

        return ['instant' => $value['instant'], 'timezone' => $value['timezone']];
    }

    /**
     * Decode a bounded structured text area or preserve an already parsed array.
     *
     * @param   mixed  $value  JSON string or parsed array.
     *
     * @return  array<mixed>  Parsed bounded structure.
     *
     * @throws  InvalidArgumentException  When the value is neither string nor array.
     * @throws  JsonException  When JSON is malformed or deeper than 16 levels.
     *
     * @since   2.0.0
     */
    private function structured(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || strlen($value) > self::MAX_BYTES) {
            throw new InvalidArgumentException('A generated structured field is invalid or unbounded.');
        }
        $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('A generated structured field must decode to an array or object.');
        }

        return $decoded;
    }

    /**
     * Require a scalar text value without coercing arrays or objects.
     *
     * @param   mixed  $value  Browser value.
     *
     * @return  string  Original string.
     *
     * @throws  InvalidArgumentException  When not a bounded string.
     *
     * @since   2.0.0
     */
    private function string(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > self::MAX_BYTES) {
            throw new InvalidArgumentException('A generated text field is invalid or unbounded.');
        }

        return $value;
    }
}
