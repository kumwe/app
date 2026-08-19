<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

/**
 * One field type a business field may declare, pairing the value family with its storage family.
 *
 * Field types are the vocabulary `FieldDefinition::$type` draws from: `BuiltInFieldTypes` supplies the
 * `core.*` set and a schema-2 package contributes its own under its extension namespace. Construction
 * fixes the two halves that have to agree — the logical family a caller sees and the physical family the
 * schema compiler emits a column for — and refuses a pairing no conversion could serve, so an
 * unstorable type is rejected when it is declared rather than when a table is built. The configuration
 * keys are the closed set a field of this type may set; `BusinessDefinitionValidator` rejects any key
 * outside it. A published identifier is pinned to the bytes it shipped with, so an extension may revise
 * its types only by declaring new identifiers.
 *
 * @since  2.0.0
 */
final readonly class FieldTypeDefinition
{
    /**
     * Declare a field type and reject a value and storage pairing no conversion could serve.
     *
     * @param   string        $id                 Namespaced identifier fields declare, such as `core.text`.
     * @param   string        $label              Operator-facing name shown when choosing a type.
     * @param   string        $description        Short explanation shown beside the label.
     * @param   string        $valueType          Logical family callers exchange, such as `string`.
     * @param   string        $storageType        Physical family a column is emitted in, such as `json`.
     * @param   list<string>  $configurationKeys  The only configuration keys a field of this type may set.
     *
     * @throws  InvalidBusinessDefinition  When the identifier is not namespaced, the metadata is empty or
     *          oversized, either family is unsupported, the two families cannot be converted into one
     *          another, or the configuration keys are duplicated, unbounded, or malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public string $valueType,
        public string $storageType,
        public array $configurationKeys = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $id) !== 1 || strlen($id) > 191) {
            throw new InvalidBusinessDefinition('A field-type identifier must be namespaced.');
        }
        if ($label === '' || strlen($label) > 120 || $description === '' || strlen($description) > 500) {
            throw new InvalidBusinessDefinition('A field type requires bounded human-readable metadata.');
        }
        if (!in_array($valueType, ['string', 'integer', 'boolean', 'object', 'collection', 'reference'], true)) {
            throw new InvalidBusinessDefinition('A field type value family is unsupported.');
        }
        if (
            !in_array(
                $storageType,
                ['guid', 'string', 'text', 'integer', 'boolean', 'date', 'time', 'datetime', 'json'],
                true,
            )
        ) {
            throw new InvalidBusinessDefinition('A field type storage family is unsupported.');
        }
        if (in_array($storageType, ['guid', 'string', 'text'], true)) {
            $compatible = in_array($valueType, ['reference', 'string'], true);
        } elseif ($storageType === 'integer') {
            $compatible = $valueType === 'integer';
        } elseif ($storageType === 'boolean') {
            $compatible = $valueType === 'boolean';
        } elseif (in_array($storageType, ['date', 'time', 'datetime'], true)) {
            $compatible = $valueType === 'string';
        } else {
            $compatible = true;
        }
        if (!$compatible) {
            throw new InvalidBusinessDefinition(
                'A field type value family cannot be converted to its declared physical storage family.',
            );
        }
        if (count($configurationKeys) > 32 || count($configurationKeys) !== count(array_unique($configurationKeys))) {
            throw new InvalidBusinessDefinition('Field-type configuration keys are duplicated or unbounded.');
        }
        foreach ($configurationKeys as $key) {
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1) {
                throw new InvalidBusinessDefinition('A field-type configuration key is invalid.');
            }
        }
    }

    /**
     * Rebuild a field type from the canonical document `toArray()` writes.
     *
     * This is the boundary a package declaration and a stored field-type row both come through, so an
     * unknown key is refused rather than dropped: the document's bytes are the contract an existing
     * identifier is pinned to.
     *
     * @param   array<string, mixed>  $document  Canonical field-type document, keyed as it is stored.
     *
     * @return  self  The field type, with every construction rule already applied.
     *
     * @throws  InvalidBusinessDefinition  When a key is unknown, the configuration keys are not a list of
     *          strings, or the resulting type breaks a construction rule.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        self::knownKeys($document, ['id', 'label', 'description', 'value_type', 'storage_type', 'configuration_keys']);
        $configuration = $document['configuration_keys'] ?? [];
        if (!is_array($configuration) || !array_is_list($configuration)) {
            throw new InvalidBusinessDefinition('Field-type configuration_keys must be a list.');
        }
        $keys = [];
        foreach ($configuration as $key) {
            if (!is_string($key)) {
                throw new InvalidBusinessDefinition('Field-type configuration keys must be strings.');
            }
            $keys[] = $key;
        }

        return new self(
            self::string($document, 'id'),
            self::string($document, 'label'),
            self::string($document, 'description'),
            self::string($document, 'value_type'),
            self::string($document, 'storage_type'),
            $keys,
        );
    }

    /**
     * Export the field type as the document its published bytes are compared against.
     *
     * @return  array<string, mixed>  Identifier, labels, both families, and the configuration keys under
     *          their snake_case keys.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'value_type' => $this->valueType,
            'storage_type' => $this->storageType,
            'configuration_keys' => $this->configurationKeys,
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
            throw new InvalidBusinessDefinition('Field-type property ' . $key . ' must be a non-empty string.');
        }

        return trim($value);
    }

    /**
     * Refuse a document carrying any property outside the declared set.
     *
     * @param   array<string, mixed>  $document  Document whose keys are being checked.
     * @param   list<string>          $allowed   Every property name this release understands.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the document carries a key outside the allowed set.
     *
     * @since   2.0.0
     */
    private static function knownKeys(array $document, array $allowed): void
    {
        if (array_diff(array_keys($document), $allowed) !== []) {
            throw new InvalidBusinessDefinition('A field type contains an unknown property.');
        }
    }
}
