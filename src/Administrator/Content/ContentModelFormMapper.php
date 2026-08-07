<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Content;

use InvalidArgumentException;

final readonly class ContentModelFormMapper
{
    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    public function contentTypeSchema(array $form): array
    {
        $properties = [];
        $required = [];
        for ($index = 0; $index < 100; $index++) {
            $key = trim($form['field_' . $index . '_key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1 || isset($properties[$key])) {
                throw new InvalidArgumentException(sprintf('Content field %s is invalid or duplicated.', $key));
            }
            $type = $form['field_' . $index . '_type'] ?? 'string';
            $schema = $this->fieldSchema($form, $index, $type);
            $title = trim($form['field_' . $index . '_title'] ?? '');
            $description = trim($form['field_' . $index . '_description'] ?? '');
            if ($title !== '') {
                $schema['title'] = $title;
            }
            if ($description !== '') {
                $schema['description'] = $description;
            }
            $properties[$key] = $schema;
            if (($form['field_' . $index . '_required'] ?? '') === '1') {
                $required[] = $key;
            }
        }
        if ($properties === []) {
            throw new InvalidArgumentException('A content type requires at least one graphical field.');
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, string> $form
     * @return list<array<string, mixed>>
     */
    public function workflowStates(array $form): array
    {
        $states = [];
        $keys = [];
        $initialState = trim($form['initial_state_key'] ?? '');
        for ($index = 0; $index < 100; $index++) {
            $key = trim($form['state_' . $index . '_key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9_-]{0,62}$/D', $key) !== 1 || isset($keys[$key])) {
                throw new InvalidArgumentException(sprintf('Workflow state %s is invalid or duplicated.', $key));
            }
            $keys[$key] = true;
            $name = trim($form['state_' . $index . '_name'] ?? '');
            if ($name === '') {
                throw new InvalidArgumentException(sprintf('Workflow state %s requires a name.', $key));
            }
            $states[] = [
                'key' => $key,
                'name' => $name,
                'initial' => $initialState === $key,
                'public' => ($form['state_' . $index . '_public'] ?? '') === '1',
            ];
        }
        if ($states === []) {
            throw new InvalidArgumentException('A workflow requires at least one state.');
        }
        if ($initialState === '' || !isset($keys[$initialState])) {
            throw new InvalidArgumentException('A workflow requires one valid initial state.');
        }
        return $states;
    }

    /**
     * @param array<string, string> $form
     * @return list<array<string, mixed>>
     */
    public function workflowTransitions(array $form): array
    {
        $transitions = [];
        for ($index = 0; $index < 200; $index++) {
            $from = trim($form['transition_' . $index . '_from'] ?? '');
            $to = trim($form['transition_' . $index . '_to'] ?? '');
            if ($from === '' && $to === '') {
                continue;
            }
            $capability = trim($form['transition_' . $index . '_capability'] ?? '');
            if (
                preg_match('/^[a-z][a-z0-9_-]{0,62}$/D', $from) !== 1
                || preg_match('/^[a-z][a-z0-9_-]{0,62}$/D', $to) !== 1
                || preg_match('/^[a-z][a-z0-9.:-]{0,126}$/D', $capability) !== 1
            ) {
                throw new InvalidArgumentException('Workflow transitions require valid states and a capability.');
            }
            $transitions[] = [
                'from' => $from,
                'to' => $to,
                'required_capability' => $capability,
            ];
        }
        return $transitions;
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private function fieldSchema(array $form, int $index, string $type): array
    {
        $schema = match ($type) {
            'text' => ['type' => 'string', 'maxLength' => 50_000],
            'integer' => ['type' => 'integer'],
            'number' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'date-time' => ['type' => 'string', 'format' => 'date-time'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'url' => ['type' => 'string', 'format' => 'uri'],
            'media' => ['type' => 'string', 'format' => 'uri-reference', 'x-kumwe-field' => 'media'],
            'string-list' => ['type' => 'array', 'items' => ['type' => 'string']],
            'string' => ['type' => 'string'],
            default => throw new InvalidArgumentException(sprintf('Content field type %s is unsupported.', $type)),
        };

        $minimum = trim($form['field_' . $index . '_minimum'] ?? '');
        $maximum = trim($form['field_' . $index . '_maximum'] ?? '');
        if (in_array($type, ['integer', 'number'], true)) {
            if ($minimum !== '') {
                $this->assertNumber($minimum, $type, 'minimum');
                $schema['minimum'] = $type === 'integer' ? (int) $minimum : (float) $minimum;
            }
            if ($maximum !== '') {
                $this->assertNumber($maximum, $type, 'maximum');
                $schema['maximum'] = $type === 'integer' ? (int) $maximum : (float) $maximum;
            }
        } elseif (in_array($type, ['string', 'text'], true)) {
            if ($minimum !== '') {
                $this->assertLength($minimum, 'minimum');
                $schema['minLength'] = (int) $minimum;
            }
            if ($maximum !== '') {
                $this->assertLength($maximum, 'maximum');
                $schema['maxLength'] = (int) $maximum;
            }
        }
        $splitOptions = preg_split('/\R/u', trim($form['field_' . $index . '_options'] ?? ''));
        $options = $splitOptions === false ? [] : $splitOptions;
        $options = array_values(array_filter(
            array_map('trim', $options),
            static fn (string $option): bool => $option !== '',
        ));
        if ($options !== [] && in_array($type, ['string', 'text'], true)) {
            $schema['enum'] = $options;
        }

        return $schema;
    }

    private function assertNumber(string $value, string $type, string $boundary): void
    {
        $valid = $type === 'integer'
            ? preg_match('/^-?[0-9]+$/D', $value) === 1
            : is_numeric($value) && is_finite((float) $value);
        if (!$valid) {
            throw new InvalidArgumentException(sprintf('The field %s must be a valid %s.', $boundary, $type));
        }
    }

    private function assertLength(string $value, string $boundary): void
    {
        if (preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The field %s length must be a non-negative integer.',
                $boundary,
            ));
        }
    }
}
