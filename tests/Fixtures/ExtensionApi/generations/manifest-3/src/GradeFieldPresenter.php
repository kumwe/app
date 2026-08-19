<?php

declare(strict_types=1);

namespace KumweContract\ManifestThree;

use InvalidArgumentException;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentation;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationRequest;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresenter;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldWidget;

/**
 * Presents the manifest-3 compatibility grade as markup-free output.
 *
 * The presenter exists so the schema-3 field-presentation surface is exercised by a real implementation
 * of the public `FieldPresenter` contract rather than by a declaration alone.
 *
 * @since  2.0.0
 */
final readonly class GradeFieldPresenter implements FieldPresenter
{
    /**
     * Render the disclosed grade as read-only text in every declared context.
     *
     * @param   FieldPresentationRequest  $request  Typed field metadata and already disclosed value.
     *
     * @return  FieldPresentation  Markup-free model the generated core templates consume.
     *
     * @throws  InvalidArgumentException  When the disclosed value is not a string or null.
     *
     * @since   2.0.0
     */
    public function present(FieldPresentationRequest $request): FieldPresentation
    {
        $value = $request->value;
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException('A compatibility grade must be a string or null.');
        }

        return new FieldPresentation(
            $request->field->handle,
            $request->field->label,
            $request->context,
            FieldWidget::Output,
            $value ?? '',
            null,
            false,
            $request->field->required,
            $request->errors,
            [],
        );
    }
}
