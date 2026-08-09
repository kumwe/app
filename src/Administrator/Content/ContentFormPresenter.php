<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Content;

use Kumwe\CMS\Content\Domain\ContentTypeDefinition;

/**
 * Turns a content type's JSON schema into the field descriptors the administrator editor renders.
 *
 * The editor template knows nothing about JSON Schema: it walks a list of ready-made descriptors and
 * emits one input each. This presenter does that translation — picking a widget per property, giving
 * every leaf a path-encoded input name, and formatting stored values for display — so adding a field
 * to a content type is enough to make it editable. It is the write half of `ContentFormDataMapper`,
 * which reads those names back; changing a name here without changing it there breaks the round trip.
 *
 * @since  2.0.0
 */
final readonly class ContentFormPresenter
{
    /**
     * Build the editor field list for one content type, pre-filled with an entry's stored values.
     *
     * @param   ContentTypeDefinition  $definition  Content type whose schema decides the fields shown.
     * @param   array<string, mixed>   $values      Stored entry data; empty falls back to the defaults.
     *
     * @return  list<array<string, mixed>>  Descriptors in schema order, groups carrying their children.
     *
     * @since   2.0.0
     */
    public function fields(ContentTypeDefinition $definition, array $values = []): array
    {
        return $this->objectFields($definition->schema(), $values, []);
    }

    /**
     * Describe one object level of the schema, recursing into nested objects as grouped children.
     *
     * Every descriptor carries a `kind` of either `group` or `field`; a group holds its children in
     * place of an input. Input names and element ids are built from the property path, which is what
     * lets `ContentFormDataMapper` restore the nesting without the template knowing about it.
     *
     * @param   array<string, mixed>  $schema  Object schema level, holding `properties` and `required`.
     * @param   array<string, mixed>  $values  Stored values for this level, keyed by property name.
     * @param   list<string>          $path    Property names walked so far; empty at the root.
     *
     * @return  list<array<string, mixed>>  Descriptors in the order the schema lists its properties.
     *
     * @since   2.0.0
     */
    private function objectFields(array $schema, array $values, array $path): array
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        if (!is_array($properties) || array_is_list($properties) || !is_array($required)) {
            return [];
        }

        $fields = [];
        foreach ($properties as $key => $fieldSchema) {
            if (!is_string($key) || !is_array($fieldSchema) || array_is_list($fieldSchema)) {
                continue;
            }
            /** @var array<string, mixed> $fieldSchema */
            $fieldPath = [...$path, $key];
            $value = $values[$key] ?? ($fieldSchema['default'] ?? null);
            $type = is_string($fieldSchema['type'] ?? null) ? $fieldSchema['type'] : 'string';
            if ($type === 'object') {
                $nested = is_array($value) && ($value === [] || !array_is_list($value)) ? $value : [];
                /** @var array<string, mixed> $nested */
                $fields[] = [
                    'kind' => 'group',
                    'key' => $key,
                    'label' => $this->label($key, $fieldSchema),
                    'description' => $this->description($fieldSchema),
                    'children' => $this->objectFields($fieldSchema, $nested, $fieldPath),
                ];
                continue;
            }

            $enum = $fieldSchema['enum'] ?? [];
            $options = [];
            if (is_array($enum) && array_is_list($enum)) {
                foreach ($enum as $option) {
                    if (is_string($option) || is_int($option) || is_float($option)) {
                        $options[] = ['value' => (string) $option, 'label' => (string) $option];
                    }
                }
            }
            $input = $this->inputType($key, $type, $fieldSchema, $options !== []);
            $fields[] = [
                'kind' => 'field',
                'key' => $key,
                'name' => 'field__' . implode('__', $fieldPath),
                'id' => 'content-field-' . implode('-', $fieldPath),
                'label' => $this->label($key, $fieldSchema),
                'description' => $this->description($fieldSchema),
                'input' => $input,
                'required' => in_array($key, $required, true),
                'value' => $this->displayValue($type, $value),
                'checked' => $type === 'boolean' && $value === true,
                'options' => $options,
                'min' => $fieldSchema['minimum'] ?? null,
                'max' => $fieldSchema['maximum'] ?? null,
                'min_length' => $fieldSchema['minLength'] ?? null,
                'max_length' => $fieldSchema['maxLength'] ?? null,
                'pattern' => is_string($fieldSchema['pattern'] ?? null) ? $fieldSchema['pattern'] : null,
                'step' => $type === 'number' ? 'any' : null,
                'accepts_media' => $type === 'string' && ($fieldSchema['x-kumwe-field'] ?? null) === 'media',
            ];
        }

        return $fields;
    }

    /**
     * Resolve the caption shown above a field's input.
     *
     * @param   string                $key     Property name, the source of the fallback caption.
     * @param   array<string, mixed>  $schema  Property schema, whose `title` wins when it is non-blank.
     *
     * @return  string  The schema title, or the property name title-cased with underscores as spaces.
     *
     * @since   2.0.0
     */
    private function label(string $key, array $schema): string
    {
        $title = $schema['title'] ?? null;
        return is_string($title) && trim($title) !== ''
            ? trim($title)
            : ucwords(str_replace('_', ' ', $key));
    }

    /**
     * Resolve the hint text rendered under a field's input.
     *
     * @param   array<string, mixed>  $schema  Property schema, whose `description` supplies the hint.
     *
     * @return  string  The trimmed description, or an empty string when the schema carries none.
     *
     * @since   2.0.0
     */
    private function description(array $schema): string
    {
        $description = $schema['description'] ?? null;
        return is_string($description) ? trim($description) : '';
    }

    /**
     * Choose the widget one property is rendered with.
     *
     * An enumeration always becomes a select; otherwise the JSON type decides, then the string
     * `format`, and finally two conventions the schema cannot express — a property named `body` gets
     * the rich text editor, and `description` or any long string gets a textarea instead of an input.
     *
     * @param   string                $key         Property name, consulted for the two name conventions.
     * @param   string                $type        JSON Schema type of the property, `string` by default.
     * @param   array<string, mixed>  $schema      Property schema, read for `format` and `maxLength`.
     * @param   bool                  $hasOptions  Whether an enumeration produced selectable options.
     *
     * @return  string  Widget name the editor template branches on, such as `select` or `rich-text`.
     *
     * @since   2.0.0
     */
    private function inputType(string $key, string $type, array $schema, bool $hasOptions): string
    {
        if ($hasOptions) {
            return 'select';
        }
        if ($type === 'boolean') {
            return 'checkbox';
        }
        if ($type === 'array') {
            return 'lines';
        }
        if ($type === 'integer' || $type === 'number') {
            return 'number';
        }
        $format = $schema['format'] ?? null;
        if ($format === 'date') {
            return 'date';
        }
        if ($format === 'date-time') {
            return 'datetime-local';
        }
        if ($format === 'email') {
            return 'email';
        }
        if ($format === 'uri') {
            return 'url';
        }
        if ($format === 'uri-reference') {
            return 'text';
        }
        $maximum = $schema['maxLength'] ?? null;
        if ($key === 'body') {
            return 'rich-text';
        }

        return $key === 'description' || (is_int($maximum) && $maximum > 240) ? 'textarea' : 'text';
    }

    /**
     * Format a stored value for the `value` attribute of its input.
     *
     * @param   string  $type   JSON Schema type of the property, which selects the formatting rule.
     * @param   mixed   $value  Stored value, or null when the entry holds nothing for this property.
     *
     * @return  string  Lists join on line breaks, date-times truncate to minutes, booleans render empty.
     *
     * @since   2.0.0
     */
    private function displayValue(string $type, mixed $value): string
    {
        if ($type === 'array' && is_array($value) && array_is_list($value)) {
            return implode("\n", array_map(
                static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
                $value,
            ));
        }
        if ($type === 'boolean' || $value === null) {
            return '';
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            if ($type === 'string' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/D', (string) $value) === 1) {
                return substr((string) $value, 0, 16);
            }
            return (string) $value;
        }

        return '';
    }
}
