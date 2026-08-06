<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Automation;

use InvalidArgumentException;

final readonly class AutomationJobField
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
        public bool $required = false,
        public string|int|null $default = null,
        public ?int $minimum = null,
        public ?int $maximum = null,
        public ?string $pattern = null,
        public array $options = [],
        public string $help = '',
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1 || trim($label) === '') {
            throw new InvalidArgumentException('Automation field keys and labels must be valid.');
        }
        if (!in_array($type, ['text', 'integer', 'select'], true)) {
            throw new InvalidArgumentException('Automation field types must be text, integer, or select.');
        }
    }

    /** @return array<string, bool|int|string|list<string>|null> */
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
