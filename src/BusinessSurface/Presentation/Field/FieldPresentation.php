<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Presentation\Field;

use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;

/**
 * Escaped-view-model request returned by core or extension field strategies.
 *
 * A strategy cannot return markup or a Twig path. It chooses one core-owned semantic widget and supplies
 * bounded data which Twig auto-escapes, keeping extension renderers useful without making them an HTML or
 * template-inclusion escape hatch.
 *
 * @since  2.0.0
 */
final readonly class FieldPresentation
{
    /**
     * Maximum canonical bytes a retained editor value may expose to a generated form.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_INPUT_BYTES = 1_048_576;

    /**
     * Capture one semantic field view model.
     *
     * @param   string                                     $handle      Stable field handle used in labels and names.
     * @param   string                                     $label       Operator-facing label.
     * @param   FieldPresentationContext                   $context     Exact presentation context.
     * @param   FieldWidget                                $widget      Core-owned widget to render.
     * @param   string                                     $display     Escaped text for read contexts.
     * @param   mixed                                      $inputValue  Typed retained input; always null for secrets.
     * @param   bool                                       $editable    Whether an editor may be enabled.
     * @param   bool                                       $required    Whether empty input is invalid.
     * @param   list<string>                               $errors      Field-level caller-visible errors.
     * @param   list<array{value: string, label: string}>  $options     Closed choice options.
     * @param   array<string, int|string|bool>             $attributes  Allow-listed bounds for the core widget.
     *
     * @throws  InvalidArgumentException  When identity, labels, widget state, input size, errors, options, or
     *          attributes are malformed or unbounded.
     * @throws  InvalidBusinessDefinition  When retained input or attributes cannot be encoded in the closed
     *          value space.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $handle,
        public string $label,
        public FieldPresentationContext $context,
        public FieldWidget $widget,
        public string $display,
        public mixed $inputValue,
        public bool $editable,
        public bool $required,
        public array $errors = [],
        public array $options = [],
        public array $attributes = [],
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1
            || $label === '' || strlen($label) > 120 || strlen($display) > 65_536
            || count($errors) > 32 || count($options) > 256 || count($attributes) > 16
        ) {
            throw new InvalidArgumentException('A field presentation is malformed or unbounded.');
        }
        if (
            ($editable && $widget === FieldWidget::Output)
            || (!$editable && $widget !== FieldWidget::Output)
        ) {
            throw new InvalidArgumentException('A field presentation has an inconsistent editor state.');
        }
        foreach ($errors as $error) {
            if (!is_string($error) || $error === '' || strlen($error) > 1000) {
                throw new InvalidArgumentException('A field presentation contains an invalid error.');
            }
        }
        foreach ($options as $option) {
            if (
                !is_array($option) || array_keys($option) !== ['value', 'label']
                || !is_string($option['value']) || !is_string($option['label'])
                || strlen($option['value']) > 191 || $option['label'] === '' || strlen($option['label']) > 120
            ) {
                throw new InvalidArgumentException('A field presentation contains an invalid option.');
            }
        }
        $allowedAttributes = ['maxlength', 'minlength', 'max', 'min', 'step', 'rows', 'autocomplete', 'inputmode'];
        if (array_diff(array_keys($attributes), $allowedAttributes) !== []) {
            throw new InvalidArgumentException('A field presentation contains an unsafe widget attribute.');
        }
        foreach ($attributes as $attribute) {
            if (
                (!is_int($attribute) && !is_string($attribute) && !is_bool($attribute))
                || (is_string($attribute) && strlen($attribute) > 191)
            ) {
                throw new InvalidArgumentException('A field presentation contains an invalid widget attribute.');
            }
        }
        if (strlen(CanonicalDefinitionJson::encode($attributes)) > 4096) {
            throw new InvalidArgumentException('Field-presentation widget attributes exceed four kibibytes.');
        }
        $inputBytes = 0;
        self::measureInputBytes($inputValue, $inputBytes);
        if (strlen(CanonicalDefinitionJson::encode($inputValue)) > self::MAX_INPUT_BYTES) {
            throw new InvalidArgumentException('A field presentation input exceeds one mebibyte.');
        }
    }

    /**
     * Refuse obviously oversized input before canonical JSON allocates the complete encoded value.
     *
     * Raw string and key bytes plus structural JSON punctuation form a lower bound on final encoded size;
     * canonical encoding performs the exact check afterwards and rejects unsupported value kinds. Stopping this
     * walk as soon as the budget is crossed prevents hundreds of large presenter strings being concatenated first.
     *
     * @param   mixed  $value  Presenter-retained input node being measured.
     * @param   int    $bytes  Running lower-bound byte count, updated in place.
     * @param   int    $depth  Current array nesting depth.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is over-wide, over-deep, or exceeds one mebibyte.
     *
     * @since   2.0.0
     */
    private static function measureInputBytes(mixed $value, int &$bytes, int $depth = 0): void
    {
        if ($depth > 32) {
            throw new InvalidArgumentException('A field presentation input is nested too deeply.');
        }
        if (is_string($value)) {
            $bytes += strlen($value) + 2;
        } elseif (is_int($value)) {
            $bytes += strlen((string) $value);
        } elseif (is_bool($value)) {
            $bytes += $value ? 4 : 5;
        } elseif ($value === null) {
            $bytes += 4;
        } elseif (is_array($value)) {
            if (count($value) > 512) {
                throw new InvalidArgumentException('A field presentation input collection is unbounded.');
            }
            $bytes += 2;
            $list = array_is_list($value);
            $first = true;
            foreach ($value as $key => $item) {
                if (!$first) {
                    $bytes++;
                }
                $first = false;
                if (!$list) {
                    $bytes += strlen((string) $key) + 3;
                }
                if ($bytes > self::MAX_INPUT_BYTES) {
                    throw new InvalidArgumentException('A field presentation input exceeds one mebibyte.');
                }
                self::measureInputBytes($item, $bytes, $depth + 1);
            }
        }
        if ($bytes > self::MAX_INPUT_BYTES) {
            throw new InvalidArgumentException('A field presentation input exceeds one mebibyte.');
        }
    }

    /**
     * Export the model to the shape shared Twig macros receive.
     *
     * @return  array<string, mixed>  Markup-free semantic field presentation.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'label' => $this->label,
            'context' => $this->context->value,
            'widget' => $this->widget->value,
            'display' => $this->display,
            'input_value' => $this->inputValue,
            'editable' => $this->editable,
            'required' => $this->required,
            'errors' => $this->errors,
            'options' => $this->options,
            'attributes' => $this->attributes,
        ];
    }
}
