<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Content;

use Kumwe\CMS\Content\Domain\ContentTypeDefinition;

final readonly class ContentFormPresenter
{
    /**
     * @param array<string, mixed> $values
     * @return list<array<string, mixed>>
     */
    public function fields(ContentTypeDefinition $definition, array $values = []): array
    {
        return $this->objectFields($definition->schema(), $values, []);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $values
     * @param list<string> $path
     * @return list<array<string, mixed>>
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
                'accepts_media' => $type === 'string' && ($fieldSchema['format'] ?? null) === 'uri',
            ];
        }

        return $fields;
    }

    /** @param array<string, mixed> $schema */
    private function label(string $key, array $schema): string
    {
        $title = $schema['title'] ?? null;
        return is_string($title) && trim($title) !== ''
            ? trim($title)
            : ucwords(str_replace('_', ' ', $key));
    }

    /** @param array<string, mixed> $schema */
    private function description(array $schema): string
    {
        $description = $schema['description'] ?? null;
        return is_string($description) ? trim($description) : '';
    }

    /**
     * @param array<string, mixed> $schema
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
        $maximum = $schema['maxLength'] ?? null;
        if ($key === 'body') {
            return 'rich-text';
        }

        return $key === 'description' || (is_int($maximum) && $maximum > 240) ? 'textarea' : 'text';
    }

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
