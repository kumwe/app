<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Domain;

use InvalidArgumentException;

/**
 * One named property of a content type schema, paired with whether the type requires it.
 *
 * `ContentTypeDefinition::fields()` projects the schema's `properties` map into these so that form
 * builders and API presenters can iterate a content type field by field without parsing raw JSON
 * Schema themselves. The fragment is carried through unchanged: this value describes the field, it
 * does not enforce it — `JsonSchemaValidator` remains the only thing that checks values against it.
 *
 * @since  2.0.0
 */
final readonly class FieldDefinition
{
    /**
     * Capture one field of a content type schema.
     *
     * @param   string                $key       Field key as it appears under the schema's `properties`.
     * @param   array<string, mixed>  $schema    JSON Schema fragment describing this field alone.
     * @param   bool                  $required  Whether the content type lists this key under `required`.
     *
     * @throws  InvalidArgumentException  When the key is not a lowercase identifier or the fragment is a list.
     *
     * @since   2.0.0
     */
    public function __construct(public string $key, public array $schema, public bool $required)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1) {
            throw new InvalidArgumentException('A field key must be a lowercase identifier.');
        }
        if ($schema !== [] && array_is_list($schema)) {
            throw new InvalidArgumentException('A field definition must contain a JSON Schema object.');
        }
    }

    /**
     * Export the field in the shape content model API responses and form builders consume.
     *
     * @return  array{key: string, schema: array<string, mixed>, required: bool}  One `fields` entry.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'schema' => $this->schema, 'required' => $this->required];
    }
}
