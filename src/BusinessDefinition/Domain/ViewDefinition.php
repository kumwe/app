<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

final readonly class ViewDefinition
{
    /** @var list<string> */
    public array $fields;

    /** @var list<string> */
    public array $filters;

    /** @var list<string> */
    public array $sorts;

    /**
     * @param list<string> $fields
     * @param list<string> $filters
     * @param list<string> $sorts
     */
    public function __construct(
        public string $handle,
        public string $label,
        public string $kind,
        array $fields,
        array $filters = [],
        array $sorts = [],
        public bool $administrator = true,
        public bool $portal = false,
        public bool $public = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1 || $label === '' || strlen($label) > 120) {
            throw new InvalidBusinessDefinition('A business view identity is invalid.');
        }
        if (!in_array($kind, ['list', 'detail', 'form', 'history', 'relation'], true)) {
            throw new InvalidBusinessDefinition('A business view kind is unsupported.');
        }
        if (!$administrator && !$portal && !$public) {
            throw new InvalidBusinessDefinition('A business view must declare at least one delivery surface.');
        }
        $this->fields = self::identifiers($fields, false);
        $this->filters = self::identifiers($filters, true);
        $this->sorts = self::identifiers($sorts, true);
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        if (array_diff(array_keys($document), [
            'handle', 'label', 'kind', 'fields', 'filters', 'sorts', 'administrator', 'portal', 'public',
        ]) !== []) {
            throw new InvalidBusinessDefinition('A business view contains an unknown property.');
        }

        return new self(
            self::string($document, 'handle'),
            self::string($document, 'label'),
            self::string($document, 'kind'),
            self::list($document, 'fields'),
            self::list($document, 'filters'),
            self::list($document, 'sorts'),
            self::boolean($document, 'administrator', true),
            self::boolean($document, 'portal'),
            self::boolean($document, 'public'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'label' => $this->label,
            'kind' => $this->kind,
            'fields' => $this->fields,
            'filters' => $this->filters,
            'sorts' => $this->sorts,
            'administrator' => $this->administrator,
            'portal' => $this->portal,
            'public' => $this->public,
        ];
    }

    /** @param array<string, mixed> $document */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidBusinessDefinition('Business view property ' . $key . ' is required.');
        }
        return trim($value);
    }

    /** @param array<string, mixed> $document @return list<string> */
    private static function list(array $document, string $key): array
    {
        $value = $document[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidBusinessDefinition('Business view property ' . $key . ' must be a list.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidBusinessDefinition('Business view property ' . $key . ' must contain strings.');
            }
            $result[] = $item;
        }
        return $result;
    }

    /** @param array<string, mixed> $document */
    private static function boolean(array $document, string $key, bool $default = false): bool
    {
        $value = $document[$key] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidBusinessDefinition('Business view property ' . $key . ' must be boolean.');
        }
        return $value;
    }

    /** @param list<string> $values @return list<string> */
    private static function identifiers(array $values, bool $mayBeEmpty): array
    {
        if ((!$mayBeEmpty && $values === []) || count($values) > 128 || count($values) !== count(array_unique($values))) {
            throw new InvalidBusinessDefinition('Business view field references are empty, duplicated, or unbounded.');
        }
        foreach ($values as $value) {
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $value) !== 1) {
                throw new InvalidBusinessDefinition('A business view field reference is invalid.');
            }
        }
        return $values;
    }
}
