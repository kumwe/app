<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;

final readonly class PhysicalColumnBlueprint
{
    private const DOCTRINE_TYPES = [
        'ascii_string',
        'bigint',
        'binary',
        'blob',
        'boolean',
        'date_immutable',
        'datetime_immutable',
        'datetimetz_immutable',
        'decimal',
        'guid',
        'integer',
        'json',
        'smallint',
        'string',
        'text',
        'time_immutable',
    ];

    private const OPTION_KEYS = [
        'length',
        'precision',
        'scale',
        'fixed',
        'autoincrement',
        'default',
        'comment',
    ];

    /** @var array<string, mixed> */
    public array $options;

    /** @param array<string, mixed> $options */
    public function __construct(
        public string $logicalName,
        public string $physicalName,
        public string $doctrineType,
        array $options = [],
        public bool $nullable = false,
    ) {
        SchemaDocument::assertIdentifier($logicalName, 'The physical column logical name');
        SchemaDocument::assertPhysicalIdentifier($physicalName, 'The physical column name');
        if (!in_array($doctrineType, self::DOCTRINE_TYPES, true)) {
            throw new InvalidBusinessSchema('The physical column Doctrine type is not portable or supported.');
        }
        SchemaDocument::assertObjectValue($options, 'Physical column options');
        if (array_diff(array_keys($options), self::OPTION_KEYS) !== []) {
            throw new InvalidBusinessSchema('A physical column contains a non-portable Doctrine option.');
        }
        if (array_key_exists('notnull', $options) || array_key_exists('nullable', $options)) {
            throw new InvalidBusinessSchema('Column nullability must use the explicit nullable property.');
        }
        if (array_key_exists('precision', $options) || array_key_exists('scale', $options)) {
            $precision = $options['precision'] ?? null;
            $scale = $options['scale'] ?? null;
            if (
                $doctrineType !== 'decimal' || !is_int($precision) || !is_int($scale)
                || $precision < 1 || $precision > 65 || $scale < 0 || $scale > 30 || $scale > $precision
            ) {
                throw new InvalidBusinessSchema('A decimal physical column requires a valid precision and scale.');
            }
        }
        if ($doctrineType === 'decimal' && (!isset($options['precision']) || !isset($options['scale']))) {
            throw new InvalidBusinessSchema('A decimal physical column requires explicit precision and scale.');
        }
        if (
            array_key_exists('length', $options)
            && (
                !is_int($options['length']) || $options['length'] < 1 || $options['length'] > 16_383
                || !in_array($doctrineType, ['ascii_string', 'binary', 'string'], true)
            )
        ) {
            throw new InvalidBusinessSchema('A physical column length must be a positive integer.');
        }
        if (
            array_key_exists('fixed', $options)
            && (!is_bool($options['fixed']) || !in_array($doctrineType, ['ascii_string', 'binary', 'string'], true))
        ) {
            throw new InvalidBusinessSchema('The fixed option is invalid for this physical column.');
        }
        if (
            array_key_exists('autoincrement', $options)
            && (!is_bool($options['autoincrement']) || $nullable)
        ) {
            throw new InvalidBusinessSchema('An autoincrement physical column must be non-nullable.');
        }
        if (
            ($options['autoincrement'] ?? false) === true
            && !in_array($doctrineType, ['bigint', 'integer', 'smallint'], true)
        ) {
            throw new InvalidBusinessSchema('Only an integer physical column can autoincrement.');
        }
        if (
            array_key_exists('comment', $options)
            && (!is_string($options['comment']) || strlen($options['comment']) > 255)
        ) {
            throw new InvalidBusinessSchema('A physical column comment is invalid.');
        }
        if (array_key_exists('default', $options)) {
            self::assertDefault($doctrineType, $options['default']);
        }
        CanonicalDefinitionJson::encode($options);
        ksort($options, SORT_STRING);
        $this->options = $options;
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            ['logical_name', 'physical_name', 'doctrine_type', 'options', 'nullable'],
            'A physical column blueprint',
        );

        return new self(
            SchemaDocument::string($document, 'logical_name'),
            SchemaDocument::string($document, 'physical_name'),
            SchemaDocument::string($document, 'doctrine_type'),
            SchemaDocument::object($document, 'options') ?? [],
            SchemaDocument::boolean($document, 'nullable'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'logical_name' => $this->logicalName,
            'physical_name' => $this->physicalName,
            'doctrine_type' => $this->doctrineType,
            'options' => $this->options,
            'nullable' => $this->nullable,
        ];
    }

    private static function assertDefault(string $doctrineType, mixed $default): void
    {
        $valid = match ($doctrineType) {
            'boolean' => is_bool($default),
            'bigint', 'integer', 'smallint' => is_int($default),
            'decimal' => is_string($default)
                && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $default) === 1,
            'ascii_string', 'date_immutable', 'datetime_immutable', 'datetimetz_immutable', 'guid', 'string', 'text',
            'time_immutable' => is_string($default),
            'json' => is_array($default) || is_bool($default) || is_int($default) || is_string($default)
                || $default === null,
            default => $default === null,
        };
        if (!$valid) {
            throw new InvalidBusinessSchema('A physical column default does not match its exact Doctrine type.');
        }
    }
}
