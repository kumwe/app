<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

final readonly class ActionDefinition
{
    public function __construct(
        public string $handle,
        public string $label,
        public string $capability,
        public bool $bulk = false,
        public bool $administrator = true,
        public bool $portal = false,
        public bool $public = false,
        public bool $highImpact = false,
        public ?Expression $condition = null,
        public ?string $transition = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1 || $label === '' || strlen($label) > 120) {
            throw new InvalidBusinessDefinition('A business action identity is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9-]*(?:[._:][a-z0-9-]+)*$/D', $capability) !== 1) {
            throw new InvalidBusinessDefinition('A business action capability is invalid.');
        }
        if ($public) {
            throw new InvalidBusinessDefinition('Business actions cannot be anonymously public.');
        }
        if (!$administrator && !$portal) {
            throw new InvalidBusinessDefinition('A business action requires an administrator or portal surface.');
        }
        if ($transition !== null && preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $transition) !== 1) {
            throw new InvalidBusinessDefinition('A business action workflow transition is invalid.');
        }
        if ($condition !== null && $condition->type !== 'boolean') {
            throw new InvalidBusinessDefinition('A business action condition must produce boolean.');
        }
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        if (
            array_diff(array_keys($document), [
            'handle', 'label', 'capability', 'bulk', 'administrator', 'portal', 'public', 'high_impact',
            'condition', 'transition',
            ]) !== []
        ) {
            throw new InvalidBusinessDefinition('A business action contains an unknown property.');
        }
        $condition = $document['condition'] ?? null;
        if ($condition !== null && (!is_array($condition) || array_is_list($condition))) {
            throw new InvalidBusinessDefinition('A business action condition must be an object.');
        }

        return new self(
            self::string($document, 'handle'),
            self::string($document, 'label'),
            self::string($document, 'capability'),
            self::boolean($document, 'bulk'),
            self::boolean($document, 'administrator', true),
            self::boolean($document, 'portal'),
            self::boolean($document, 'public'),
            self::boolean($document, 'high_impact'),
            is_array($condition) ? Expression::fromArray($condition) : null,
            self::nullableString($document, 'transition'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'label' => $this->label,
            'capability' => $this->capability,
            'bulk' => $this->bulk,
            'administrator' => $this->administrator,
            'portal' => $this->portal,
            'public' => $this->public,
            'high_impact' => $this->highImpact,
            'condition' => $this->condition?->toArray(),
            'transition' => $this->transition,
        ];
    }

    /** @param array<string, mixed> $document */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidBusinessDefinition('Business action property ' . $key . ' is required.');
        }
        return trim($value);
    }

    /** @param array<string, mixed> $document */
    private static function boolean(array $document, string $key, bool $default = false): bool
    {
        $value = $document[$key] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidBusinessDefinition('Business action property ' . $key . ' must be boolean.');
        }
        return $value;
    }

    /** @param array<string, mixed> $document */
    private static function nullableString(array $document, string $key): ?string
    {
        $value = $document[$key] ?? null;
        if ($value !== null && (!is_string($value) || trim($value) === '')) {
            throw new InvalidBusinessDefinition('Business action property ' . $key . ' must be null or a string.');
        }
        return is_string($value) ? trim($value) : null;
    }
}
