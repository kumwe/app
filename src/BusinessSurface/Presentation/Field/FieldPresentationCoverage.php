<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Presentation\Field;

use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationContext;

use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;

/**
 * Derives the exact presenter contexts a declarative field may reach on generated surfaces.
 *
 * The signed-manifest admission boundary and the assembled runtime registry both use this one derivation,
 * so install and activation cannot disagree about whether a presenter is complete.
 *
 * @since  2.0.0
 */
final class FieldPresentationCoverage
{
    /**
     * Derive every presentation context a generated surface may request for one field.
     *
     * Readable fields can reach collection, detail, and bounded relationship-choice rendering. Create and
     * update follow the same immutable structural flags as `BusinessSurfaceCatalog`; relation also covers
     * an editable field when the definition is used as an owned-line target.
     *
     * @param   FieldDefinition  $field  Published field whose possible generated uses are inspected.
     *
     * @return  list<FieldPresentationContext>  Unique required contexts in enum declaration order.
     *
     * @since   2.0.0
     */
    public static function requiredContexts(FieldDefinition $field): array
    {
        $required = [];
        if ($field->readVisible) {
            $required[FieldPresentationContext::List->value] = true;
            $required[FieldPresentationContext::Detail->value] = true;
            $required[FieldPresentationContext::Relation->value] = true;
        }
        if ($field->createVisible && !$field->readOnly && !$field->serverOnly) {
            $required[FieldPresentationContext::Create->value] = true;
            $required[FieldPresentationContext::Relation->value] = true;
        }
        if (
            $field->updateVisible
            && !$field->readOnly
            && !$field->serverOnly
            && !$field->immutableAfterCreate
        ) {
            $required[FieldPresentationContext::Update->value] = true;
            $required[FieldPresentationContext::Relation->value] = true;
        }

        return array_values(array_filter(
            FieldPresentationContext::cases(),
            static fn (FieldPresentationContext $context): bool => isset($required[$context->value]),
        ));
    }

    /**
     * Block construction; coverage is a stateless declarative derivation.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
