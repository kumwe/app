<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

/**
 * One declared association between business entities, validated the moment it is constructed.
 *
 * Relationships are the part of an entity contract that reaches another entity's table: the schema
 * compiler turns a singular kind into a target column on the owning record table and a collection kind
 * into a junction or owned-line table, and the delete behaviour becomes the foreign key's action when
 * the target row is removed. Construction settles only what this one relationship can answer for
 * itself — that a required association cannot be nulled away by a delete, that an owned line collection
 * cascades from its owner, and that ordering is reserved for collections. Whether the target exists, is
 * in the same site and scope, and names a reciprocal inverse is `BusinessDefinitionValidator`'s job,
 * because those answers need the rest of the graph.
 *
 * @since  2.0.0
 */
final readonly class RelationshipDefinition
{
    /**
     * Capture an association and reject a combination the runtime could not honour.
     *
     * @param   string            $handle    Stable snake_case name of this side of the association.
     * @param   string            $label     Operator-facing name for the association.
     * @param   RelationshipKind  $kind      Cardinality, and with it the storage the compiler emits.
     * @param   string            $target    Namespaced handle of the entity on the other side.
     * @param   ?string           $inverse   Handle of the reciprocal relationship on the target, or null
     *          when this side is declared alone.
     * @param   bool              $required  Whether a record must always name a target.
     * @param   bool              $unique    Whether a target may be claimed by one source only.
     * @param   bool              $ordered   Whether collection members carry a caller-visible position.
     * @param   DeleteBehavior    $onDelete  What deleting the target does to the association.
     *
     * @throws  InvalidBusinessDefinition  When an identifier is malformed, a required association would be
     *          set to null on delete, an owned line collection does not cascade, or a singular
     *          relationship claims ordering.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $handle,
        public string $label,
        public RelationshipKind $kind,
        public string $target,
        public ?string $inverse = null,
        public bool $required = false,
        public bool $unique = false,
        public bool $ordered = false,
        public DeleteBehavior $onDelete = DeleteBehavior::Restrict,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
            throw new InvalidBusinessDefinition('A relationship handle is invalid.');
        }
        if ($label === '' || strlen($label) > 120) {
            throw new InvalidBusinessDefinition('A relationship label is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $target) !== 1) {
            throw new InvalidBusinessDefinition('A relationship target handle is invalid.');
        }
        if ($inverse !== null && preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $inverse) !== 1) {
            throw new InvalidBusinessDefinition('A relationship inverse handle is invalid.');
        }
        if ($onDelete === DeleteBehavior::SetNull && $required) {
            throw new InvalidBusinessDefinition('A required relationship cannot use set-null deletion.');
        }
        if ($kind === RelationshipKind::OwnedLineCollection && $onDelete !== DeleteBehavior::Cascade) {
            throw new InvalidBusinessDefinition('An owned line collection must cascade from its owner.');
        }
        if (
            $ordered && !in_array($kind, [
            RelationshipKind::OneToMany,
            RelationshipKind::ManyToMany,
            RelationshipKind::OwnedLineCollection,
            ], true)
        ) {
            throw new InvalidBusinessDefinition('Only collection relationships may be ordered.');
        }
    }

    /**
     * Rebuild a relationship from the canonical document `toArray()` writes.
     *
     * Unknown keys are refused rather than ignored, and an unrecognised kind or delete behaviour is
     * rejected here, so an import cannot land a relationship this release would misread.
     *
     * @param   array<string, mixed>  $document  Canonical relationship document, keyed as it is stored.
     *
     * @return  self  The relationship, with every construction rule already applied.
     *
     * @throws  InvalidBusinessDefinition  When a key is unknown, a member has the wrong type, or the
     *          resulting relationship breaks a construction rule.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        if (
            array_diff(array_keys($document), [
            'handle', 'label', 'kind', 'target', 'inverse', 'required', 'unique', 'ordered', 'on_delete',
            ]) !== []
        ) {
            throw new InvalidBusinessDefinition('A relationship contains an unknown property.');
        }
        $kind = RelationshipKind::tryFrom(self::string($document, 'kind'))
            ?? throw new InvalidBusinessDefinition('A relationship kind is unsupported.');
        $delete = DeleteBehavior::tryFrom(self::optionalString($document, 'on_delete', 'restrict'))
            ?? throw new InvalidBusinessDefinition('A relationship delete behavior is unsupported.');

        return new self(
            self::string($document, 'handle'),
            self::string($document, 'label'),
            $kind,
            self::string($document, 'target'),
            self::nullableString($document, 'inverse'),
            self::boolean($document, 'required'),
            self::boolean($document, 'unique'),
            self::boolean($document, 'ordered'),
            $delete,
        );
    }

    /**
     * Export the relationship as the document the definition checksum is taken over.
     *
     * @return  array<string, mixed>  Every declared property under its snake_case key, with the kind and
     *          delete behaviour written as their backing strings.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'label' => $this->label,
            'kind' => $this->kind->value,
            'target' => $this->target,
            'inverse' => $this->inverse,
            'required' => $this->required,
            'unique' => $this->unique,
            'ordered' => $this->ordered,
            'on_delete' => $this->onDelete->value,
        ];
    }

    /**
     * Read a mandatory string property, trimmed.
     *
     * @param   array<string, mixed>  $document  Document the property is read from.
     * @param   string                $key       Property name, which is also named in the failure.
     *
     * @return  string  The value with surrounding whitespace removed.
     *
     * @throws  InvalidBusinessDefinition  When the property is absent, not a string, or blank.
     *
     * @since   2.0.0
     */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidBusinessDefinition('Relationship property ' . $key . ' is required.');
        }
        return trim($value);
    }

    /**
     * Read a string property that falls back to a supplied default when absent.
     *
     * @param   array<string, mixed>  $document  Document the property is read from.
     * @param   string                $key       Property name, which is also named in the failure.
     * @param   string                $default   Value substituted when the document omits the key.
     *
     * @return  string  The value with surrounding whitespace removed.
     *
     * @throws  InvalidBusinessDefinition  When the property is present but not a string.
     *
     * @since   2.0.0
     */
    private static function optionalString(array $document, string $key, string $default): string
    {
        $value = $document[$key] ?? $default;
        if (!is_string($value)) {
            throw new InvalidBusinessDefinition('Relationship property ' . $key . ' must be a string.');
        }
        return trim($value);
    }

    /**
     * Read an optional string property where absence and emptiness both mean "not declared".
     *
     * @param   array<string, mixed>  $document  Document the property is read from.
     * @param   string                $key       Property name, which is also named in the failure.
     *
     * @return  ?string  The trimmed value, or null when the document omits the key.
     *
     * @throws  InvalidBusinessDefinition  When the property is present but not a non-blank string.
     *
     * @since   2.0.0
     */
    private static function nullableString(array $document, string $key): ?string
    {
        $value = $document[$key] ?? null;
        if ($value !== null && (!is_string($value) || trim($value) === '')) {
            throw new InvalidBusinessDefinition('Relationship property ' . $key . ' must be null or a string.');
        }
        return is_string($value) ? trim($value) : null;
    }

    /**
     * Read a flag that defaults to false when the document omits it.
     *
     * @param   array<string, mixed>  $document  Document the property is read from.
     * @param   string                $key       Property name, which is also named in the failure.
     *
     * @return  bool  The declared flag, or false when the key is absent.
     *
     * @throws  InvalidBusinessDefinition  When the property is present but not a boolean.
     *
     * @since   2.0.0
     */
    private static function boolean(array $document, string $key): bool
    {
        $value = $document[$key] ?? false;
        if (!is_bool($value)) {
            throw new InvalidBusinessDefinition('Relationship property ' . $key . ' must be boolean.');
        }
        return $value;
    }
}
