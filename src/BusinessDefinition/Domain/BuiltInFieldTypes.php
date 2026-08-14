<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

/**
 * The field-type catalogue every site can build definitions from without installing an extension.
 *
 * `FieldTypeRegistry` seeds itself from this list under `DefinitionOwner::core()`, which is what makes
 * these the identifiers a definition may name out of the box; anything else has to be contributed by a
 * package under its own namespace. Each entry fixes three things a field cannot renegotiate later: the
 * value family definitions work with, the physical storage family the schema compiler emits for it, and
 * the configuration keys a field of that type is allowed to set. The identifiers are the stable half of
 * the contract: published versions reference a type by name, so renaming one would strand every version
 * that already names it, and the catalogue grows by addition.
 *
 * @since  2.0.0
 */
final class BuiltInFieldTypes
{
    /**
     * Build the complete core catalogue, grouped by family in declaration order.
     *
     * A fresh list is constructed on every call, so this is a source of truth rather than a lookup:
     * `FieldTypeRegistry` consumes it once at construction, re-keys it by identifier, and answers
     * every later question about which types are active.
     *
     * @return  list<FieldTypeDefinition>  Every core type, each identifier carrying the `core.` prefix.
     *
     * @since   2.0.0
     */
    public static function all(): array
    {
        return [
            self::type('uuid', 'UUID', 'A canonical UUID identity.', 'string', 'guid'),
            self::type(
                'reference_identity',
                'Reference identity',
                'A validated external reference.',
                'string',
                'string',
            ),
            self::type('text', 'Text', 'Bounded plain text.', 'string', 'string', ['length']),
            self::type('rich_text', 'Rich text', 'Sanitized rich text.', 'string', 'text', ['length']),
            self::type('integer', 'Integer', 'A signed whole number.', 'integer', 'integer'),
            self::type(
                'decimal',
                'Decimal',
                'An exact arbitrary-precision decimal string.',
                'string',
                'string',
                ['precision', 'scale'],
            ),
            self::type(
                'money',
                'Money',
                'An exact amount paired with an ISO 4217 currency.',
                'object',
                'json',
                ['precision', 'scale', 'currency'],
            ),
            self::type(
                'quantity',
                'Quantity',
                'An exact quantity paired with a declared unit.',
                'object',
                'json',
                ['precision', 'scale', 'unit'],
            ),
            self::type('boolean', 'Boolean', 'A true or false value.', 'boolean', 'boolean'),
            self::type('enum', 'Choice', 'One value from a bounded declared set.', 'string', 'string', ['options']),
            self::type('date', 'Date', 'A calendar date without time.', 'string', 'date'),
            self::type('local_time', 'Local time', 'A local wall-clock time.', 'string', 'time'),
            self::type('instant', 'Instant', 'An absolute UTC timestamp.', 'string', 'datetime'),
            self::type(
                'zoned_datetime',
                'Zoned date and time',
                'An instant paired with an IANA timezone.',
                'object',
                'json',
            ),
            self::type('email', 'Email address', 'A normalized email address.', 'string', 'string'),
            self::type('url', 'URL', 'An absolute HTTP or HTTPS URL.', 'string', 'string'),
            self::type(
                'phone',
                'Phone-like text',
                'Normalized phone-like text without telephony assumptions.',
                'string',
                'string',
            ),
            self::type(
                'media_reference',
                'Media reference',
                'A reference to a Kumwe media asset.',
                'reference',
                'guid',
            ),
            self::type(
                'entity_reference',
                'Entity reference',
                'A reference to another declared business entity.',
                'reference',
                'guid',
                ['target'],
            ),
            self::type('embedded_value', 'Embedded value object', 'A bounded typed embedded value.', 'object', 'json'),
            self::type(
                'ordered_lines',
                'Ordered line collection',
                'An owned ordered collection of line values.',
                'collection',
                'json',
                ['target'],
            ),
            self::type(
                'bounded_json',
                'Bounded JSON',
                'An explicitly bounded JSON escape hatch.',
                'object',
                'json',
                ['max_bytes'],
            ),
            self::type(
                'secret',
                'Encrypted secret',
                'A value requiring application-level encryption.',
                'string',
                'text',
            ),
            self::type(
                'computed',
                'Server-computed value',
                'A deterministic server-computed value.',
                'string',
                'string',
            ),
            self::type(
                'sequence',
                'Allocated number',
                'A server-allocated gapless document number.',
                'string',
                'string',
                ['scope', 'reset', 'prefix', 'padding', 'timezone'],
            ),
        ];
    }

    /**
     * Construct one core type, applying the `core.` namespace prefix the registry checks ownership against.
     *
     * @param   string        $id                 Unprefixed identifier, such as `money` or `rich_text`.
     * @param   string        $label              Human-readable name shown when choosing a field type.
     * @param   string        $description        One-line explanation of what values the type holds.
     * @param   string        $valueType          Value family definitions work with, such as string or object.
     * @param   string        $storageType        Physical storage family the schema compiler emits for it.
     * @param   list<string>  $configurationKeys  Configuration keys a field of this type may set.
     *
     * @return  FieldTypeDefinition  The namespaced type, validated by its own constructor.
     *
     * @since   2.0.0
     */
    private static function type(
        string $id,
        string $label,
        string $description,
        string $valueType,
        string $storageType,
        array $configurationKeys = [],
    ): FieldTypeDefinition {
        return new FieldTypeDefinition(
            'core.' . $id,
            $label,
            $description,
            $valueType,
            $storageType,
            $configurationKeys,
        );
    }

    /**
     * Block instantiation; the catalogue is reachable through static members only.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
