<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\SiteContext;

final readonly class ContentTypeDefinition
{
    /** @var array<string, mixed> */
    private array $schema;

    /** @param array<string, mixed> $schema */
    public function __construct(
        public string $id,
        public SiteContext $site,
        public string $handle,
        public string $name,
        public string $workflowId,
        public int $workflowVersion,
        array $schema,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $publishedAt,
    ) {
        self::uuid($id);
        if (preg_match('/^[a-z][a-z0-9_-]{0,99}$/D', $handle) !== 1) {
            throw new InvalidArgumentException('A content type handle must be a lowercase identifier.');
        }
        if (mb_strlen(trim($name)) < 1 || mb_strlen(trim($name)) > 255) {
            throw new InvalidArgumentException('A content type name must contain between 1 and 255 characters.');
        }
        self::uuid($workflowId);
        if ($workflowVersion < 1 || $version < 1) {
            throw new InvalidArgumentException('Definition versions must be positive integers.');
        }
        if (($schema['type'] ?? null) !== 'object' || array_is_list($schema)) {
            throw new InvalidArgumentException('A content type schema must describe a JSON object.');
        }
        $this->schema = $schema;
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return $this->schema;
    }

    /** @return list<FieldDefinition> */
    public function fields(): array
    {
        $properties = $this->schema['properties'] ?? [];
        if (!is_array($properties) || array_is_list($properties)) {
            return [];
        }
        $required = $this->schema['required'] ?? [];
        if (!is_array($required)) {
            $required = [];
        }
        $fields = [];
        foreach ($properties as $key => $fieldSchema) {
            if (is_string($key) && is_array($fieldSchema) && !array_is_list($fieldSchema)) {
                /** @var array<string, mixed> $fieldSchema */
                $fields[] = new FieldDefinition($key, $fieldSchema, in_array($key, $required, true));
            }
        }
        return $fields;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'site' => $this->site->identifier(),
            'handle' => $this->handle,
            'name' => $this->name,
            'workflow_id' => $this->workflowId,
            'workflow_version' => $this->workflowVersion,
            'schema' => $this->schema,
            'fields' => array_map(static fn (FieldDefinition $field): array => $field->toArray(), $this->fields()),
            'version' => $this->version,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'published_at' => $this->publishedAt->format(DATE_ATOM),
        ];
    }

    private static function uuid(string $value): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) !== 1) {
            throw new InvalidArgumentException('A definition ID must be a canonical UUID.');
        }
    }
}
