<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

/**
 * One named operation a business entity offers on its records, together with the capability guarding it.
 *
 * Actions are declared inside an entity definition and travel into the immutable published payload, so
 * the runtime resolves them from the version a record is pinned to rather than from live configuration.
 * `BusinessRecordService` looks the action up by handle, demands `$capability`, evaluates `$condition`
 * against the record's current values, and then performs `$transition` on the entity's workflow — an
 * action naming no transition has nothing the generated runtime can execute. An optional owner-scoped
 * handler/schema pair instead binds the action to a typed custom application handler and signed contract;
 * it is mutually exclusive with a workflow transition. The surface flags decide only where an action may
 * be offered; they never grant permission on their own. This constructor remains the validation point.
 *
 * @since  2.0.0
 */
final readonly class ActionDefinition
{
    /**
     * Declare an action, validating its identity, guard, surfaces, and precondition.
     *
     * @param   string       $handle         Lowercase snake-case name the action is invoked by.
     * @param   string       $label          Operator-facing name shown wherever the action is offered.
     * @param   string       $capability     Dotted capability an actor must hold to run the action.
     * @param   bool         $bulk           Whether the action may be offered against a selection of records.
     * @param   bool         $administrator  Whether the administrator surface may offer the action.
     * @param   bool         $portal         Whether the portal surface may offer the action.
     * @param   bool         $public         Always rejected when true; actions are never anonymous.
     * @param   bool         $highImpact     Marks the action consequential enough to warrant confirmation.
     * @param   ?Expression  $condition      Boolean precondition on the record; null leaves it unconditional.
     * @param   ?string      $transition     Workflow transition handle the action performs, or null for none.
     * @param   ?string      $handler        Owner-scoped custom handler reference, or null for generated behavior.
     * @param   ?string      $schema         Owner-scoped signed schema reference paired with `$handler`.
     *
     * @throws  InvalidBusinessDefinition  When the handle or label is malformed, the capability is not a dotted
     *          identifier, public execution is requested, neither the administrator nor the portal surface is
     *          declared, the transition or custom references are malformed, both execution mechanisms are
     *          declared, or the condition does not produce boolean.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $handle,
        public string $label,
        public string $capability,
        public bool $bulk = false,
        public bool $administrator = true,
        public bool $portal = false,
        public bool $public = false,
        public bool $highImpact = false,
        public ?Expression $condition = null,
        public ?string $transition = null,
        public ?string $handler = null,
        public ?string $schema = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1 || $label === '' || strlen($label) > 120) {
            throw new InvalidBusinessDefinition('A business action identity is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9-]*(?:[._:][a-z0-9-]+)*$/D', $capability) !== 1) {
            throw new InvalidBusinessDefinition('A business action capability is invalid.');
        }
        if ($public) {
            throw new InvalidBusinessDefinition('Business actions cannot be anonymously public.');
        }
        if (!$administrator && !$portal) {
            throw new InvalidBusinessDefinition('A business action requires an administrator or portal surface.');
        }
        if ($transition !== null && preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $transition) !== 1) {
            throw new InvalidBusinessDefinition('A business action workflow transition is invalid.');
        }
        if (($handler === null) !== ($schema === null)) {
            throw new InvalidBusinessDefinition(
                'A custom business action requires both handler and schema references.',
            );
        }
        if ($transition !== null && $handler !== null) {
            throw new InvalidBusinessDefinition('A business action cannot declare a transition and custom handler.');
        }
        foreach ([$handler, $schema] as $reference) {
            if (
                $reference !== null
                && (
                    strlen($reference) > 191
                    || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $reference) !== 1
                )
            ) {
                throw new InvalidBusinessDefinition('A custom business action reference is invalid.');
            }
        }
        if ($condition !== null && $condition->type !== 'boolean') {
            throw new InvalidBusinessDefinition('A business action condition must produce boolean.');
        }
    }

    /**
     * Rebuild an action from its canonical document, rejecting any property the contract does not name.
     *
     * The unknown-property check runs before anything is read, so a document written against a newer or
     * hand-edited schema fails at the import boundary rather than being silently truncated on the way in.
     *
     * @param   array<string, mixed>  $document  Decoded action document keyed by canonical snake-case name.
     *
     * @return  self  The validated action, having passed the same invariants as direct construction.
     *
     * @throws  InvalidBusinessDefinition  When the document carries an unknown property, a condition that is
     *          not a non-empty JSON object, a property of the wrong type, or values the constructor rejects.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        if (
            array_diff(array_keys($document), [
            'handle', 'label', 'capability', 'bulk', 'administrator', 'portal', 'public', 'high_impact',
            'condition', 'transition', 'handler', 'schema',
            ]) !== []
        ) {
            throw new InvalidBusinessDefinition('A business action contains an unknown property.');
        }
        $condition = $document['condition'] ?? null;
        if ($condition !== null && (!is_array($condition) || array_is_list($condition))) {
            throw new InvalidBusinessDefinition('A business action condition must be an object.');
        }
        /** @var non-empty-array<string, mixed>|null $condition */

        return new self(
            self::string($document, 'handle'),
            self::string($document, 'label'),
            self::string($document, 'capability'),
            self::boolean($document, 'bulk'),
            self::boolean($document, 'administrator', true),
            self::boolean($document, 'portal'),
            self::boolean($document, 'public'),
            self::boolean($document, 'high_impact'),
            is_array($condition) ? Expression::fromArray($condition) : null,
            self::nullableString($document, 'transition'),
            self::nullableString($document, 'handler'),
            self::nullableString($document, 'schema'),
        );
    }

    /**
     * Export the action as the document that becomes part of a published definition's canonical bytes.
     *
     * Every declared property is emitted, including the defaults, so the document round-trips through
     * `fromArray()` unchanged. Key order is irrelevant: `CanonicalDefinitionJson` sorts before hashing.
     *
     * @return  array<string, mixed>  Declared properties under their snake-case canonical keys, with the
     *          condition rendered as a nested document or null.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        $result = [
            'handle' => $this->handle,
            'label' => $this->label,
            'capability' => $this->capability,
            'bulk' => $this->bulk,
            'administrator' => $this->administrator,
            'portal' => $this->portal,
            'public' => $this->public,
            'high_impact' => $this->highImpact,
            'condition' => $this->condition?->toArray(),
            'transition' => $this->transition,
        ];
        if ($this->handler !== null && $this->schema !== null) {
            $result['handler'] = $this->handler;
            $result['schema'] = $this->schema;
        }

        return $result;
    }

    /**
     * Read a mandatory text property, trimmed of surrounding whitespace.
     *
     * @param   array<string, mixed>  $document  Decoded action document being read.
     * @param   string                $key       Canonical property name to read.
     *
     * @return  string  The trimmed value, never empty.
     *
     * @throws  InvalidBusinessDefinition  When the property is absent, is not a string, or is blank once trimmed.
     *
     * @since   2.0.0
     */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidBusinessDefinition('Business action property ' . $key . ' is required.');
        }
        return trim($value);
    }

    /**
     * Read a flag, falling back to the declared default when the property is absent or null.
     *
     * A present value is never coerced: `1`, `"true"` and `"1"` are all rejected, so a document cannot
     * flip a surface or impact flag through a loosely typed encoding.
     *
     * @param   array<string, mixed>  $document  Decoded action document being read.
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
            throw new InvalidBusinessDefinition('Business action property ' . $key . ' must be boolean.');
        }
        return $value;
    }

    /**
     * Read an optional text property, distinguishing "not declared" from "declared blank".
     *
     * @param   array<string, mixed>  $document  Decoded action document being read.
     * @param   string                $key       Canonical property name to read.
     *
     * @return  ?string  The trimmed value, or null when the property is absent or explicitly null.
     *
     * @throws  InvalidBusinessDefinition  When the property is present but is not a string, or is blank once
     *          trimmed; a blank string is a malformed declaration rather than an omission.
     *
     * @since   2.0.0
     */
    private static function nullableString(array $document, string $key): ?string
    {
        $value = $document[$key] ?? null;
        if ($value !== null && (!is_string($value) || trim($value) === '')) {
            throw new InvalidBusinessDefinition('Business action property ' . $key . ' must be null or a string.');
        }
        return is_string($value) ? trim($value) : null;
    }
}
