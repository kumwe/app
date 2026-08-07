<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

final class BuiltInFieldTypes
{
    /** @return list<FieldTypeDefinition> */
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
        ];
    }

    /** @param list<string> $configurationKeys */
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

    private function __construct()
    {
    }
}
