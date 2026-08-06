<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Content;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Content\Domain\ContentTypeDefinition;

final readonly class ContentFormDataMapper
{
    /**
     * @param array<array-key, mixed> $body
     * @return array<string, mixed>
     */
    public function map(ContentTypeDefinition $definition, array $body): array
    {
        [$present, $value] = $this->mapObject($definition->schema(), $body, []);
        if (!$present || !is_array($value) || array_is_list($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<array-key, mixed> $body */
    public function containsGeneratedFields(array $body): bool
    {
        foreach (array_keys($body) as $key) {
            if (is_string($key) && str_starts_with($key, 'field__')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<array-key, mixed> $body
     * @param list<string> $path
     * @return array{bool, array<string, mixed>}
     */
    private function mapObject(array $schema, array $body, array $path): array
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        if (!is_array($properties) || array_is_list($properties) || !is_array($required)) {
            return [false, []];
        }

        $result = [];
        $present = $path === [];
        foreach ($properties as $key => $fieldSchema) {
            if (!is_string($key) || !is_array($fieldSchema) || array_is_list($fieldSchema)) {
                continue;
            }
            /** @var array<string, mixed> $fieldSchema */
            $fieldPath = [...$path, $key];
            $isRequired = in_array($key, $required, true);
            $type = is_string($fieldSchema['type'] ?? null) ? $fieldSchema['type'] : 'string';
            if ($type === 'object') {
                [$childPresent, $child] = $this->mapObject($fieldSchema, $body, $fieldPath);
                if ($childPresent || $isRequired) {
                    $result[$key] = $child;
                    $present = true;
                }
                continue;
            }

            $name = 'field__' . implode('__', $fieldPath);
            $raw = $body[$name] ?? null;
            [$valuePresent, $value] = $this->mapValue($fieldSchema, $raw, $isRequired, $name);
            if ($valuePresent) {
                $result[$key] = $value;
                $present = true;
            }
        }

        return [$present, $result];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{bool, mixed}
     */
    private function mapValue(array $schema, mixed $raw, bool $required, string $name): array
    {
        $type = is_string($schema['type'] ?? null) ? $schema['type'] : 'string';
        if ($type === 'boolean') {
            return [$required || $raw !== null, in_array($raw, ['1', 'true', 'on'], true)];
        }
        if (!is_string($raw)) {
            if (array_key_exists('default', $schema)) {
                return [true, $schema['default']];
            }
            return [$required, $type === 'array' ? [] : ''];
        }

        if ($type === 'array') {
            $lines = preg_split('/\R/u', $raw) ?: [];
            $items = [];
            $itemSchema = $schema['items'] ?? ['type' => 'string'];
            if (!is_array($itemSchema) || array_is_list($itemSchema)) {
                $itemSchema = ['type' => 'string'];
            }
            /** @var array<string, mixed> $itemSchema */
            foreach ($lines as $index => $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                [, $items[]] = $this->mapValue($itemSchema, $line, true, $name . '[' . $index . ']');
            }
            return [$required || $items !== [], $items];
        }

        if ($raw === '' && !$required) {
            return [false, null];
        }
        if ($type === 'integer') {
            if (preg_match('/^-?[0-9]+$/D', $raw) !== 1) {
                throw new InvalidArgumentException(sprintf('The %s field must contain a whole number.', $name));
            }
            return [true, (int) $raw];
        }
        if ($type === 'number') {
            if (!is_numeric($raw) || !is_finite((float) $raw)) {
                throw new InvalidArgumentException(sprintf('The %s field must contain a number.', $name));
            }
            return [true, (float) $raw];
        }
        if (($schema['format'] ?? null) === 'date-time' && $raw !== '') {
            try {
                return [true, (new DateTimeImmutable($raw))->format(DATE_ATOM)];
            } catch (\Exception $exception) {
                throw new InvalidArgumentException(sprintf(
                    'The %s field must contain a valid date and time.',
                    $name,
                ), 0, $exception);
            }
        }

        return [true, $raw];
    }
}
