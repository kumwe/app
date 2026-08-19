<?php

declare(strict_types=1);

namespace KumweExample\Announcements\Presentation;

use InvalidArgumentException;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentation;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationRequest;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresenter;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldWidget;

/**
 * Presents the announcements component's bounded severity field through core-owned widgets.
 *
 * @since  2.0.0
 */
final readonly class SeverityFieldPresenter implements FieldPresenter
{
    /**
     * Render a disclosed severity as text or as a closed selector when server policy permits editing.
     *
     * @param   FieldPresentationRequest  $request  Typed field metadata and already disclosed value.
     *
     * @return  FieldPresentation  Markup-free model consumed by the generated core templates.
     *
     * @throws  InvalidArgumentException  When the field carries an invalid severity value or option set.
     *
     * @since   2.0.0
     */
    public function present(FieldPresentationRequest $request): FieldPresentation
    {
        $value = $request->value;
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException('An announcement severity must be a string or null.');
        }
        $options = $this->options($request);
        if ($value !== null && !in_array($value, array_column($options, 'value'), true)) {
            throw new InvalidArgumentException('An announcement severity is outside its declared options.');
        }
        $editing = $request->permitsEditing();

        return new FieldPresentation(
            $request->field->handle,
            $request->field->label,
            $request->context,
            $editing ? FieldWidget::Select : FieldWidget::Output,
            $value === null ? '' : self::label($value),
            $editing ? $value : null,
            $editing,
            $request->field->required,
            $request->errors,
            $editing ? $options : [],
        );
    }

    /**
     * Validate and label the closed option set declared by the field.
     *
     * @param   FieldPresentationRequest  $request  Field whose `options` configuration is inspected.
     *
     * @return  non-empty-list<array{value: string, label: string}>  Unique safe selector options.
     *
     * @throws  InvalidArgumentException  When options are absent, repeated, malformed, or unbounded.
     *
     * @since   2.0.0
     */
    private function options(FieldPresentationRequest $request): array
    {
        $values = $request->field->configuration['options'] ?? null;
        if (!is_array($values) || !array_is_list($values) || $values === [] || count($values) > 256) {
            throw new InvalidArgumentException('An announcement severity requires a bounded option list.');
        }
        $options = [];
        foreach ($values as $value) {
            if (
                !is_string($value)
                || preg_match('/^[a-z][a-z0-9_-]{0,62}$/D', $value) !== 1
                || isset($options[$value])
            ) {
                throw new InvalidArgumentException('An announcement severity option is malformed or repeated.');
            }
            $options[$value] = ['value' => $value, 'label' => self::label($value)];
        }

        return array_values($options);
    }

    /**
     * Turn one stable severity token into its human-readable label.
     *
     * @param   string  $value  Validated lowercase severity token.
     *
     * @return  string  Title-cased label with separators replaced by spaces.
     *
     * @since   2.0.0
     */
    private static function label(string $value): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $value));
    }
}
