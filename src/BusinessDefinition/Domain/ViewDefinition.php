<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

/**
 * One named projection of a business entity: the fields a surface shows, filters on, and sorts by.
 *
 * Views are declared inside an entity definition and travel into the immutable published payload, so the
 * projection a surface is meant to offer is pinned to a definition version and checksummed with it rather
 * than read from live configuration. This constructor proves a view sound in isolation — a supported
 * kind, at least one delivery surface, and bounded, duplicate-free handle lists — while the owning
 * `EntityTypeDefinition` proves it against the entity: every handle must name a declared field, filters
 * must name filterable fields and sorts sortable ones, and a view may claim the portal or public surface
 * only where the entity exposes that surface too. The flags say where a view may be offered; they never
 * grant permission on their own.
 *
 * @since  2.0.0
 */
final readonly class ViewDefinition
{
    /**
     * Field handles the view projects, in declaration order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $fields;

    /**
     * Field handles the view offers as filters; empty when it exposes none.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $filters;

    /**
     * Field handles the view may be ordered by; empty when it offers no ordering choice.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $sorts;

    /**
     * Declare a view, validating its identity, kind, surfaces, and field references.
     *
     * @param   string        $handle         Lowercase snake-case name the view is addressed by.
     * @param   string        $label          Operator-facing name shown wherever the view is offered.
     * @param   string        $kind           Presentation shape: `list`, `detail`, `form`, `history`, or
     *          `relation`.
     * @param   list<string>  $fields         Field handles to project; at least one is required.
     * @param   list<string>  $filters        Field handles offered as filters, each of which the entity must
     *          declare filterable.
     * @param   list<string>  $sorts          Field handles offered as sort keys, each of which the entity must
     *          declare sortable.
     * @param   bool          $administrator  Whether the administrator surface may render the view.
     * @param   bool          $portal         Whether the portal surface may render the view.
     * @param   bool          $public         Whether the view may be rendered anonymously.
     *
     * @throws  InvalidBusinessDefinition  When the handle or label is malformed, the kind is unsupported, no
     *          delivery surface is declared, the projection is empty, a list exceeds 128 entries or repeats a
     *          handle, or a handle is not a bounded lowercase identifier.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $handle,
        public string $label,
        public string $kind,
        array $fields,
        array $filters = [],
        array $sorts = [],
        public bool $administrator = true,
        public bool $portal = false,
        public bool $public = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1 || $label === '' || strlen($label) > 120) {
            throw new InvalidBusinessDefinition('A business view identity is invalid.');
        }
        if (!in_array($kind, ['list', 'detail', 'form', 'history', 'relation'], true)) {
            throw new InvalidBusinessDefinition('A business view kind is unsupported.');
        }
        if (!$administrator && !$portal && !$public) {
            throw new InvalidBusinessDefinition('A business view must declare at least one delivery surface.');
        }
        $this->fields = self::identifiers($fields, false);
        $this->filters = self::identifiers($filters, true);
        $this->sorts = self::identifiers($sorts, true);
    }

    /**
     * Rebuild a view from its canonical document, rejecting any property the contract does not name.
     *
     * The unknown-property check runs before anything is read, so a document written against a newer or
     * hand-edited schema fails at the import boundary instead of being quietly truncated on the way in.
     *
     * @param   array<string, mixed>  $document  Decoded view document keyed by canonical property name.
     *
     * @return  self  The validated view, having passed the same invariants as direct construction.
     *
     * @throws  InvalidBusinessDefinition  When the document carries an unknown property, a property of the
     *          wrong type, or values the constructor rejects.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        if (
            array_diff(array_keys($document), [
            'handle', 'label', 'kind', 'fields', 'filters', 'sorts', 'administrator', 'portal', 'public',
            ]) !== []
        ) {
            throw new InvalidBusinessDefinition('A business view contains an unknown property.');
        }

        return new self(
            self::string($document, 'handle'),
            self::string($document, 'label'),
            self::string($document, 'kind'),
            self::list($document, 'fields'),
            self::list($document, 'filters'),
            self::list($document, 'sorts'),
            self::boolean($document, 'administrator', true),
            self::boolean($document, 'portal'),
            self::boolean($document, 'public'),
        );
    }

    /**
     * Export the view as the document that becomes part of a published definition's canonical bytes.
     *
     * Every declared property is emitted, defaults included, so the document round-trips through
     * `fromArray()` unchanged. Key order carries no meaning: `CanonicalDefinitionJson` sorts before hashing.
     *
     * @return  array<string, mixed>  Handle, label, kind, the three handle lists, and the three surface
     *          flags, under their canonical keys.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'label' => $this->label,
            'kind' => $this->kind,
            'fields' => $this->fields,
            'filters' => $this->filters,
            'sorts' => $this->sorts,
            'administrator' => $this->administrator,
            'portal' => $this->portal,
            'public' => $this->public,
        ];
    }

    /**
     * Read a mandatory text property, trimmed of surrounding whitespace.
     *
     * @param   array<string, mixed>  $document  Decoded view document being read.
     * @param   string                $key       Canonical property name to read.
     *
     * @return  string  The trimmed value, never empty.
     *
     * @throws  InvalidBusinessDefinition  When the property is absent, is not a string, or is blank once
     *          trimmed.
     *
     * @since   2.0.0
     */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidBusinessDefinition('Business view property ' . $key . ' is required.');
        }
        return trim($value);
    }

    /**
     * Read one of the three handle lists, treating an absent property as an empty list.
     *
     * Entries are checked one by one rather than trusted from the outer type, so a list holding a number
     * or a nested object is refused here rather than reaching the identifier pattern as a coerced string.
     *
     * @param   array<string, mixed>  $document  Decoded view document being read.
     * @param   string                $key       Canonical property name to read.
     *
     * @return  list<string>  The declared entries in document order; empty when the property is absent.
     *
     * @throws  InvalidBusinessDefinition  When the property is present but is not a JSON array, or holds an
     *          entry that is not a string.
     *
     * @since   2.0.0
     */
    private static function list(array $document, string $key): array
    {
        $value = $document[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidBusinessDefinition('Business view property ' . $key . ' must be a list.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidBusinessDefinition('Business view property ' . $key . ' must contain strings.');
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Read a surface flag, falling back to the declared default when the property is absent or null.
     *
     * A present value is never coerced: `1`, `"1"` and `"true"` are all rejected, so a loosely encoded
     * document cannot open a delivery surface the author did not write out as a boolean.
     *
     * @param   array<string, mixed>  $document  Decoded view document being read.
     * @param   string                $key       Canonical property name to read.
     * @param   bool                  $default   Value to use when the property is absent or null.
     *
     * @return  bool  The declared flag, or the default.
     *
     * @throws  InvalidBusinessDefinition  When the property is present with a value that is not a boolean.
     *
     * @since   2.0.0
     */
    private static function boolean(array $document, string $key, bool $default = false): bool
    {
        $value = $document[$key] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidBusinessDefinition('Business view property ' . $key . ' must be boolean.');
        }
        return $value;
    }

    /**
     * Prove one handle list is bounded, free of repeats, and made only of well-formed field handles.
     *
     * Whether the referenced fields exist, and whether they are filterable or sortable, is a question about
     * the owning entity that `EntityTypeDefinition` answers; this only settles the shape of the handles.
     *
     * @param   list<string>  $values      Field handles declared for one of the view's three lists.
     * @param   bool          $mayBeEmpty  Whether an empty list is acceptable; false for the projection,
     *          which has to name at least one field.
     *
     * @return  list<string>  The same handles, unchanged and in declaration order.
     *
     * @throws  InvalidBusinessDefinition  When a required list is empty, more than 128 handles are given, a
     *          handle repeats, or a handle is not a bounded lowercase snake-case identifier.
     *
     * @since   2.0.0
     */
    private static function identifiers(array $values, bool $mayBeEmpty): array
    {
        if (
            (!$mayBeEmpty && $values === [])
            || count($values) > 128
            || count($values) !== count(array_unique($values))
        ) {
            throw new InvalidBusinessDefinition('Business view field references are empty, duplicated, or unbounded.');
        }
        foreach ($values as $value) {
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $value) !== 1) {
                throw new InvalidBusinessDefinition('A business view field reference is invalid.');
            }
        }
        return $values;
    }
}
