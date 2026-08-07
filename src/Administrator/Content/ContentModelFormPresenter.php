<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Content;

final readonly class ContentModelFormPresenter
{
    /**
     * @param array<string, mixed> $schema
     * @return list<array<string, mixed>>
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

    /** @param array<string, mixed> $field */
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
