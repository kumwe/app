<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

final readonly class RecordInvariantDefinition
{
    public function __construct(
        public string $handle,
        public string $message,
        public Expression $condition,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
            throw new InvalidBusinessDefinition('A record invariant handle is invalid.');
        }
        if ($message === '' || strlen($message) > 500) {
            throw new InvalidBusinessDefinition('A record invariant requires a bounded message.');
        }
        if ($condition->type !== 'boolean') {
            throw new InvalidBusinessDefinition('A record invariant condition must produce boolean.');
        }
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        if (array_diff(array_keys($document), ['handle', 'message', 'condition']) !== []) {
            throw new InvalidBusinessDefinition('A record invariant contains an unknown property.');
        }
        $handle = $document['handle'] ?? null;
        $message = $document['message'] ?? null;
        $condition = $document['condition'] ?? null;
        if (!is_string($handle) || !is_string($message) || !is_array($condition) || array_is_list($condition)) {
            throw new InvalidBusinessDefinition('A record invariant has an invalid shape.');
        }

        /** @var array<string, mixed> $condition */
        return new self(trim($handle), trim($message), Expression::fromArray($condition));
    }

    /** @param array<string, scalar|null> $fields */
    public function isSatisfied(array $fields): bool
    {
        $result = $this->condition->evaluate($fields);
        if (!is_bool($result)) {
            throw new InvalidBusinessDefinition('A record invariant produced a non-boolean result.');
        }

        return $result;
    }

    /** @return array{handle: string, message: string, condition: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'message' => $this->message,
            'condition' => $this->condition->toArray(),
        ];
    }
}
