<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Automation;

use InvalidArgumentException;

/**
 * One operator-facing input on the payload form an automation job type presents.
 *
 * An automation schedule carries a free-form JSON payload, which is unusable in an administrator
 * screen unless something states what a given job type expects. A field declares that expectation —
 * the payload key it writes, its caption, its widget, and the bounds a submitted value must satisfy —
 * so `AutomationJobFormRegistry` can both render the input and validate what comes back from it. The
 * constructor rejects a malformed declaration at registration time rather than at request time.
 *
 * @since  2.0.0
 */
final readonly class AutomationJobField
{
    /**
     * Declare one payload field, refusing a declaration the form could not render or validate.
     *
     * @param   string                $key       Payload key this field writes, lowercase with underscores.
     * @param   string                $label     Caption shown beside the input, and the name used in errors.
     * @param   string                $type      Widget kind: `text`, `integer`, `boolean`, or `select`.
     * @param   bool                  $required  Whether the operator must supply a value before saving.
     * @param   bool|string|int|null  $default   Value substituted for an empty input; null omits the key.
     * @param   int|null              $minimum   Smallest accepted `integer` value, or null for no floor.
     * @param   int|null              $maximum   Largest accepted `integer` value, or null for no ceiling.
     * @param   string|null           $pattern   PCRE a text value must match, or null to accept any text.
     * @param   list<string>          $options   Accepted values for a `select`; empty leaves the value free.
     * @param   string                $help      Hint rendered under the input to explain what to enter.
     *
     * @throws  InvalidArgumentException  When the key or label is malformed, or the type is not one of
     *          the three supported widget kinds.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
        public bool $required = false,
        public bool|string|int|null $default = null,
        public ?int $minimum = null,
        public ?int $maximum = null,
        public ?string $pattern = null,
        public array $options = [],
        public string $help = '',
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1 || trim($label) === '') {
            throw new InvalidArgumentException('Automation field keys and labels must be valid.');
        }
        if (!in_array($type, ['text', 'integer', 'boolean', 'select'], true)) {
            throw new InvalidArgumentException('Automation field types must be text, integer, boolean, or select.');
        }
        if ($type === 'boolean' && $options !== []) {
            throw new InvalidArgumentException('Boolean automation fields cannot declare text options.');
        }
    }

    /**
     * Export the declaration as the flat array the automation form template iterates over.
     *
     * The `name` entry is the HTML input name, prefixed so that payload inputs can be told apart from
     * the rest of the schedule form when the submission comes back.
     *
     * @return  array<string, bool|int|string|list<string>|null>  The declaration plus the input `name`.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => 'payload__' . $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'default' => $this->default,
            'minimum' => $this->minimum,
            'maximum' => $this->maximum,
            'pattern' => $this->pattern,
            'options' => $this->options,
            'help' => $this->help,
        ];
    }
}
