<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioIdempotencyRace;
use Kumwe\App\Studio\Application\Host\StudioIdempotencyRepository;
use Kumwe\App\Studio\Application\Host\StudioPersistenceRace;
use Kumwe\App\Studio\Application\Host\StudioRecoveryRepository;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use Kumwe\App\Studio\Domain\Host\StudioIdempotencyRecord;
use LogicException;
use RuntimeException;

/**
 * Portable DBAL adapter for immutable artifacts, mutation replay and scoped recovery.
 *
 * Every write joins the application transaction. Artifact heads move with a conditional update while
 * their immutable revision row is inserted in the same transaction. Idempotency claims arbitrate by a
 * unique scope digest, and rate windows are locked on production engines before their counter advances.
 * Canonical documents are TEXT, not driver JSON, so the database cannot reorder or renumber bytes.
 *
 * @since  2.0.0
 */
final readonly class DoctrineStudioHostStorage implements
    StudioArtifactRepository,
    StudioIdempotencyRepository,
    StudioRecoveryRepository
{
    /**
     * Bind the adapter to one database connection and prefix-aware table compiler.
     *
     * @param  Connection  $database  Authoritative application database.
     * @param  TableNames  $tables    Installation table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Load the current artifact head within one trusted site.
     *
     * @param   string  $siteIdentifier  Trusted site scope.
     * @param   string  $id              Canonical artifact identifier.
     * @param   string  $version         Canonical artifact version.
     *
     * @return  StoredStudioArtifact|null  Current artifact or null when absent.
     *
     * @since   2.0.0
     */
    public function current(string $siteIdentifier, string $id, string $version): ?StoredStudioArtifact
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? AND artifact_id = ? AND artifact_version = ?',
            $this->tables->quoted('studio_artifact_heads'),
        ), [$siteIdentifier, $id, $version]);

        return $row === false ? null : $this->artifact($row);
    }

    /**
     * Load one immutable historical artifact revision.
     *
     * @param   string  $siteIdentifier  Trusted site scope.
     * @param   string  $id              Canonical artifact identifier.
     * @param   string  $version         Canonical artifact version.
     * @param   string  $revision        Exact historical revision.
     *
     * @return  StoredStudioArtifact|null  Historical artifact or null when absent.
     *
     * @since   2.0.0
     */
    public function revision(
        string $siteIdentifier,
        string $id,
        string $version,
        string $revision,
    ): ?StoredStudioArtifact {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? AND artifact_id = ? '
                . 'AND artifact_version = ? AND revision = ?',
            $this->tables->quoted('studio_artifact_revisions'),
        ), [$siteIdentifier, $id, $version, $revision]);

        return $row === false ? null : $this->artifact($row);
    }

    /**
     * Append an immutable revision and conditionally advance its artifact head.
     *
     * @param   StoredStudioArtifact  $artifact         Next admitted artifact revision.
     * @param   string|null           $expectedCurrent  Expected current head or null on creation.
     *
     * @return  bool  False when the head changed before compare-and-set.
     *
     * @since   2.0.0
     */
    public function store(StoredStudioArtifact $artifact, ?string $expectedCurrent): bool
    {
        $this->assertTransaction();
        $values = [
            'site_identifier' => $artifact->siteIdentifier,
            'artifact_id' => $artifact->id,
            'artifact_version' => $artifact->version,
            'artifact_kind' => $artifact->kind,
            'revision' => $artifact->revision,
            'status' => $artifact->status,
            'canonical_document' => $artifact->canonicalDocument,
            'canonical_dependencies' => $artifact->canonicalDependencies,
            'recorded_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ];
        if ($expectedCurrent === null) {
            try {
                $this->database->insert(
                    $this->tables->raw('studio_artifact_heads'),
                    $values,
                    ['recorded_at' => Types::DATETIME_IMMUTABLE],
                );
            } catch (UniqueConstraintViolationException $exception) {
                throw new StudioPersistenceRace('A Studio artifact head was concurrently created.', 0, $exception);
            }
        } else {
            $head = $values;
            unset($head['site_identifier'], $head['artifact_id'], $head['artifact_version']);
            $affected = $this->database->update(
                $this->tables->raw('studio_artifact_heads'),
                $head,
                [
                    'site_identifier' => $artifact->siteIdentifier,
                    'artifact_id' => $artifact->id,
                    'artifact_version' => $artifact->version,
                    'revision' => $expectedCurrent,
                ],
                ['recorded_at' => Types::DATETIME_IMMUTABLE],
            );
            if ($affected !== 1) {
                return false;
            }
        }
        try {
            $this->database->insert(
                $this->tables->raw('studio_artifact_revisions'),
                $values,
                ['recorded_at' => Types::DATETIME_IMMUTABLE],
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new StudioPersistenceRace('A Studio artifact revision was concurrently created.', 0, $exception);
        }

        return true;
    }

    /**
     * Find one durable idempotency claim by its complete scope digest.
     *
     * @param   string  $scopeDigest  Actor/session/resource/operation/key scope digest.
     *
     * @return  StudioIdempotencyRecord|null  Existing claim or null.
     *
     * @since   2.0.0
     */
    public function find(string $scopeDigest): ?StudioIdempotencyRecord
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT scope_digest, intent_digest, state, result_bytes FROM %s WHERE scope_digest = ?',
            $this->tables->quoted('studio_host_idempotency'),
        ), [$scopeDigest]);
        if ($row === false) {
            return null;
        }
        $scope = $this->string($row, 'scope_digest');
        $intent = $this->string($row, 'intent_digest');
        $state = $this->string($row, 'state');
        $result = $row['result_bytes'] ?? null;
        if ($result !== null && !is_string($result)) {
            throw new RuntimeException('A stored Studio idempotency result is corrupt.');
        }
        if (!in_array($state, ['claimed', 'completed'], true) || ($state === 'completed') !== ($result !== null)) {
            throw new RuntimeException('A stored Studio idempotency state is corrupt.');
        }

        return new StudioIdempotencyRecord($scope, $intent, $result);
    }

    /**
     * Claim one durable idempotency scope inside the caller's transaction.
     *
     * @param   StudioIdempotencyRecord    $record    New pending claim.
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live host session.
     * @param   StudioHostRequest          $request   Validated canonical request.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function begin(
        StudioIdempotencyRecord $record,
        StudioHostSessionSnapshot $snapshot,
        StudioHostRequest $request,
    ): void {
        $this->assertTransaction();
        if ($request->idempotencyKey === null) {
            throw new LogicException('A Studio idempotency claim requires a key.');
        }
        try {
            $this->database->insert($this->tables->raw('studio_host_idempotency'), [
                'scope_digest' => $record->scopeDigest,
                'intent_digest' => $record->intentDigest,
                'actor_id' => $snapshot->session->actorId,
                'session_binding' => $snapshot->session->sessionBinding,
                'resource_context_key' => $request->resourceContextKey,
                'session_generation' => $request->sessionGeneration,
                'operation_id' => $request->operationId,
                'idempotency_key' => $request->idempotencyKey,
                'state' => 'claimed',
                'result_bytes' => null,
                'created_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'completed_at' => null,
            ], [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'completed_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new StudioIdempotencyRace('The Studio idempotency scope is already claimed.', 0, $exception);
        }
    }

    /**
     * Complete one pending idempotency claim exactly once.
     *
     * @param   string  $scopeDigest  Existing claim scope.
     * @param   string  $resultBytes  Canonical completed-result bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function complete(string $scopeDigest, string $resultBytes): void
    {
        $this->assertTransaction();
        $affected = $this->database->update($this->tables->raw('studio_host_idempotency'), [
            'state' => 'completed',
            'result_bytes' => $resultBytes,
            'completed_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ], ['scope_digest' => $scopeDigest, 'state' => 'claimed'], [
            'completed_at' => Types::DATETIME_IMMUTABLE,
        ]);
        if ($affected !== 1) {
            throw new RuntimeException('A Studio idempotency claim could not be completed exactly once.');
        }
    }

    /**
     * Load recovery bytes from their complete actor, session and resource scope.
     *
     * @param   string  $actorId             Trusted actor identifier.
     * @param   string  $sessionBinding      Trusted browser-session binding.
     * @param   string  $resourceContextKey  Opaque resource context key.
     *
     * @return  string|null  Canonical envelope bytes or null.
     *
     * @since   2.0.0
     */
    public function loadEnvelope(string $actorId, string $sessionBinding, string $resourceContextKey): ?string
    {
        $value = $this->database->fetchOne(sprintf(
            'SELECT canonical_envelope FROM %s WHERE resource_context_key = ? '
                . 'AND actor_id = ? AND session_binding = ?',
            $this->tables->quoted('studio_recovery_envelopes'),
        ), [$resourceContextKey, $actorId, $sessionBinding]);
        if ($value === false) {
            return null;
        }
        if (!is_string($value)) {
            throw new RuntimeException('A stored Studio recovery envelope is corrupt.');
        }

        return $value;
    }

    /**
     * Upsert canonical recovery bytes within their complete trusted scope.
     *
     * @param   string  $actorId                Trusted actor identifier.
     * @param   string  $sessionBinding         Trusted browser-session binding.
     * @param   string  $resourceContextKey     Opaque resource context key.
     * @param   string  $canonicalEnvelope      Exact canonical envelope bytes.
     * @param   int     $updatedAtMilliseconds  Server update instant in epoch milliseconds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function saveEnvelope(
        string $actorId,
        string $sessionBinding,
        string $resourceContextKey,
        string $canonicalEnvelope,
        int $updatedAtMilliseconds,
    ): void {
        $this->assertTransaction();
        $affected = $this->database->update($this->tables->raw('studio_recovery_envelopes'), [
            'canonical_envelope' => $canonicalEnvelope,
            'envelope_bytes' => strlen($canonicalEnvelope),
            'updated_at_milliseconds' => $updatedAtMilliseconds,
        ], [
            'resource_context_key' => $resourceContextKey,
            'actor_id' => $actorId,
            'session_binding' => $sessionBinding,
        ]);
        if ($affected === 1) {
            return;
        }
        try {
            $this->database->insert($this->tables->raw('studio_recovery_envelopes'), [
                'resource_context_key' => $resourceContextKey,
                'actor_id' => $actorId,
                'session_binding' => $sessionBinding,
                'canonical_envelope' => $canonicalEnvelope,
                'envelope_bytes' => strlen($canonicalEnvelope),
                'updated_at_milliseconds' => $updatedAtMilliseconds,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new StudioPersistenceRace('A Studio recovery scope was concurrently created.', 0, $exception);
        }
    }

    /**
     * Delete recovery bytes only within their complete trusted scope.
     *
     * @param   string  $actorId             Trusted actor identifier.
     * @param   string  $sessionBinding      Trusted browser-session binding.
     * @param   string  $resourceContextKey  Opaque resource context key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function discardEnvelope(string $actorId, string $sessionBinding, string $resourceContextKey): void
    {
        $this->assertTransaction();
        $this->database->delete($this->tables->raw('studio_recovery_envelopes'), [
            'resource_context_key' => $resourceContextKey,
            'actor_id' => $actorId,
            'session_binding' => $sessionBinding,
        ]);
    }

    /**
     * Atomically consume one fixed-window recovery-write unit.
     *
     * @param   string  $scopeDigest         Complete recovery-write scope digest.
     * @param   int     $nowMilliseconds     Server instant in epoch milliseconds.
     * @param   int     $windowMilliseconds  Fixed window duration.
     * @param   int     $maximumRequests     Maximum accepted writes per window.
     *
     * @return  int|null  Remaining milliseconds when refused, otherwise null.
     *
     * @since   2.0.0
     */
    public function consumeRateLimit(
        string $scopeDigest,
        int $nowMilliseconds,
        int $windowMilliseconds,
        int $maximumRequests,
    ): ?int {
        $this->assertTransaction();
        $lock = $this->database->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT window_started_milliseconds, request_count FROM %s WHERE scope_digest = ?%s',
            $this->tables->quoted('studio_recovery_rate_limits'),
            $lock,
        ), [$scopeDigest]);
        if ($row === false) {
            try {
                $this->database->insert($this->tables->raw('studio_recovery_rate_limits'), [
                    'scope_digest' => $scopeDigest,
                    'window_started_milliseconds' => $nowMilliseconds,
                    'request_count' => 1,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                throw new StudioPersistenceRace(
                    'A Studio recovery rate window was concurrently created.',
                    0,
                    $exception,
                );
            }

            return null;
        }
        $started = $this->integer($row, 'window_started_milliseconds');
        $count = $this->integer($row, 'request_count');
        if ($nowMilliseconds >= $started + $windowMilliseconds) {
            $this->database->update($this->tables->raw('studio_recovery_rate_limits'), [
                'window_started_milliseconds' => $nowMilliseconds,
                'request_count' => 1,
            ], ['scope_digest' => $scopeDigest]);

            return null;
        }
        if ($count >= $maximumRequests) {
            return max(1, $started + $windowMilliseconds - $nowMilliseconds);
        }
        $affected = $this->database->update($this->tables->raw('studio_recovery_rate_limits'), [
            'request_count' => $count + 1,
        ], [
            'scope_digest' => $scopeDigest,
            'window_started_milliseconds' => $started,
            'request_count' => $count,
        ]);
        if ($affected !== 1) {
            throw new StudioPersistenceRace('A Studio recovery rate window changed concurrently.');
        }

        return null;
    }

    /**
     * Reconstitute one artifact while its constructor re-proves exact canonical bytes.
     *
     * @param   array<string, mixed>  $row  Head or history row.
     *
     * @return  StoredStudioArtifact  Reconstituted artifact value.
     *
     * @since   2.0.0
     */
    private function artifact(array $row): StoredStudioArtifact
    {
        return new StoredStudioArtifact(
            $this->string($row, 'site_identifier'),
            $this->string($row, 'artifact_id'),
            $this->string($row, 'artifact_version'),
            $this->string($row, 'artifact_kind'),
            $this->string($row, 'revision'),
            $this->string($row, 'status'),
            $this->string($row, 'canonical_document'),
            $this->string($row, 'canonical_dependencies'),
        );
    }

    /**
     * Require every mutation to join the application transaction boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertTransaction(): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new LogicException('Studio host persistence writes require an active transaction.');
        }
    }

    /**
     * Read one required string column from an untrusted database row.
     *
     * @param   array<string, mixed>  $row     Database row.
     * @param   string                $column  Required column name.
     *
     * @return  string  Exact stored string.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('A Studio host persistence row is corrupt.');
        }

        return $value;
    }

    /**
     * Read one exact integer column from an untrusted database row.
     *
     * @param   array<string, mixed>  $row     Database row.
     * @param   string                $column  Required column name.
     *
     * @return  int  Exact stored integer.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^-?\d+$/D', $value) !== 1)) {
            throw new RuntimeException('A Studio host persistence row is corrupt.');
        }

        return (int) $value;
    }
}
