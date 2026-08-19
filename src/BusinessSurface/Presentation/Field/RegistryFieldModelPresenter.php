<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Presentation\Field;

use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessSurface\Application\FieldModelContext;
use Kumwe\App\BusinessSurface\Application\FieldModelPresenter;
use Kumwe\App\BusinessSurface\Application\PresentedField;

/**
 * Adapts the application-owned rendering contract over the owner-aware safe presenter registry.
 *
 * The application facade names its context in its own `FieldModelContext` vocabulary and never sees a
 * presentation type; this adapter translates that context to the extension-facing
 * `FieldPresentationContext` by backing value, builds the validated `FieldPresentationRequest` the
 * registered strategies receive, and reduces the returned `FieldPresentation` to the typed
 * `PresentedField` the facade consumes. Every guarantee stays the registry's: fail-closed coverage,
 * editability that only narrows, and conversion provenance that cannot be dropped.
 *
 * @since  2.0.0
 */
final readonly class RegistryFieldModelPresenter implements FieldModelPresenter
{
    /**
     * Wire the adapter to the contribution-owned registry every presented value crosses.
     *
     * @param  FieldPresentationRegistry  $registry  Owner-aware safe field presenter registry.
     *
     * @since  2.0.0
     */
    public function __construct(private FieldPresentationRegistry $registry)
    {
    }

    /**
     * Present one field's disclosed value through the registry's exact type and context strategy.
     *
     * @param   FieldDefinition      $field     Field declaration from the pinned entity version.
     * @param   FieldTypeDefinition  $type      Immutable logical and storage family of the field.
     * @param   FieldModelContext    $context   Exact render or edit context the facade is building.
     * @param   mixed                $value     Already validated and policy-disclosed typed value.
     * @param   list<string>         $errors    Caller-visible validation messages for this field.
     * @param   bool                 $editable  Whether server policy and conditions admit input now.
     *
     * @return  PresentedField  Display text, conversion provenance and the exported semantic model.
     *
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When no safe presenter
     *          covers the pair, the strategy answers for another field, it widens editability, or it
     *          drops the provenance of a converted amount.
     * @throws  \InvalidArgumentException  When a value marked as converted cannot prove the conversion
     *          it claims, or errors are malformed or unbounded.
     *
     * @since   2.0.0
     */
    public function present(
        FieldDefinition $field,
        FieldTypeDefinition $type,
        FieldModelContext $context,
        mixed $value,
        array $errors = [],
        bool $editable = false,
    ): PresentedField {
        $presentation = $this->registry->present(new FieldPresentationRequest(
            $field,
            $type,
            FieldPresentationContext::from($context->value),
            $value,
            errors: $errors,
            editable: $editable,
        ));

        return new PresentedField($presentation->display, $presentation->provenance, $presentation->toArray());
    }
}
