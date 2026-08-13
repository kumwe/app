<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

/**
 * Typed layout metadata a `document` view uses to render a record as a business document.
 *
 * The block names which declared parts of the entity play which documentary role: the field whose value
 * is the human document number, labelled groups of meta fields, party relationships such as the billed
 * client, the owned-line collection rendered as the document body table, and the fields shown as the
 * totals block. Every reference is proven against the owning entity by `EntityTypeDefinition`, travels
 * inside the canonical checksummed definition bytes, and is policy-filtered by the surface catalog like
 * any other projection — declaring a role here never widens disclosure. Every role is optional, so a
 * document view may start as a bare header and grow as the entity does.
 *
 * @since  2.0.0
 */
final readonly class DocumentViewDefinition
{
    /**
     * Labelled meta blocks of field handles, rendered between the header and the line table.
     *
     * @var    list<array{label: string, fields: list<string>}>
     * @since  2.0.0
     */
    public array $groups;

    /**
     * Labelled party relationships, such as the account a document is billed or addressed to.
     *
     * @var    list<array{label: string, relationship: string}>
     * @since  2.0.0
     */
    public array $parties;

    /**
     * Field handles rendered as the totals block, in declaration order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $totals;

    /**
     * Declare the documentary roles, proving each list bounded and each handle well-formed.
     *
     * @param   ?string                                           $identity  Field whose value is the human
     *          document number, or null to fall back to the entity label and record date.
     * @param   list<array{label: string, fields: list<string>}>  $groups    Labelled meta field groups.
     * @param   list<array{label: string, relationship: string}>  $parties   Labelled party relationships.
     * @param   ?string                                           $lines     Owned-line relationship rendered
     *          as the document body table, or null for a document without lines.
     * @param   list<string>                                      $totals    Fields shown as the totals block.
     *
     * @throws  InvalidBusinessDefinition  When a list exceeds its ceiling, a label is blank or overlong, a
     *          group projects no field, a handle repeats within its list, or a handle is malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ?string $identity = null,
        array $groups = [],
        array $parties = [],
        public ?string $lines = null,
        array $totals = [],
    ) {
        foreach ([$identity, $lines] as $handle) {
            if ($handle !== null && preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
                throw new InvalidBusinessDefinition('A document view role handle is invalid.');
            }
        }
        if (count($groups) > 16 || count($parties) > 8) {
            throw new InvalidBusinessDefinition('A document view declares too many groups or parties.');
        }
        foreach ($groups as $group) {
            self::label($group['label']);
            self::handles($group['fields'], false, 64);
        }
        foreach ($parties as $party) {
            self::label($party['label']);
            self::handles([$party['relationship']], false, 1);
        }
        $this->groups = $groups;
        $this->parties = $parties;
        $this->totals = self::handles($totals, true, 16);
    }

    /**
     * Rebuild the block from its canonical document, rejecting any property the contract does not name.
     *
     * @param   array<string, mixed>  $document  Decoded document-view block keyed by canonical property name.
     *
     * @return  self  The validated block, having passed the same invariants as direct construction.
     *
     * @throws  InvalidBusinessDefinition  When the block carries an unknown property, a property of the wrong
     *          type, or values the constructor rejects.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        if (array_diff(array_keys($document), ['identity', 'groups', 'parties', 'lines', 'totals']) !== []) {
            throw new InvalidBusinessDefinition('A document view block contains an unknown property.');
        }

        return new self(
            self::nullableString($document, 'identity'),
            array_map(
                static fn (array $group): array => [
                    'label' => self::string($group, 'label'),
                    'fields' => self::strings($group, 'fields'),
                ],
                self::objects($document, 'groups', ['label', 'fields']),
            ),
            array_map(
                static fn (array $party): array => [
                    'label' => self::string($party, 'label'),
                    'relationship' => self::string($party, 'relationship'),
                ],
                self::objects($document, 'parties', ['label', 'relationship']),
            ),
            self::nullableString($document, 'lines'),
            self::strings($document, 'totals'),
        );
    }

    /**
     * Export the block as the normalized document that joins the view's canonical bytes.
     *
     * All five roles are always written, defaults included, so the block round-trips through `fromArray()`
     * unchanged and two authors declaring the same roles produce identical canonical bytes.
     *
     * @return  array<string, mixed>  Identity, groups, parties, lines and totals under their canonical keys.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity,
            'groups' => $this->groups,
            'parties' => $this->parties,
            'lines' => $this->lines,
            'totals' => $this->totals,
        ];
    }

    /**
     * List every field handle the block references, for the owning entity's declaration checks.
     *
     * @return  list<string>  Identity, group and totals handles in declaration order, repeats included.
     *
     * @since   2.0.0
     */
    public function fieldHandles(): array
    {
        $handles = $this->identity === null ? [] : [$this->identity];
        foreach ($this->groups as $group) {
            $handles = [...$handles, ...$group['fields']];
        }

        return [...$handles, ...$this->totals];
    }

    /**
     * Prove one documentary label is present and bounded.
     *
     * @param   string  $label  Operator-facing block or party label.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the label is blank or longer than 120 bytes.
     *
     * @since   2.0.0
     */
    private static function label(string $label): void
    {
        if ($label === '' || strlen($label) > 120) {
            throw new InvalidBusinessDefinition('A document view label is invalid.');
        }
    }

    /**
     * Prove one handle list bounded, repeat-free, and made only of well-formed handles.
     *
     * @param   list<string>  $values      Handles declared for one documentary role.
     * @param   bool          $mayBeEmpty  Whether an empty list is acceptable for the role.
     * @param   int           $limit       Maximum number of handles the role admits.
     *
     * @return  list<string>  The same handles, unchanged and in declaration order.
     *
     * @throws  InvalidBusinessDefinition  When the list is empty but required, exceeds its ceiling, repeats
     *          a handle, or holds a malformed handle.
     *
     * @since   2.0.0
     */
    private static function handles(array $values, bool $mayBeEmpty, int $limit): array
    {
        if (
            (!$mayBeEmpty && $values === [])
            || count($values) > $limit
            || count($values) !== count(array_unique($values))
        ) {
            throw new InvalidBusinessDefinition('Document view handles are empty, duplicated, or unbounded.');
        }
        foreach ($values as $value) {
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $value) !== 1) {
                throw new InvalidBusinessDefinition('A document view handle reference is invalid.');
            }
        }

        return $values;
    }

    /**
     * Read an optional non-blank text property without repairing malformed input.
     *
     * @param   array<string, mixed>  $document  Decoded block being read.
     * @param   string                $key       Optional canonical property name.
     *
     * @return  ?string  Trimmed value, or null when the property is absent or explicitly null.
     *
     * @throws  InvalidBusinessDefinition  When a present value is non-string or blank.
     *
     * @since   2.0.0
     */
    private static function nullableString(array $document, string $key): ?string
    {
        $value = $document[$key] ?? null;
        if ($value !== null && (!is_string($value) || trim($value) === '')) {
            throw new InvalidBusinessDefinition('Document view property ' . $key . ' must be null or a string.');
        }

        return is_string($value) ? trim($value) : null;
    }

    /**
     * Read a mandatory text property from one nested group or party object.
     *
     * @param   array<string, mixed>  $document  Decoded nested object being read.
     * @param   string                $key       Canonical property name to read.
     *
     * @return  string  The trimmed value, never empty.
     *
     * @throws  InvalidBusinessDefinition  When the property is absent, non-string, or blank once trimmed.
     *
     * @since   2.0.0
     */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidBusinessDefinition('Document view property ' . $key . ' is required.');
        }

        return trim($value);
    }

    /**
     * Read one list of handle strings, treating an absent property as an empty list.
     *
     * @param   array<string, mixed>  $document  Decoded block or nested object being read.
     * @param   string                $key       Canonical property name to read.
     *
     * @return  list<string>  The declared entries in document order; empty when the property is absent.
     *
     * @throws  InvalidBusinessDefinition  When the property is not a JSON array or holds a non-string.
     *
     * @since   2.0.0
     */
    private static function strings(array $document, string $key): array
    {
        $value = $document[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidBusinessDefinition('Document view property ' . $key . ' must be a list.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidBusinessDefinition('Document view property ' . $key . ' must contain strings.');
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Read one list of nested objects, each restricted to the given canonical keys.
     *
     * @param   array<string, mixed>  $document  Decoded block being read.
     * @param   string                $key       Canonical property name to read.
     * @param   list<string>          $keys      Exact property names each nested object may carry.
     *
     * @return  list<array<string, mixed>>  The declared objects in document order.
     *
     * @throws  InvalidBusinessDefinition  When the property is not a list of objects or a nested object
     *          carries an unknown property.
     *
     * @since   2.0.0
     */
    private static function objects(array $document, string $key, array $keys): array
    {
        $value = $document[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidBusinessDefinition('Document view property ' . $key . ' must be a list.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                throw new InvalidBusinessDefinition('Document view property ' . $key . ' must contain objects.');
            }
            if (array_diff(array_keys($item), $keys) !== []) {
                throw new InvalidBusinessDefinition('Document view property ' . $key . ' has an unknown member.');
            }
            /** @var array<string, mixed> $item */
            $result[] = $item;
        }

        return $result;
    }
}
