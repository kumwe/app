<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Automation;

use InvalidArgumentException;

final class AutomationJobFormRegistry
{
    /** @var array<string, array{label: string, fields: list<AutomationJobField>}> */
    private array $forms = [];

    /** @param iterable<AutomationJobField> $fields */
    public function register(string $jobType, string $label, iterable $fields = []): void
    {
        if (
            isset($this->forms[$jobType])
            || preg_match('/^[a-z][a-z0-9._-]{0,126}$/D', $jobType) !== 1
            || trim($label) === ''
        ) {
            throw new InvalidArgumentException('The automation job form type is invalid or duplicated.');
        }
        $indexed = [];
        foreach ($fields as $field) {
            if (isset($indexed[$field->key])) {
                throw new InvalidArgumentException('The automation job form contains a duplicate field.');
            }
            $indexed[$field->key] = $field;
        }
        $this->forms[$jobType] = ['label' => trim($label), 'fields' => array_values($indexed)];
    }

    /**
     * @param list<string> $jobTypes
     * @return list<array{type: string, label: string, fields: list<array<string, mixed>>}>
     */
    public function definitions(array $jobTypes): array
    {
        $definitions = [];
        foreach ($jobTypes as $jobType) {
            $form = $this->forms[$jobType] ?? ['label' => $this->label($jobType), 'fields' => []];
            $definitions[] = [
                'type' => $jobType,
                'label' => $form['label'],
                'fields' => array_map(
                    static fn (AutomationJobField $field): array => $field->toArray(),
                    $form['fields'],
                ),
            ];
        }

        return $definitions;
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    public function payload(string $jobType, array $form): array
    {
        $definition = $this->forms[$jobType] ?? ['label' => $this->label($jobType), 'fields' => []];
        $payload = [];
        foreach ($definition['fields'] as $field) {
            $raw = trim($form['payload__' . $field->key] ?? '');
            if ($raw === '') {
                if ($field->default !== null) {
                    $payload[$field->key] = $field->default;
                    continue;
                }
                if ($field->required) {
                    throw new InvalidArgumentException(sprintf('The %s job field is required.', $field->label));
                }
                continue;
            }
            if ($field->type === 'integer') {
                if (preg_match('/^-?[0-9]+$/D', $raw) !== 1) {
                    throw new InvalidArgumentException(sprintf(
                        'The %s job field must be a whole number.',
                        $field->label,
                    ));
                }
                $value = (int) $raw;
                if (
                    ($field->minimum !== null && $value < $field->minimum)
                    || ($field->maximum !== null && $value > $field->maximum)
                ) {
                    throw new InvalidArgumentException(sprintf(
                        'The %s job field is outside its limits.',
                        $field->label,
                    ));
                }
                $payload[$field->key] = $value;
                continue;
            }
            if ($field->options !== [] && !in_array($raw, $field->options, true)) {
                throw new InvalidArgumentException(sprintf(
                    'The %s job field has an unsupported value.',
                    $field->label,
                ));
            }
            if ($field->pattern !== null && preg_match($field->pattern, $raw) !== 1) {
                throw new InvalidArgumentException(sprintf('The %s job field is invalid.', $field->label));
            }
            $payload[$field->key] = $raw;
        }

        return $payload;
    }

    public static function core(): self
    {
        $registry = new self();
        $registry->register('content.workflow.transition', 'Transition content', [
            new AutomationJobField(
                'id',
                'Content ID',
                required: true,
                pattern: '/^[0-9a-f-]{36}$/Di',
                help: 'The canonical content identifier.',
            ),
            new AutomationJobField('version', 'Expected version', 'integer', true, minimum: 1),
            new AutomationJobField(
                'status',
                'Destination state',
                required: true,
                pattern: '/^[a-z][a-z0-9_-]{0,62}$/D',
            ),
        ]);
        $registry->register('system.idempotency.purge', 'Purge expired idempotency records', [
            new AutomationJobField('batch_size', 'Batch size', 'integer', default: 1_000, minimum: 1),
            new AutomationJobField(
                'maximum_batches',
                'Maximum batches',
                'integer',
                default: 10,
                minimum: 1,
                maximum: 100,
            ),
        ]);
        $registry->register('system.sessions.purge', 'Purge expired administrator sessions');
        $registry->register('extensions.runtime.rebuild', 'Rebuild extension runtime map');

        return $registry;
    }

    private function label(string $jobType): string
    {
        return ucfirst(str_replace(['.', '_', '-'], ' ', $jobType));
    }
}
