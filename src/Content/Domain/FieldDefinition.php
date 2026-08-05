<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Domain;

use InvalidArgumentException;

final readonly class FieldDefinition
{
    /** @param array<string, mixed> $schema */
    public function __construct(public string $key, public array $schema, public bool $required)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1) {
            throw new InvalidArgumentException('A field key must be a lowercase identifier.');
        }
        if ($schema !== [] && array_is_list($schema)) {
            throw new InvalidArgumentException('A field definition must contain a JSON Schema object.');
        }
    }

    /** @return array{key: string, schema: array<string, mixed>, required: bool} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'schema' => $this->schema, 'required' => $this->required];
    }
}
