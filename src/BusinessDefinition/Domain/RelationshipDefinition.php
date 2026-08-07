<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

final readonly class RelationshipDefinition
{
    public function __construct(
        public string $handle,
        public string $label,
        public RelationshipKind $kind,
        public string $target,
        public ?string $inverse = null,
        public bool $required = false,
        public bool $unique = false,
        public bool $ordered = false,
        public DeleteBehavior $onDelete = DeleteBehavior::Restrict,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
            throw new InvalidBusinessDefinition('A relationship handle is invalid.');
        }
        if ($label === '' || strlen($label) > 120) {
            throw new InvalidBusinessDefinition('A relationship label is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $target) !== 1) {
            throw new InvalidBusinessDefinition('A relationship target handle is invalid.');
        }
        if ($inverse !== null && preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $inverse) !== 1) {
            throw new InvalidBusinessDefinition('A relationship inverse handle is invalid.');
        }
        if ($onDelete === DeleteBehavior::SetNull && $required) {
            throw new InvalidBusinessDefinition('A required relationship cannot use set-null deletion.');
        }
        if ($kind === RelationshipKind::OwnedLineCollection && $onDelete !== DeleteBehavior::Cascade) {
            throw new InvalidBusinessDefinition('An owned line collection must cascade from its owner.');
        }
        if ($ordered && !in_array($kind, [
            RelationshipKind::OneToMany,
            RelationshipKind::ManyToMany,
            RelationshipKind::OwnedLineCollection,
        ], true)) {
            throw new InvalidBusinessDefinition('Only collection relationships may be ordered.');
        }
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        if (array_diff(array_keys($document), [
            'handle', 'label', 'kind', 'target', 'inverse', 'required', 'unique', 'ordered', 'on_delete',
        ]) !== []) {
            throw new InvalidBusinessDefinition('A relationship contains an unknown property.');
        }
        $kind = RelationshipKind::tryFrom(self::string($document, 'kind'))
            ?? throw new InvalidBusinessDefinition('A relationship kind is unsupported.');
        $delete = DeleteBehavior::tryFrom(self::optionalString($document, 'on_delete', 'restrict'))
            ?? throw new InvalidBusinessDefinition('A relationship delete behavior is unsupported.');

        return new self(
            self::string($document, 'handle'),
            self::string($document, 'label'),
            $kind,
            self::string($document, 'target'),
            self::nullableString($document, 'inverse'),
            self::boolean($document, 'required'),
            self::boolean($document, 'unique'),
            self::boolean($document, 'ordered'),
            $delete,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'label' => $this->label,
            'kind' => $this->kind->value,
            'target' => $this->target,
            'inverse' => $this->inverse,
            'required' => $this->required,
            'unique' => $this->unique,
            'ordered' => $this->ordered,
            'on_delete' => $this->onDelete->value,
        ];
    }

    /** @param array<string, mixed> $document */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidBusinessDefinition('Relationship property ' . $key . ' is required.');
        }
        return trim($value);
    }

    /** @param array<string, mixed> $document */
    private static function optionalString(array $document, string $key, string $default): string
    {
        $value = $document[$key] ?? $default;
        if (!is_string($value)) {
            throw new InvalidBusinessDefinition('Relationship property ' . $key . ' must be a string.');
        }
        return trim($value);
    }

    /** @param array<string, mixed> $document */
    private static function nullableString(array $document, string $key): ?string
    {
        $value = $document[$key] ?? null;
        if ($value !== null && (!is_string($value) || trim($value) === '')) {
            throw new InvalidBusinessDefinition('Relationship property ' . $key . ' must be null or a string.');
        }
        return is_string($value) ? trim($value) : null;
    }

    /** @param array<string, mixed> $document */
    private static function boolean(array $document, string $key): bool
    {
        $value = $document[$key] ?? false;
        if (!is_bool($value)) {
            throw new InvalidBusinessDefinition('Relationship property ' . $key . ' must be boolean.');
        }
        return $value;
    }
}
