<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Automation;

use InvalidArgumentException;

/**
 * Catalogue of the payload forms the administrator automation screen offers, one per job type.
 *
 * A job payload is opaque JSON, so without a declared form an operator has to hand-write it. This
 * registry maps a job type to the fields it accepts, which lets the screen render real inputs and
 * turn the submitted values back into a payload that has already been range- and pattern-checked.
 * `core()` builds the set the shipped job types need, and `register()` adds more before the instance
 * is shared. A job type with no registered form still resolves — to a derived label and no fields —
 * so the raw JSON escape hatch keeps working for anything the builder does not describe.
 *
 * @since  2.0.0
 */
final class AutomationJobFormRegistry
{
    /**
     * Registered forms keyed by job type, each holding its screen label and its declared fields.
     *
     * @var    array<string, array{label: string, fields: list<AutomationJobField>}>
     * @since  2.0.0
     */
    private array $forms = [];

    /**
     * Register the payload form one job type presents.
     *
     * @param   string                        $jobType  Job type identifier the schedule form submits.
     * @param   string                        $label    Caption for the job type in the type selector.
     * @param   iterable<AutomationJobField>  $fields   Fields the payload accepts, in render order.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the job type is already registered or malformed, the
     *          label is blank, or two fields claim the same key.
     *
     * @since   2.0.0
     */
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
     * Describe each requested job type in the shape the automation template renders.
     *
     * A job type with no registered form is still described, with a label derived from its identifier
     * and an empty field list, so an unrecognised type never disappears from the selector.
     *
     * @param   list<string>  $jobTypes  Job types the operator may schedule, in selector order.
     *
     * @return  list<array{type: string, label: string, fields: list<array<string, mixed>>}>  One entry
     *          per requested type, in the order given.
     *
     * @since   2.0.0
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
     * Turn a submitted schedule form into the payload the job will be queued with.
     *
     * Only the registered fields are read, so anything else in the form is ignored. An empty input
     * falls back to the field's default; a field with neither a value nor a default is dropped unless
     * it is required. A job type with no registered form therefore yields an empty payload.
     *
     * @param   string                 $jobType  Job type whose registered fields drive the mapping.
     * @param   array<string, string>  $form     Submitted form values, keyed by HTML input name.
     *
     * @return  array<string, mixed>  Payload keyed by field key, values already coerced and checked.
     *
     * @throws  InvalidArgumentException  When a required field is empty, or a value fails its whole
     *          number, range, option, or pattern check.
     *
     * @since   2.0.0
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
            if ($field->type === 'boolean') {
                if (!in_array($raw, ['true', 'false'], true)) {
                    throw new InvalidArgumentException(sprintf(
                        'The %s job field must be true or false.',
                        $field->label,
                    ));
                }
                $payload[$field->key] = $raw === 'true';
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

    /**
     * Build the registry describing the job types Kumwe ships with.
     *
     * This is the factory the container shares and the handlers fall back to, so the shipped
     * content-transition, purge, and runtime-rebuild jobs always have a form even when nothing has
     * registered one.
     *
     * @return  self  A registry pre-populated with the core job forms.
     *
     * @since   2.0.0
     */
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
        $registry->register(
            'business.record.idempotency.purge',
            'Purge expired business-record idempotency entries',
            [
                new AutomationJobField('batch_size', 'Batch size', 'integer', default: 500, minimum: 1, maximum: 1_000),
                new AutomationJobField(
                    'maximum_batches',
                    'Maximum batches',
                    'integer',
                    default: 10,
                    minimum: 1,
                    maximum: 100,
                ),
            ],
        );
        $registry->register('system.sessions.purge', 'Purge expired administrator sessions');
        $registry->register('extensions.runtime.rebuild', 'Rebuild extension runtime map');

        return $registry;
    }

    /**
     * Derive a readable caption for a job type that has no registered form.
     *
     * @param   string  $jobType  Dotted job type identifier, such as `system.sessions.purge`.
     *
     * @return  string  The identifier with separators replaced by spaces and the first letter raised.
     *
     * @since   2.0.0
     */
    private function label(string $jobType): string
    {
        return ucfirst(str_replace(['.', '_', '-'], ' ', $jobType));
    }
}
