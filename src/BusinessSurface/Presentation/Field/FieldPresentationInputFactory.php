<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Presentation\Field;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationConfiguration;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationContext;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationInput;

/**
 * Builds the canonical SDK input from one admitted App field definition.
 *
 * @since  2.0.0
 */
final readonly class FieldPresentationInputFactory
{
    /**
     * Copy the already admitted definition into the host-neutral SDK value offered to a presenter.
     *
     * @param   FieldDefinition           $field     Exact field declaration being presented.
     * @param   FieldTypeDefinition       $type      Admitted logical type resolved for the field.
     * @param   FieldPresentationContext  $context   Exact delivery context being built.
     * @param   mixed                     $value     Policy-disclosed or retained field value.
     * @param   string                    $locale    Bounded locale formatting hint.
     * @param   list<string>              $errors    Caller-visible validation messages.
     * @param   bool                      $editable  Whether host policy currently admits input.
     *
     * @return  FieldPresentationInput  Complete canonical presenter input.
     *
     * @throws  InvalidArgumentException  When the resolved type contradicts the field. Configuration
     *          bounds are already enforced at definition admission and are defensively rechecked by the
     *          SDK value object here.
     *
     * @since   2.0.0
     */
    public static function fromDefinition(
        FieldDefinition $field,
        FieldTypeDefinition $type,
        FieldPresentationContext $context,
        mixed $value = null,
        string $locale = 'en',
        array $errors = [],
        bool $editable = false,
    ): FieldPresentationInput {
        if ($field->type !== $type->id) {
            throw new InvalidArgumentException('A field-presentation input has mismatched field-type metadata.');
        }

        return new FieldPresentationInput(
            handle: $field->handle,
            label: $field->label,
            fieldType: $type->id,
            required: $field->required,
            readOnly: $field->readOnly,
            computed: $field->computed,
            serverOnly: $field->serverOnly,
            immutableAfterCreate: $field->immutableAfterCreate,
            context: $context,
            value: $value,
            locale: $locale,
            errors: $errors,
            editable: $editable,
            length: $field->length,
            precision: $field->precision,
            scale: $field->scale,
            configuration: FieldPresentationConfiguration::fromArray($field->configuration),
        );
    }
}
