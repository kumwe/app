<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Presentation\Field;

use InvalidArgumentException;

/**
 * Signed declaration of the presentation contexts one contributed field type implements.
 *
 * The executable presenter never enters a manifest or runtime publication. This immutable value is the
 * comparable declaration a strict contribution phase reconciles before accepting that presenter object.
 *
 * @since  2.0.0
 */
final readonly class FieldPresentationContribution
{
    /**
     * Canonically ordered contexts implemented by the contributed presenter.
     *
     * @var    non-empty-list<FieldPresentationContext>
     * @since  2.0.0
     */
    public array $contexts;

    /**
     * Declare complete presentation coverage for one owner-namespaced field type.
     *
     * @param   string                                    $fieldType  Exact contributed field-type identifier.
     * @param   non-empty-list<FieldPresentationContext>  $contexts   Unique render and edit contexts implemented.
     *
     * @throws  InvalidArgumentException  When the identifier or context set is malformed or unbounded.
     *
     * @since   2.0.0
     */
    public function __construct(public string $fieldType, array $contexts)
    {
        if (
            preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $fieldType) !== 1
            || strlen($fieldType) > 191
        ) {
            throw new InvalidArgumentException('A field-presentation contribution requires a namespaced type.');
        }
        if ($contexts === [] || count($contexts) > count(FieldPresentationContext::cases())) {
            throw new InvalidArgumentException('A field-presentation contribution requires bounded contexts.');
        }
        $indexed = [];
        foreach ($contexts as $context) {
            if (!$context instanceof FieldPresentationContext || isset($indexed[$context->value])) {
                throw new InvalidArgumentException('Field-presentation contexts must be unique typed values.');
            }
            $indexed[$context->value] = $context;
        }
        ksort($indexed, SORT_STRING);
        $this->contexts = array_values($indexed);
    }

    /**
     * Parse one strict manifest declaration.
     *
     * @param   array<string, mixed>  $document  Closed field-type and context document.
     *
     * @return  self  Validated canonical declaration.
     *
     * @throws  InvalidArgumentException  When a key or value is unknown, malformed, repeated, or unbounded.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        if (array_diff(array_keys($document), ['field_type', 'contexts']) !== []) {
            throw new InvalidArgumentException('A field-presentation contribution contains an unknown property.');
        }
        $fieldType = $document['field_type'] ?? null;
        $contexts = $document['contexts'] ?? null;
        if (!is_string($fieldType) || !is_array($contexts) || !array_is_list($contexts)) {
            throw new InvalidArgumentException('A field-presentation contribution is malformed.');
        }

        return new self($fieldType, array_map(static function (mixed $context): FieldPresentationContext {
            if (!is_string($context)) {
                throw new InvalidArgumentException('A field-presentation context must be a string.');
            }

            return FieldPresentationContext::tryFrom($context)
                ?? throw new InvalidArgumentException('A field-presentation context is unsupported.');
        }, $contexts));
    }

    /**
     * Export the signed manifest and contribution-inventory shape.
     *
     * @return  array{field_type: string, contexts: non-empty-list<string>}  Canonical declaration.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'field_type' => $this->fieldType,
            'contexts' => array_map(
                static fn (FieldPresentationContext $context): string => $context->value,
                $this->contexts,
            ),
        ];
    }
}
