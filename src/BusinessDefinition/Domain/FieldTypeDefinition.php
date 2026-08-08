<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

final readonly class FieldTypeDefinition
{
    /** @param list<string> $configurationKeys */
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
        $compatible = match ($storageType) {
            'guid' => in_array($valueType, ['reference', 'string'], true),
            'string', 'text' => in_array($valueType, ['reference', 'string'], true),
            'integer' => $valueType === 'integer',
            'boolean' => $valueType === 'boolean',
            'date', 'time', 'datetime' => $valueType === 'string',
            'json' => true,
            default => false,
        };
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

    /** @param array<string, mixed> $document */
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

    /** @return array<string, mixed> */
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

    /** @param array<string, mixed> $document */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidBusinessDefinition('Field-type property ' . $key . ' must be a non-empty string.');
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string> $allowed
     */
    private static function knownKeys(array $document, array $allowed): void
    {
        if (array_diff(array_keys($document), $allowed) !== []) {
            throw new InvalidBusinessDefinition('A field type contains an unknown property.');
        }
    }
}
