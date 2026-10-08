<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextBinding;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextRepository;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTarget;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use RuntimeException;

/**
 * Doctrine store for opaque contextual Content authoring bindings.
 *
 * @since  2.0.0
 */
final readonly class DoctrineContentStudioAuthoringContextRepository implements ContentStudioAuthoringContextRepository
{
    /**
     * Bind context persistence to the installation's prefix-aware database.
     *
     * @param  Connection  $connection  Configured relational connection.
     * @param  TableNames  $tables      Validated physical table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $connection, private TableNames $tables)
    {
    }

    /**
     * Insert a complete immutable binding before the caller receives its opaque key.
     *
     * @param   ContentStudioAuthoringContextBinding  $binding  Verified target and authenticated scope.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the database refuses the insert.
     *
     * @since   2.0.0
     */
    public function add(ContentStudioAuthoringContextBinding $binding): void
    {
        $this->connection->insert(
            $this->tables->raw('studio_content_authoring_contexts'),
            [
                'context_key' => $binding->contextKey,
                'actor_id' => $binding->actorId,
                'site_identifier' => $binding->siteId,
                'organization_identifier' => $binding->organizationId,
                'workspace_identifier' => $binding->workspaceId,
                'surface' => $binding->surface,
                'session_binding' => $binding->sessionBinding,
                'authority_binding' => $binding->authorityBinding,
                'intent' => $binding->target->intent->value,
                'model_identifier' => $binding->target->modelId,
                'model_version' => $binding->target->modelVersion,
                'model_revision' => $binding->target->modelRevision,
                'entry_identifier' => $binding->target->entryId,
                'entry_revision' => $binding->target->entryRevision,
                'return_path' => $binding->target->returnPath,
                'created_at' => $binding->createdAt,
                'expires_at' => $binding->expiresAt,
            ],
            ['created_at' => Types::DATETIME_IMMUTABLE, 'expires_at' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Resolve one binding by opaque key and reconstruct every persisted scalar defensively.
     *
     * @param   string  $contextKey  Opaque context key from an authenticated request.
     *
     * @return  ContentStudioAuthoringContextBinding|null  Revalidated binding, or null when no row exists.
     *
     * @throws  \Doctrine\DBAL\Exception  When the database read fails.
     * @throws  RuntimeException  When persisted metadata violates application invariants.
     *
     * @since   2.0.0
     */
    public function find(string $contextKey): ?ContentStudioAuthoringContextBinding
    {
        $row = $this->connection->fetchAssociative(sprintf(
            'SELECT context_key, actor_id, site_identifier, organization_identifier, workspace_identifier, surface, '
                . 'session_binding, authority_binding, intent, model_identifier, model_version, model_revision, '
                . 'entry_identifier, '
                . 'entry_revision, return_path, created_at, expires_at FROM %s WHERE context_key = ?',
            $this->tables->quoted('studio_content_authoring_contexts'),
        ), [$contextKey], [ParameterType::STRING]);
        if ($row === false) {
            return null;
        }
        $storedContextKey = self::string($row, 'context_key');
        if (!hash_equals($storedContextKey, $contextKey)) {
            return null;
        }

        try {
            return new ContentStudioAuthoringContextBinding(
                $storedContextKey,
                self::string($row, 'actor_id'),
                self::string($row, 'site_identifier'),
                self::nullableString($row, 'organization_identifier'),
                self::nullableString($row, 'workspace_identifier'),
                self::string($row, 'surface'),
                self::string($row, 'session_binding'),
                self::string($row, 'authority_binding'),
                new ContentStudioAuthoringTarget(
                    StudioAuthoringIntent::from(self::string($row, 'intent')),
                    self::nullableString($row, 'model_identifier'),
                    self::nullableString($row, 'model_version'),
                    self::nullableString($row, 'model_revision'),
                    self::nullableString($row, 'entry_identifier'),
                    self::nullableString($row, 'entry_revision'),
                    self::string($row, 'return_path'),
                ),
                self::instant($row['created_at'] ?? null),
                self::instant($row['expires_at'] ?? null),
            );
        } catch (\DateMalformedStringException | InvalidArgumentException | \ValueError $exception) {
            throw new RuntimeException('Stored Studio Content authoring context metadata is invalid.', 0, $exception);
        }
    }

    /**
     * Advance the stored target of one binding in place, leaving every other column untouched.
     *
     * @param   ContentStudioAuthoringContextBinding  $binding  Binding carrying the successor target.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the database refuses the update.
     * @throws  RuntimeException  When no row carries the binding's key.
     *
     * @since   2.0.0
     */
    public function advance(ContentStudioAuthoringContextBinding $binding): void
    {
        $updated = $this->connection->update(
            $this->tables->raw('studio_content_authoring_contexts'),
            [
                'intent' => $binding->target->intent->value,
                'model_identifier' => $binding->target->modelId,
                'model_version' => $binding->target->modelVersion,
                'model_revision' => $binding->target->modelRevision,
                'entry_identifier' => $binding->target->entryId,
                'entry_revision' => $binding->target->entryRevision,
                'return_path' => $binding->target->returnPath,
            ],
            ['context_key' => $binding->contextKey],
        );
        if ($updated !== 1) {
            throw new RuntimeException('The Studio Content authoring context to advance does not exist.');
        }
    }

    /**
     * Record the start source with a conditional update, so the first choice wins under concurrency.
     *
     * @param   string  $contextKey    Opaque key of an existing binding.
     * @param   string  $startSource   Canonical JSON start source.
     * @param   string  $presentation  Initial Studio presentation, recorded atomically with the source.
     *
     * @return  array{source: string, presentation: string}|null  Recorded start, or null.
     *
     * @since   2.0.0
     */
    public function recordStart(string $contextKey, string $startSource, string $presentation): ?array
    {
        $this->connection->executeStatement(sprintf(
            'UPDATE %s SET start_source = ?, initial_presentation = ? WHERE context_key = ? AND start_source IS NULL',
            $this->tables->quoted('studio_content_authoring_contexts'),
        ), [$startSource, $presentation, $contextKey]);

        return $this->start($contextKey);
    }

    /**
     * Read the recorded start source of one binding.
     *
     * @param   string  $contextKey  Opaque key of an existing binding.
     *
     * @return  array{source: string, presentation: string}|null  Recorded start, or null.
     *
     * @since   2.0.0
     */
    public function start(string $contextKey): ?array
    {
        $row = $this->connection->fetchAssociative(sprintf(
            'SELECT start_source, initial_presentation FROM %s WHERE context_key = ?',
            $this->tables->quoted('studio_content_authoring_contexts'),
        ), [$contextKey]);

        if ($row === false || !is_string($row['start_source']) || !is_string($row['initial_presentation'])) {
            return null;
        }

        return ['source' => $row['start_source'], 'presentation' => $row['initial_presentation']];
    }

    /**
     * Replace the recorded handed-type digest of one binding.
     *
     * The statement's affected-row count is not checked: an engine that reports only changed rows
     * returns zero when the same digest is recorded again, which is not an error.
     *
     * @param   string  $contextKey  Opaque key of an existing binding.
     * @param   string  $digest      Lowercase hexadecimal SHA-256 of the handed type's canonical JSON.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the digest is not a lowercase hexadecimal SHA-256.
     * @throws  \Doctrine\DBAL\Exception  When the database refuses the update.
     *
     * @since   2.0.0
     */
    public function recordHandedType(string $contextKey, string $digest): void
    {
        if (preg_match(self::DIGEST, $digest) !== 1) {
            throw new InvalidArgumentException('A handed Studio content type digest must be a SHA-256.');
        }
        $this->connection->executeStatement(sprintf(
            'UPDATE %s SET handed_type_digest = ? WHERE context_key = ?',
            $this->tables->quoted('studio_content_authoring_contexts'),
        ), [$digest, $contextKey], [ParameterType::STRING, ParameterType::STRING]);
    }

    /**
     * Read the recorded handed-type digest of one binding.
     *
     * @param   string  $contextKey  Opaque key of an existing binding.
     *
     * @return  ?string  Recorded digest, or null when none was recorded or the binding does not exist.
     *
     * @throws  RuntimeException  When a stored digest is not a lowercase hexadecimal SHA-256.
     * @throws  \Doctrine\DBAL\Exception  When the database read fails.
     *
     * @since   2.0.0
     */
    public function handedType(string $contextKey): ?string
    {
        $digest = $this->connection->fetchOne(sprintf(
            'SELECT handed_type_digest FROM %s WHERE context_key = ?',
            $this->tables->quoted('studio_content_authoring_contexts'),
        ), [$contextKey], [ParameterType::STRING]);
        if ($digest === false || $digest === null) {
            return null;
        }
        if (!is_string($digest) || preg_match(self::DIGEST, $digest) !== 1) {
            throw new RuntimeException('Stored Studio Content authoring column handed_type_digest is invalid.');
        }

        return $digest;
    }

    /**
     * Read one required non-empty textual database column.
     *
     * @param   array<string, mixed>  $row   Fetched database row.
     * @param   string                $name  Required column name.
     *
     * @return  string  Stored non-empty text.
     *
     * @throws  RuntimeException  When the column is absent, empty, or not textual.
     *
     * @since   2.0.0
     */
    private static function string(array $row, string $name): string
    {
        $value = $row[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored Studio Content authoring column %s is invalid.', $name));
        }

        return $value;
    }

    /**
     * Read one nullable textual database column without treating malformed empties as absence.
     *
     * @param   array<string, mixed>  $row   Fetched database row.
     * @param   string                $name  Optional column name.
     *
     * @return  string|null  Stored optional text.
     *
     * @throws  RuntimeException  When a present column is empty or not textual.
     *
     * @since   2.0.0
     */
    private static function nullableString(array $row, string $name): ?string
    {
        $value = $row[$name] ?? null;
        if ($value !== null && (!is_string($value) || $value === '')) {
            throw new RuntimeException(sprintf('Stored Studio Content authoring column %s is invalid.', $name));
        }

        return $value;
    }

    /**
     * Read one required persisted instant across DBAL driver representations.
     *
     * @param   mixed  $value  Driver-returned temporal value.
     *
     * @return  DateTimeImmutable  Immutable stored instant.
     *
     * @throws  RuntimeException  When the value is absent or not temporal text/object data.
     * @throws  \DateMalformedStringException  When temporal text cannot be parsed.
     *
     * @since   2.0.0
     */
    private static function instant(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored Studio Content authoring instant is invalid.');
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /**
     * Grammar of a handed-type digest: a lowercase hexadecimal SHA-256.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string DIGEST = '/^[0-9a-f]{64}$/D';
}
