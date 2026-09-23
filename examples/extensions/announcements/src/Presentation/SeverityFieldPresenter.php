<?php

declare(strict_types=1);

namespace KumweExample\Announcements\Presentation;

use InvalidArgumentException;
use Kumwe\CanonicalJson\CanonicalEncoder;
use Kumwe\BusinessSurface\Contract\Presentation\Field\FieldPresentationInput;
use Kumwe\BusinessSurface\Contract\Presentation\Field\FieldPresentationModel;
use Kumwe\BusinessSurface\Contract\Presentation\Field\FieldPresenter;
use Kumwe\BusinessSurface\Contract\Presentation\Field\FieldWidget;

/**
 * Presents the announcements component's bounded severity field through core-owned widgets.
 *
 * @since  2.0.0
 */
final readonly class SeverityFieldPresenter implements FieldPresenter
{
    /**
     * Bind the presenter to the host canonical encoder the field model bounds its bytes with.
     *
     * @param  CanonicalEncoder  $canonicalEncoder  Host encoder exposed to the extension container.
     *
     * @since  2.0.0
     */
    public function __construct(private CanonicalEncoder $canonicalEncoder)
    {
    }

    /**
     * Render a disclosed severity as text or as a closed selector when server policy permits editing.
     *
     * @param   FieldPresentationInput  $input  Typed field metadata and already disclosed value.
     *
     * @return  FieldPresentationModel  Markup-free model consumed by the generated core templates.
     *
     * @throws  InvalidArgumentException  When the field carries an invalid severity value or option set.
     *
     * @since   2.0.0
     */
    public function present(FieldPresentationInput $input): FieldPresentationModel
    {
        $value = $input->value;
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException('An announcement severity must be a string or null.');
        }
        $options = $this->options();
        if ($value !== null && !in_array($value, array_column($options, 'value'), true)) {
            throw new InvalidArgumentException('An announcement severity is outside its declared options.');
        }
        $editing = $input->permitsEditing();

        return new FieldPresentationModel(
            $input->handle,
            $input->label,
            $input->context,
            $editing ? FieldWidget::Select : FieldWidget::Output,
            $value === null ? '' : self::label($value),
            $editing ? $value : null,
            $editing,
            $input->required,
            $this->canonicalEncoder,
            $input->errors,
            $editing ? $options : [],
        );
    }

    /**
     * Validate and label the closed option set declared by the field.
     *
     * @return  non-empty-list<array{value: string, label: string}>  Unique safe selector options.
     *
     * @throws  InvalidArgumentException  When options are absent, repeated, malformed, or unbounded.
     *
     * @since   2.0.0
     */
    private function options(): array
    {
        $values = ['info', 'notice', 'warning', 'critical'];
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
