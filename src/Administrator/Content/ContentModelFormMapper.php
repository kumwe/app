<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Content;

use InvalidArgumentException;

/**
 * Translates the administrator model builder form into stored content type and workflow documents.
 *
 * The content models screen offers two ways to define a model: paste raw JSON, or fill in a repeating
 * builder form. This mapper backs the builder, reading the numbered `field_N_*`, `state_N_*` and
 * `transition_N_*` inputs and assembling the JSON Schema and workflow arrays `ContentModelService`
 * publishes. Every mistake an operator can make in the builder surfaces here as an
 * `InvalidArgumentException` before the service is called, so a malformed submission never reaches
 * persistence. `ContentModelFormPresenter` performs the reverse translation when the screen renders.
 *
 * @since  2.0.0
 */
final readonly class ContentModelFormMapper
{
    /**
     * Assemble the JSON Schema document a content type is published with.
     *
     * Rows are read from index zero to ninety-nine and blank rows are skipped, so the browser may
     * submit a sparse set. The result always closes the object against additional properties.
     *
     * @param   array<string, string>  $form  Submitted builder form, holding the `field_N_*` inputs.
     *
     * @return  array<string, mixed>  Object schema with `properties`, `required` and the closing flag.
     *
     * @throws  InvalidArgumentException  When a field key is malformed or duplicated, a field type is
     *          unsupported, a bound does not parse, or no field row was filled in at all.
     *
     * @since   2.0.0
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
     * Assemble the workflow state list the model service publishes.
     *
     * Exactly one state is marked initial, chosen by the separate `initial_state_key` input; a form
     * whose choice does not name one of the submitted states is rejected rather than silently fixed.
     *
     * @param   array<string, string>  $form  Submitted builder form, holding the `state_N_*` inputs.
     *
     * @return  list<array<string, mixed>>  States in row order, each with key, name, initial and public.
     *
     * @throws  InvalidArgumentException  When a state key is malformed or duplicated, a name is blank,
     *          no state row was filled in, or the initial state is missing or unknown.
     *
     * @since   2.0.0
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
     * Assemble the workflow transition list the model service publishes.
     *
     * A row with both endpoints blank is skipped so the builder can carry spare rows, but a row that
     * fills in only part of a transition is rejected instead of being quietly dropped.
     *
     * @param   array<string, string>  $form  Submitted builder form, with the `transition_N_*` inputs.
     *
     * @return  list<array<string, mixed>>  Transitions in row order, each with from, to and capability.
     *
     * @throws  InvalidArgumentException  When either endpoint is not a valid state key, or the required
     *          capability is missing or malformed.
     *
     * @since   2.0.0
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
     * Build the schema fragment for one builder field row.
     *
     * The builder's type names are editor-facing rather than JSON Schema types, so each maps to a
     * type and format pair. Numeric bounds apply only to `integer` and `number` rows, length bounds
     * only to `string` and `text` rows, and an options list becomes an `enum` only for those two.
     *
     * @param   array<string, string>  $form   Submitted builder form, read for this row's extra inputs.
     * @param   int                    $index  Zero-based row number whose inputs are read.
     * @param   string                 $type   Builder type, such as `media`, `string-list` or `date`.
     *
     * @return  array<string, mixed>  Schema fragment for the property, without title or description.
     *
     * @throws  InvalidArgumentException  When the builder type is unsupported, or a bound is not valid
     *          for the type it constrains.
     *
     * @since   2.0.0
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

    /**
     * Assert that a numeric bound parses as the field type it constrains.
     *
     * @param   string  $value     Raw bound exactly as the operator typed it.
     * @param   string  $type      Builder type the bound belongs to, `integer` or `number`.
     * @param   string  $boundary  Which bound is under check, used to name it in the error message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is not a whole number, or not a finite number.
     *
     * @since   2.0.0
     */
    private function assertNumber(string $value, string $type, string $boundary): void
    {
        $valid = $type === 'integer'
            ? preg_match('/^-?[0-9]+$/D', $value) === 1
            : is_numeric($value) && is_finite((float) $value);
        if (!$valid) {
            throw new InvalidArgumentException(sprintf('The field %s must be a valid %s.', $boundary, $type));
        }
    }

    /**
     * Assert that a string length bound parses as a non-negative integer.
     *
     * @param   string  $value     Raw bound exactly as the operator typed it.
     * @param   string  $boundary  Which bound is under check, used to name it in the error message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value contains anything other than digits.
     *
     * @since   2.0.0
     */
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
