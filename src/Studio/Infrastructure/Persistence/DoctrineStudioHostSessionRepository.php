<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Host\StudioHostSessionRepository;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use RuntimeException;

/**
 * Doctrine store for opaque Studio host-session bindings.
 *
 * @since  2.0.0
 */
final readonly class DoctrineStudioHostSessionRepository implements StudioHostSessionRepository
{
    /**
     * Bind opaque session persistence to the installation's prefix-aware database.
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
     * Insert a complete immutable binding before the caller returns its opaque key.
     *
     * @param   StudioHostSession  $session  Verified binding containing no credential or policy reason.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the database refuses the insert.
     *
     * @since   2.0.0
     */
    public function add(StudioHostSession $session): void
    {
        $this->connection->insert($this->tables->raw('studio_host_sessions'), [
            'resource_context_key' => $session->resourceContextKey,
            'actor_id' => $session->actorId,
            'site_identifier' => $session->siteId,
            'organization_identifier' => $session->organizationId,
            'workspace_identifier' => $session->workspaceId,
            'surface' => $session->surface,
            'session_binding' => $session->sessionBinding,
            'mode' => $session->mode->value,
            'resource_kind' => $session->resourceKind->value,
            'resource_identifier' => $session->resourceId,
            'session_generation' => $session->sessionGeneration,
        ]);
    }

    /**
     * Resolve one binding by opaque key and revalidate every persisted scalar.
     *
     * @param   string  $resourceContextKey  Canonical context key from a validated host envelope.
     *
     * @return  StudioHostSession|null  Revalidated binding, or null when no row exists.
     *
     * @throws  \Doctrine\DBAL\Exception  When the database read fails.
     * @throws  RuntimeException  When persisted metadata violates the domain invariants.
     *
     * @since   2.0.0
     */
    public function find(string $resourceContextKey): ?StudioHostSession
    {
        $row = $this->connection->fetchAssociative(sprintf(
            'SELECT actor_id, site_identifier, organization_identifier, workspace_identifier, surface, '
                . 'session_binding, '
                . 'mode, resource_kind, resource_identifier, session_generation FROM %s '
                . 'WHERE resource_context_key = ?',
            $this->tables->quoted('studio_host_sessions'),
        ), [$resourceContextKey], [ParameterType::STRING]);
        if ($row === false) {
            return null;
        }

        try {
            return new StudioHostSession(
                $resourceContextKey,
                self::string($row, 'actor_id'),
                self::string($row, 'site_identifier'),
                self::nullableString($row, 'organization_identifier'),
                self::nullableString($row, 'workspace_identifier'),
                self::string($row, 'surface'),
                self::string($row, 'session_binding'),
                StudioSessionMode::from(self::string($row, 'mode')),
                StudioResourceKind::from(self::string($row, 'resource_kind')),
                self::string($row, 'resource_identifier'),
                self::string($row, 'session_generation'),
            );
        } catch (InvalidArgumentException | \ValueError $exception) {
            throw new RuntimeException('Stored Studio host-session metadata is invalid.', 0, $exception);
        }
    }

    /**
     * Read one required non-empty textual database column.
     *
     * @param   array<string, mixed>  $row   Fetched database row.
     * @param   string                $name  Required column name.
     *
     * @return  string  Stored non-empty text.
     *
     * @throws  RuntimeException  When the column is absent, empty or not textual.
     *
     * @since   2.0.0
     */
    private static function string(array $row, string $name): string
    {
        $value = $row[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored Studio host-session column %s is invalid.', $name));
        }

        return $value;
    }

    /**
     * Read one nullable textual database column without converting invalid empties to absence.
     *
     * @param   array<string, mixed>  $row   Fetched database row.
     * @param   string                $name  Optional column name.
     *
     * @return  string|null  Stored non-empty text or null.
     *
     * @throws  RuntimeException  When a present column is empty or not textual.
     *
     * @since   2.0.0
     */
    private static function nullableString(array $row, string $name): ?string
    {
        $value = $row[$name] ?? null;
        if ($value !== null && (!is_string($value) || $value === '')) {
            throw new RuntimeException(sprintf('Stored Studio host-session column %s is invalid.', $name));
        }

        return $value;
    }
}
