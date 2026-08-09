<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Content;

/**
 * Rebuilds the administrator model builder rows from a content type schema that is already published.
 *
 * Opening an existing content type has to show the rows that produced its schema, including the
 * editor-facing type name — and JSON Schema does not record that name, so it has to be inferred back
 * from the type, format and extension keywords `ContentModelFormMapper` wrote. This presenter does
 * that inference, which is what lets a type be edited in the builder instead of collapsing to plain
 * strings on every save. The two classes must therefore agree on the same builder type vocabulary.
 *
 * @since  2.0.0
 */
final readonly class ContentModelFormPresenter
{
    /**
     * Describe a published content type schema as builder rows.
     *
     * @param   array<string, mixed>  $schema  Published object schema, with `properties` and `required`.
     *
     * @return  list<array<string, mixed>>  One row per property in schema order, with form-ready bounds
     *          and options; empty when the schema exposes no usable properties.
     *
     * @since   2.0.0
     */
    public function fields(array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        if (!is_array($properties) || array_is_list($properties) || !is_array($required)) {
            return [];
        }
        $fields = [];
        foreach ($properties as $key => $field) {
            if (!is_string($key) || !is_array($field) || array_is_list($field)) {
                continue;
            }
            /** @var array<string, mixed> $field */
            $fields[] = [
                'key' => $key,
                'title' => is_string($field['title'] ?? null) ? $field['title'] : ucwords(str_replace('_', ' ', $key)),
                'description' => is_string($field['description'] ?? null) ? $field['description'] : '',
                'type' => $this->type($field),
                'required' => in_array($key, $required, true),
                'minimum' => $field['minimum'] ?? $field['minLength'] ?? '',
                'maximum' => $field['maximum'] ?? $field['maxLength'] ?? '',
                'options' => is_array($field['enum'] ?? null) && array_is_list($field['enum'])
                    ? implode("\n", array_map(
                        static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                        $field['enum'],
                    ))
                    : '',
            ];
        }
        return $fields;
    }

    /**
     * Infer the builder type name a stored property schema was produced from.
     *
     * @param   array<string, mixed>  $field  Property schema whose type, format and items decide the row.
     *
     * @return  string  Builder type name, falling back to `string` for anything not recognised.
     *
     * @since   2.0.0
     */
    private function type(array $field): string
    {
        $type = $field['type'] ?? 'string';
        $format = $field['format'] ?? null;
        $items = $field['items'] ?? null;
        if ($type === 'array' && is_array($items) && ($items['type'] ?? null) === 'string') {
            return 'string-list';
        }
        if ($type === 'string' && $format === 'date') {
            return 'date';
        }
        if ($type === 'string' && $format === 'date-time') {
            return 'date-time';
        }
        if ($type === 'string' && $format === 'email') {
            return 'email';
        }
        if ($type === 'string' && $format === 'uri') {
            return 'url';
        }
        if ($type === 'string' && ($field['x-kumwe-field'] ?? null) === 'media') {
            return 'media';
        }
        if ($type === 'string' && is_int($field['maxLength'] ?? null) && $field['maxLength'] > 240) {
            return 'text';
        }
        return is_string($type) && in_array($type, ['string', 'integer', 'number', 'boolean'], true)
            ? $type
            : 'string';
    }
}
