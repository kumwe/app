<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;

/**
 * Rendering contract through which business-surface application code obtains generated field models.
 *
 * `BusinessSurfaceService` decides which field is presented, in which context, with which disclosed
 * value, errors and editability — those are use-case decisions — but the strategy that turns the value
 * into a semantic view model belongs to the presentation layer. This port keeps the dependency pointing
 * the right way: the application layer owns the contract and speaks domain types, and the presentation
 * layer adapts it over the owner-aware safe presenter registry (`RegistryFieldModelPresenter` today).
 * An implementation owes the registry's guarantees unchanged: a type and context pair without a
 * registered safe presenter fails closed, a presenter cannot widen server-side editability, and a
 * converted amount is refused unless its conversion provenance survives presentation.
 *
 * @since  2.0.0
 */
interface FieldModelPresenter
{
    /**
     * Present one field's disclosed value through its exact type and context strategy.
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
    ): PresentedField;
}
