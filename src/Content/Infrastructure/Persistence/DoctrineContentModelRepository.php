<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use JsonException;
use LogicException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Content\Application\ContentModelRepository;
use Kumwe\CMS\Content\Domain\ContentTypeDefinition;
use Kumwe\CMS\Content\Domain\VersionConflict;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Workflow\Domain\WorkflowDefinition;
use Kumwe\CMS\Workflow\Domain\WorkflowStateDefinition;
use Kumwe\CMS\Workflow\Domain\WorkflowTransitionDefinition;
use RuntimeException;

final readonly class DoctrineContentModelRepository implements ContentModelRepository
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function contentTypes(SiteContext $site): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT v.* FROM %s v INNER JOIN %s h ON h.id = v.content_type_id AND h.version = v.version '
            . 'WHERE h.site_identifier = ? ORDER BY h.handle ASC',
            $this->tables->quoted('content_type_definition_versions'),
            $this->tables->quoted('content_types'),
        ), [$site->identifier()]);
        return array_map($this->mapContentType(...), $rows);
    }

    public function contentType(SiteContext $site, string $identifier, ?int $version = null): ?ContentTypeDefinition
    {
        $sql = sprintf(
            'SELECT v.* FROM %s v INNER JOIN %s h ON h.id = v.content_type_id '
            . 'WHERE h.site_identifier = ? AND (h.id = ? OR h.handle = ?) AND v.version = %s',
            $this->tables->quoted('content_type_definition_versions'),
            $this->tables->quoted('content_types'),
            $version === null ? 'h.version' : '?',
        );
        $parameters = [$site->identifier(), $identifier, $identifier];
        if ($version !== null) {
            $parameters[] = $version;
        }
        $row = $this->database->fetchAssociative($sql, $parameters);
        return $row === false ? null : $this->mapContentType($row);
    }

    public function insertContentType(ContentTypeDefinition $definition): void
    {
        $this->database->insert($this->tables->raw('content_types'), [
            'id' => $definition->id,
            'site_identifier' => $definition->site->identifier(),
            'workflow_id' => $definition->workflowId,
            'handle' => $definition->handle,
            'name' => $definition->name,
            'field_schema' => $definition->schema(),
            'version' => $definition->version,
            'created_at' => $definition->createdAt,
            'updated_at' => $definition->publishedAt,
        ], [
            'field_schema' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $this->insertContentTypeVersion($definition);
    }

    public function publishContentType(ContentTypeDefinition $definition, int $expectedVersion): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new LogicException('Content type publication requires an active transaction.');
        }
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET workflow_id = ?, name = ?, field_schema = ?, version = ?, updated_at = ? '
            . 'WHERE id = ? AND site_identifier = ? AND version = ?',
            $this->tables->quoted('content_types'),
        ), [
            $definition->workflowId,
            $definition->name,
            $definition->schema(),
            $definition->version,
            $definition->publishedAt,
            $definition->id,
            $definition->site->identifier(),
            $expectedVersion,
        ], [
            Types::GUID,
            Types::STRING,
            Types::JSON,
            Types::INTEGER,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::STRING,
            Types::INTEGER,
        ]);
        if ((string) $affected !== '1') {
            throw new VersionConflict($expectedVersion, $this->headVersion('content_types', $definition->id));
        }
        $this->insertContentTypeVersion($definition);
    }

    public function workflows(SiteContext $site): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT v.* FROM %s v INNER JOIN %s h ON h.id = v.workflow_id AND h.version = v.version '
            . 'WHERE h.site_identifier = ? ORDER BY h.handle ASC',
            $this->tables->quoted('workflow_definition_versions'),
            $this->tables->quoted('workflows'),
        ), [$site->identifier()]);
        return array_map($this->mapWorkflow(...), $rows);
    }

    public function workflow(SiteContext $site, string $identifier, ?int $version = null): ?WorkflowDefinition
    {
        $sql = sprintf(
            'SELECT v.* FROM %s v INNER JOIN %s h ON h.id = v.workflow_id '
            . 'WHERE h.site_identifier = ? AND (h.id = ? OR h.handle = ?) AND v.version = %s',
            $this->tables->quoted('workflow_definition_versions'),
            $this->tables->quoted('workflows'),
            $version === null ? 'h.version' : '?',
        );
        $parameters = [$site->identifier(), $identifier, $identifier];
        if ($version !== null) {
            $parameters[] = $version;
        }
        $row = $this->database->fetchAssociative($sql, $parameters);
        return $row === false ? null : $this->mapWorkflow($row);
    }

    public function insertWorkflow(WorkflowDefinition $definition): void
    {
        $this->database->insert($this->tables->raw('workflows'), [
            'id' => $definition->id,
            'site_identifier' => $definition->site->identifier(),
            'handle' => $definition->handle,
            'name' => $definition->name,
            'version' => $definition->version,
            'created_at' => $definition->createdAt,
            'updated_at' => $definition->publishedAt,
        ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        $this->insertWorkflowVersion($definition);
    }

    public function publishWorkflow(WorkflowDefinition $definition, int $expectedVersion): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new LogicException('Workflow publication requires an active transaction.');
        }
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET name = ?, version = ?, updated_at = ? WHERE id = ? AND site_identifier = ? AND version = ?',
            $this->tables->quoted('workflows'),
        ), [
            $definition->name,
            $definition->version,
            $definition->publishedAt,
            $definition->id,
            $definition->site->identifier(),
            $expectedVersion,
        ], [
            Types::STRING,
            Types::INTEGER,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::STRING,
            Types::INTEGER,
        ]);
        if ((string) $affected !== '1') {
            throw new VersionConflict($expectedVersion, $this->headVersion('workflows', $definition->id));
        }
        $this->insertWorkflowVersion($definition);
    }

    private function insertContentTypeVersion(ContentTypeDefinition $definition): void
    {
        $this->database->insert($this->tables->raw('content_type_definition_versions'), [
            'content_type_id' => $definition->id,
            'version' => $definition->version,
            'site_identifier' => $definition->site->identifier(),
            'handle' => $definition->handle,
            'name' => $definition->name,
            'workflow_id' => $definition->workflowId,
            'workflow_version' => $definition->workflowVersion,
            'validation_schema' => $definition->schema(),
            'created_at' => $definition->createdAt,
            'published_at' => $definition->publishedAt,
        ], [
            'validation_schema' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'published_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    private function insertWorkflowVersion(WorkflowDefinition $definition): void
    {
        $publicStates = [];
        foreach ($definition->states() as $state) {
            if ($state->public) {
                $publicStates[] = $state->key;
            }
        }
        $this->database->insert($this->tables->raw('workflow_definition_versions'), [
            'workflow_id' => $definition->id,
            'version' => $definition->version,
            'site_identifier' => $definition->site->identifier(),
            'handle' => $definition->handle,
            'name' => $definition->name,
            'states' => array_map(
                static fn (WorkflowStateDefinition $state): array => $state->toArray(),
                $definition->states(),
            ),
            'transitions' => array_map(
                static fn (WorkflowTransitionDefinition $transition): array => $transition->toArray(),
                $definition->transitions(),
            ),
            'public_states' => $publicStates,
            'created_at' => $definition->createdAt,
            'published_at' => $definition->publishedAt,
        ], [
            'states' => Types::JSON,
            'transitions' => Types::JSON,
            'public_states' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'published_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function mapContentType(array $row): ContentTypeDefinition
    {
        return new ContentTypeDefinition(
            $this->string($row, 'content_type_id'),
            SiteContext::fromString($this->string($row, 'site_identifier')),
            $this->string($row, 'handle'),
            $this->string($row, 'name'),
            $this->string($row, 'workflow_id'),
            $this->integer($row, 'workflow_version'),
            $this->jsonObject($row, 'validation_schema'),
            $this->integer($row, 'version'),
            $this->date($row['created_at'] ?? null),
            $this->date($row['published_at'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapWorkflow(array $row): WorkflowDefinition
    {
        $states = [];
        foreach ($this->jsonList($row, 'states') as $state) {
            if (!is_array($state)) {
                throw new RuntimeException('Stored workflow state is invalid.');
            }
            $states[] = new WorkflowStateDefinition(
                (string) ($state['key'] ?? ''),
                (string) ($state['name'] ?? ''),
                (bool) ($state['initial'] ?? false),
                (bool) ($state['public'] ?? false),
            );
        }
        $transitions = [];
        foreach ($this->jsonList($row, 'transitions') as $transition) {
            if (!is_array($transition)) {
                throw new RuntimeException('Stored workflow transition is invalid.');
            }
            $transitions[] = new WorkflowTransitionDefinition(
                (string) ($transition['from'] ?? ''),
                (string) ($transition['to'] ?? ''),
                Capability::fromString((string) ($transition['required_capability'] ?? '')),
            );
        }
        return new WorkflowDefinition(
            $this->string($row, 'workflow_id'),
            SiteContext::fromString($this->string($row, 'site_identifier')),
            $this->string($row, 'handle'),
            $this->string($row, 'name'),
            $states,
            $transitions,
            $this->integer($row, 'version'),
            $this->date($row['created_at'] ?? null),
            $this->date($row['published_at'] ?? null),
        );
    }

    private function headVersion(string $table, string $id): int
    {
        $value = $this->database->fetchOne(
            sprintf('SELECT version FROM %s WHERE id = ?', $this->tables->quoted($table)),
            [$id],
        );
        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        if (!isset($row[$key]) || !is_string($row[$key]) || $row[$key] === '') {
            throw new RuntimeException('Stored definition ' . $key . ' is invalid.');
        }
        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('Stored definition ' . $key . ' is invalid.');
        }
        return (int) $value;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function jsonObject(array $row, string $key): array
    {
        $value = $this->json($row[$key] ?? null);
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('Stored definition ' . $key . ' must be a JSON object.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row @return list<mixed> */
    private function jsonList(array $row, string $key): array
    {
        $value = $this->json($row[$key] ?? null);
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Stored definition ' . $key . ' must be a JSON list.');
        }
        return $value;
    }

    private function json(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        try {
            return json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored definition JSON is invalid.', 0, $exception);
        }
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value)) {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }
        throw new RuntimeException('Stored definition timestamp is invalid.');
    }
}
