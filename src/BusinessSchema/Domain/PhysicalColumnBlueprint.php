<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Domain;

use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;

/**
 * Canonical description of one column of a physical table, restricted to the portable Doctrine subset.
 *
 * The planner diffs these blueprints and the executor verifies live columns against them, so a column has
 * to mean the same thing on every supported engine: the Doctrine type comes from a fixed list, only the
 * portable options are accepted, and nullability is a property of its own rather than a `notnull` option
 * an engine could fold differently. A default is proven expressible in the exact type it belongs to, and
 * the options map is proven canonically encodable and key sorted, so two equal columns always serialize —
 * and therefore checksum — identically.
 *
 * @since  2.0.0
 */
final readonly class PhysicalColumnBlueprint
{
    /**
     * Doctrine type names a column may declare, the subset that behaves alike on every supported engine.
     *
     * @var    list<string>
     * @since  2.0.0
     */
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

    /**
     * Doctrine options a column may carry; every other option is engine tuning and is refused.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const OPTION_KEYS = [
        'length',
        'precision',
        'scale',
        'fixed',
        'autoincrement',
        'default',
        'comment',
    ];

    /**
     * Portable Doctrine options for the column, key sorted so equal columns serialize identically.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $options;

    /**
     * Assemble a column and prove its type, options, and default are portable and mutually consistent.
     *
     * @param   string                $logicalName   Handle a plan operation and the compiler name this column by.
     * @param   string                $physicalName  Installed column name, already compiled to the portable grammar.
     * @param   string                $doctrineType  One of the accepted Doctrine type names.
     * @param   array<string, mixed>  $options       Portable Doctrine options; key sorted before they are stored.
     * @param   bool                  $nullable      Whether the installed column accepts NULL.
     *
     * @throws  InvalidBusinessSchema  When either name breaks its grammar, the Doctrine type is outside the
     *          portable set, an option is unknown or expresses nullability, a decimal lacks a valid precision
     *          and scale, a length or fixed option is malformed or sits on a type that carries no length, an
     *          autoincrement column is nullable or not an integer, the comment is not a string of at most 255
     *          bytes, or the default does not match the column's exact type.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the options hold a value
     *          canonical JSON cannot reproduce, such as a float or an object.
     *
     * @since   2.0.0
     */
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

    /**
     * Rebuild a column from its persisted document, revalidating every rule the constructor applies.
     *
     * @param   array<string, mixed>  $document  Stored column object, as written by `toArray()`.
     *
     * @return  self  The revalidated column, with its options back in canonical order.
     *
     * @throws  InvalidBusinessSchema  When the document carries an unknown property, a field is missing or
     *          misshapen, or any column rule fails.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the stored options hold a
     *          value canonical JSON cannot reproduce.
     *
     * @since   2.0.0
     */
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

    /**
     * Export the column in the shape that is persisted inside a table blueprint.
     *
     * @return  array<string, mixed>  Keyed `logical_name`, `physical_name`, `doctrine_type`, `options`, and
     *          `nullable`, with the options already in canonical order.
     *
     * @since   2.0.0
     */
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

    /**
     * Refuse a default the column's exact Doctrine type cannot carry.
     *
     * Defaults travel through canonical JSON and are later compared against live schema state, so each has
     * to arrive already in the representation its type reproduces — a decimal as a base-10 numeric string
     * rather than a float, a temporal value as a string — instead of being coerced on the way in. The two
     * binary types, `binary` and `blob`, accept no default other than null.
     *
     * @param   string  $doctrineType  Doctrine type the default must be expressible in.
     * @param   mixed   $default       Candidate default exactly as it arrived in the options map.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the default does not match the type it is declared against.
     *
     * @since   2.0.0
     */
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
