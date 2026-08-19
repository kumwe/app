<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Presentation\Field;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\ConvertedMoneyValue;

/**
 * Owner-aware registry of safe presenters keyed by field type and exact presentation context.
 *
 * A type/context pair has exactly one active owner. Registration verifies the owner controls the type
 * namespace and rejects collisions, while removal is owner-scoped so disabling one extension cannot
 * remove another extension's presenter. Core falls back only among its own built-in registrations; an
 * extension field type without a registered context fails closed instead of rendering as generic HTML.
 *
 * This is also the one gate every presented value crosses, core-owned or contributed, which is why the
 * conversion-provenance rule is enforced here rather than in any single presenter: a strategy handed a
 * converted amount that returns a presentation without its provenance is refused outright, so no
 * extension can reduce a converted figure to a bare number by writing its own renderer.
 *
 * @since  2.0.0
 */
final class FieldPresentationRegistry
{
    /**
     * Presenters keyed by field type and context.
     *
     * @var    array<string, array<string, array{owner: DefinitionOwner, presenter: FieldPresenter}>>
     * @since  2.0.0
     */
    private array $presenters = [];

    /**
     * Register one safe presenter for a bounded set of contexts.
     *
     * @param   DefinitionOwner                           $owner      Owner of the field-type namespace.
     * @param   string                                    $fieldType  Exact namespaced field-type identifier.
     * @param   non-empty-list<FieldPresentationContext>  $contexts   Contexts served by this strategy.
     * @param   FieldPresenter                            $presenter  Transport-free semantic strategy.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the owner does not control the type namespace.
     * @throws  InvalidArgumentException  When contexts are empty, repeated, or collide with an owner.
     *
     * @since   2.0.0
     */
    public function register(
        DefinitionOwner $owner,
        string $fieldType,
        array $contexts,
        FieldPresenter $presenter,
    ): void {
        $owner->assertOwns($fieldType);
        if ($contexts === []) {
            throw new InvalidArgumentException('Field-presentation contexts must be a unique non-empty list.');
        }
        $contextNames = [];
        foreach ($contexts as $context) {
            if (!$context instanceof FieldPresentationContext) {
                throw new InvalidArgumentException('A field-presentation context is invalid.');
            }
            if (isset($contextNames[$context->value])) {
                throw new InvalidArgumentException('Field-presentation contexts must be a unique non-empty list.');
            }
            $contextNames[$context->value] = true;
            if (isset($this->presenters[$fieldType][$context->value])) {
                throw new InvalidArgumentException(
                    'A field presenter already owns this field-type and context pair.',
                );
            }
        }
        foreach ($contexts as $context) {
            $this->presenters[$fieldType][$context->value] = [
                'owner' => $owner,
                'presenter' => $presenter,
            ];
        }
        ksort($this->presenters[$fieldType], SORT_STRING);
        ksort($this->presenters, SORT_STRING);
    }

    /**
     * Present one field through its exact type and context strategy.
     *
     * @param   FieldPresentationRequest  $request  Validated field, type, context, value and errors.
     *
     * @return  FieldPresentation  Bounded semantic view model.
     *
     * @throws  InvalidBusinessDefinition  When no strategy covers the exact pair, it returns another field,
     *          it widens editability, or it drops the provenance of a converted amount.
     * @throws  InvalidArgumentException  When a value marked as converted cannot prove the conversion it
     *          claims.
     *
     * @since   2.0.0
     */
    public function present(FieldPresentationRequest $request): FieldPresentation
    {
        $registration = $this->presenters[$request->type->id][$request->context->value] ?? null;
        if ($registration === null) {
            throw new InvalidBusinessDefinition('No safe presenter is registered for this field context.');
        }
        $converted = ConvertedMoneyValue::detect($request->value);
        $presentation = $registration['presenter']->present($request);
        if ($converted !== null && $presentation->provenance !== $converted->toArray()) {
            throw new InvalidBusinessDefinition('A converted amount must be presented with its conversion provenance.');
        }
        if (
            $presentation->handle !== $request->field->handle
            || $presentation->context !== $request->context
            || $presentation->label !== $request->field->label
            || $presentation->required !== $request->field->required
            || $presentation->errors !== $request->errors
        ) {
            throw new InvalidBusinessDefinition('A field presenter returned metadata for another field.');
        }
        if ($presentation->editable && !$request->permitsEditing()) {
            throw new InvalidBusinessDefinition('A field presenter cannot widen server-side editability.');
        }

        return $presentation;
    }

    /**
     * Remove every presenter contributed by one owner.
     *
     * @param   DefinitionOwner  $owner  Owner being disabled or uninstalled.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function removeOwner(DefinitionOwner $owner): void
    {
        foreach ($this->presenters as $fieldType => $contexts) {
            foreach ($contexts as $context => $registration) {
                if ($registration['owner']->toArray() === $owner->toArray()) {
                    unset($this->presenters[$fieldType][$context]);
                }
            }
            if ($this->presenters[$fieldType] === []) {
                unset($this->presenters[$fieldType]);
            }
        }
    }

    /**
     * List the signed presentation declarations contributed by one owner.
     *
     * Executable presenter objects are deliberately excluded. Contexts are grouped by field type so
     * diagnostics and runtime publications expose only the same declaration shape a manifest carries.
     *
     * @param   DefinitionOwner  $owner  Contributor whose presentation coverage is inspected.
     *
     * @return  list<FieldPresentationContribution>  Canonically ordered declarations.
     *
     * @since   2.0.0
     */
    public function ownedBy(DefinitionOwner $owner): array
    {
        $contributions = [];
        foreach ($this->presenters as $fieldType => $contexts) {
            $ownedContexts = [];
            foreach ($contexts as $context => $registration) {
                if ($registration['owner']->toArray() === $owner->toArray()) {
                    $ownedContexts[] = FieldPresentationContext::from($context);
                }
            }
            if ($ownedContexts !== []) {
                $contributions[] = new FieldPresentationContribution($fieldType, $ownedContexts);
            }
        }

        return $contributions;
    }

    /**
     * Report exact context coverage for compile-time activation checks.
     *
     * @param   string  $fieldType  Namespaced type identifier.
     *
     * @return  list<string>  Canonically ordered presentation-context values.
     *
     * @since   2.0.0
     */
    public function contexts(string $fieldType): array
    {
        return array_keys($this->presenters[$fieldType] ?? []);
    }

    /**
     * Prove one published definition has active presentation coverage before generated delivery is exposed.
     *
     * This runtime graph check complements signed-manifest admission: it also covers a definition that uses a
     * field type owned by another active extension, whose presenter declaration lives in that owner's manifest.
     *
     * @param   EntityTypeDefinition  $definition  Contributed definition being admitted to the active graph.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When an active type lacks a context the field can reach.
     *
     * @since   2.0.0
     */
    public function assertCovers(EntityTypeDefinition $definition): void
    {
        if ($definition->status !== DefinitionStatus::Published) {
            return;
        }
        foreach ($definition->fields() as $field) {
            $required = array_map(
                static fn (FieldPresentationContext $context): string => $context->value,
                FieldPresentationCoverage::requiredContexts($field),
            );
            $missing = array_values(array_diff($required, $this->contexts($field->type)));
            if ($missing === []) {
                continue;
            }
            sort($missing, SORT_STRING);
            throw new InvalidBusinessDefinition(sprintf(
                'Active business definitions require field-presentation contexts for %s: %s.',
                $field->type,
                implode(', ', $missing),
            ));
        }
    }
}
