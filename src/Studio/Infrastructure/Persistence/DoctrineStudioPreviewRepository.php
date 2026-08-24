<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Preview\StudioPreviewGrantRepository;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRenderAdmission;
use Kumwe\App\Studio\Application\Preview\StudioPreviewSequenceClaim;
use Kumwe\App\Studio\Application\Preview\StudioPreviewSequenceRepository;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewGrant;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderedDocument;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use RuntimeException;
use stdClass;

/**
 * Portable Doctrine ledger for preview sequences, pending renders and single-use documents.
 *
 * @since  2.0.0
 */
final readonly class DoctrineStudioPreviewRepository implements
    StudioPreviewGrantRepository,
    StudioPreviewSequenceRepository
{
    /**
     * Bind the stores to the configured relational database and prefix-aware names.
     *
     * @param  Connection  $database  Configured DBAL connection.
     * @param  TableNames  $tables    Prefix-aware table compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Atomically attempt to advance one context and endpoint lane from its exact expected sequence.
     *
     * @param   string  $resourceContextKey  Opaque trusted host context.
     * @param   string  $lane                Closed `port` or `document` direction.
     * @param   int     $sequence            Candidate zero-based next sequence.
     *
     * @return  StudioPreviewSequenceClaim  Accepted, immediate-predecessor pending, or refused.
     *
     * @since   2.0.0
     */
    public function advance(
        string $resourceContextKey,
        string $lane,
        int $sequence,
    ): StudioPreviewSequenceClaim {
        if ($sequence < 0 || !in_array($lane, ['port', 'document'], true)) {
            return StudioPreviewSequenceClaim::Refused;
        }
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET next_sequence = next_sequence + 1 '
                . 'WHERE resource_context_key = ? AND lane = ? AND next_sequence = ?',
            $this->tables->quoted('studio_preview_sequences'),
        ), [$resourceContextKey, $lane, $sequence]);
        if ($affected === 1) {
            return StudioPreviewSequenceClaim::Accepted;
        }
        if ($sequence === 0) {
            try {
                $this->database->insert($this->tables->raw('studio_preview_sequences'), [
                    'resource_context_key' => $resourceContextKey,
                    'lane' => $lane,
                    'next_sequence' => 1,
                ], ['next_sequence' => Types::BIGINT]);
            } catch (UniqueConstraintViolationException) {
                return StudioPreviewSequenceClaim::Refused;
            }

            return StudioPreviewSequenceClaim::Accepted;
        }
        $next = $this->database->fetchOne(sprintf(
            'SELECT next_sequence FROM %s WHERE resource_context_key = ? AND lane = ?',
            $this->tables->quoted('studio_preview_sequences'),
        ), [$resourceContextKey, $lane]);
        if ($next !== false && !is_int($next) && !is_string($next)) {
            return StudioPreviewSequenceClaim::Refused;
        }
        $expected = $next === false ? 0 : (int) $next;
        if ($sequence === $expected) {
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET next_sequence = next_sequence + 1 '
                    . 'WHERE resource_context_key = ? AND lane = ? AND next_sequence = ?',
                $this->tables->quoted('studio_preview_sequences'),
            ), [$resourceContextKey, $lane, $sequence]);

            return $affected === 1
                ? StudioPreviewSequenceClaim::Accepted
                : StudioPreviewSequenceClaim::Refused;
        }

        return $expected < PHP_INT_MAX && $sequence === $expected + 1
            ? StudioPreviewSequenceClaim::PredecessorPending
            : StudioPreviewSequenceClaim::Refused;
    }

    /**
     * Insert one unique pending render and supersede older claimable attempts in its context.
     *
     * @param   StudioHostSessionSnapshot   $snapshot   Live trusted session binding.
     * @param   StudioPreviewRenderRequest  $request    Exact render attempt identity.
     * @param   StudioPreviewTransport      $transport  Accepted browser transport evidence.
     * @param   DateTimeImmutable           $expiresAt  Absolute short-lived grant expiry.
     *
     * @return  StudioPreviewRenderAdmission  Accepted, cancelled by a newer sequence, or replayed.
     *
     * @since   2.0.0
     */
    public function begin(
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewRenderRequest $request,
        StudioPreviewTransport $transport,
        DateTimeImmutable $expiresAt,
    ): StudioPreviewRenderAdmission {
        return $this->database->transactional(function () use (
            $snapshot,
            $request,
            $transport,
            $expiresAt,
        ): StudioPreviewRenderAdmission {
            $contextKey = $snapshot->session->resourceContextKey;
            $this->lockPortLane($contextKey);

            return $this->beginLocked($snapshot, $request, $transport, $expiresAt);
        });
    }

    /**
     * Insert one pending attempt while the context's port lane serializes begin and cancellation.
     *
     * @param   StudioHostSessionSnapshot   $snapshot   Live trusted session binding.
     * @param   StudioPreviewRenderRequest  $request    Exact render attempt identity.
     * @param   StudioPreviewTransport      $transport  Accepted browser transport evidence.
     * @param   DateTimeImmutable           $expiresAt  Absolute short-lived grant expiry.
     *
     * @return  StudioPreviewRenderAdmission  Accepted, cancelled by a newer sequence, or replayed.
     *
     * @since   2.0.0
     */
    private function beginLocked(
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewRenderRequest $request,
        StudioPreviewTransport $transport,
        DateTimeImmutable $expiresAt,
    ): StudioPreviewRenderAdmission {
        $contextKey = $snapshot->session->resourceContextKey;
        $table = $this->tables->raw('studio_preview_grants');
        $existing = $this->database->fetchOne(sprintf(
            'SELECT request_id FROM %s WHERE resource_context_key = ? AND request_id = ?',
            $this->tables->quoted('studio_preview_grants'),
        ), [$contextKey, $request->requestId]);
        if ($existing !== false) {
            return StudioPreviewRenderAdmission::Replayed;
        }
        $latest = $this->database->fetchOne(sprintf(
            'SELECT MAX(port_sequence) FROM %s WHERE resource_context_key = ?',
            $this->tables->quoted('studio_preview_grants'),
        ), [$contextKey]);
        $superseded = (is_int($latest) || is_string($latest)) && (int) $latest > $transport->sequence;
        $this->database->executeStatement(sprintf(
            "UPDATE %s SET state = 'superseded' WHERE resource_context_key = ? "
                . "AND state IN ('pending', 'ready') AND port_sequence < ?",
            $this->tables->quoted('studio_preview_grants'),
        ), [$contextKey, $transport->sequence]);
        try {
            $this->database->insert($table, [
                'resource_context_key' => $contextKey,
                'request_id' => $request->requestId,
                'actor_id' => $snapshot->session->actorId,
                'site_identifier' => $snapshot->session->siteId,
                'organization_identifier' => $snapshot->session->organizationId,
                'workspace_identifier' => $snapshot->session->workspaceId,
                'session_binding' => $snapshot->session->sessionBinding,
                'session_generation' => $snapshot->generation,
                'origin' => $transport->origin,
                'channel_id' => $transport->channelId,
                'source_id' => $transport->sourceId,
                'port_sequence' => $transport->sequence,
                'artifact_id' => $request->artifactId,
                'draft_digest' => $request->draftDigest,
                'draft_revision' => $request->draftRevision,
                'viewport' => $request->viewport,
                'state' => $superseded ? 'superseded' : 'pending',
                'html_document' => null,
                'theme_stylesheet' => null,
                'markers_json' => null,
                'marker_map_json' => null,
                'diagnostics_json' => null,
                'expires_at' => $expiresAt,
                'claimed_at' => null,
                'use_count' => 0,
            ], [
                'port_sequence' => Types::BIGINT,
                'expires_at' => Types::DATETIME_IMMUTABLE,
                'claimed_at' => Types::DATETIME_IMMUTABLE,
                'use_count' => Types::INTEGER,
            ]);
        } catch (UniqueConstraintViolationException) {
            return StudioPreviewRenderAdmission::Replayed;
        }

        if ($superseded) {
            return StudioPreviewRenderAdmission::Cancelled;
        }
        $cancelSequence = $this->database->fetchOne(sprintf(
            'SELECT cancel_port_sequence FROM %s WHERE resource_context_key = ? AND draft_digest = ?',
            $this->tables->quoted('studio_preview_cancellations'),
        ), [$contextKey, $request->draftDigest]);
        if (
            (is_int($cancelSequence) || is_string($cancelSequence))
            && (int) $cancelSequence > $transport->sequence
        ) {
            $this->database->update($table, ['state' => 'cancelled'], [
                'resource_context_key' => $contextKey,
                'request_id' => $request->requestId,
                'state' => 'pending',
            ]);

            return StudioPreviewRenderAdmission::Cancelled;
        }

        return StudioPreviewRenderAdmission::Accepted;
    }

    /**
     * Publish rendered bytes only while their exact pending attempt remains live.
     *
     * @param   string                         $resourceContextKey  Opaque trusted host context.
     * @param   StudioPreviewRenderRequest     $request             Exact render attempt identity.
     * @param   StudioPreviewRenderedDocument  $document            Canonical rendered page and markers.
     *
     * @return  bool  False after cancellation or supersession.
     *
     * @since   2.0.0
     */
    public function complete(
        string $resourceContextKey,
        StudioPreviewRenderRequest $request,
        StudioPreviewRenderedDocument $document,
    ): bool {
        $affected = $this->database->update($this->tables->raw('studio_preview_grants'), [
            'state' => 'ready',
            'html_document' => $document->html,
            'theme_stylesheet' => $document->themeStylesheet,
            'markers_json' => CanonicalJson::stringify($document->markers),
            'marker_map_json' => CanonicalJson::stringify((object) $document->markerMap),
            'diagnostics_json' => CanonicalJson::stringify($document->diagnostics),
        ], [
            'resource_context_key' => $resourceContextKey,
            'request_id' => $request->requestId,
            'draft_digest' => $request->draftDigest,
            'state' => 'pending',
        ]);

        return $affected === 1;
    }

    /**
     * Persist the highest cancellation sequence and cancel only older matching renders.
     *
     * @param   string  $resourceContextKey  Opaque trusted host context.
     * @param   string  $draftDigest         Exact canonical draft digest.
     * @param   int     $portSequence        Accepted cancellation transport sequence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function cancel(string $resourceContextKey, string $draftDigest, int $portSequence): void
    {
        if ($portSequence < 0) {
            return;
        }
        $this->database->transactional(function () use ($resourceContextKey, $draftDigest, $portSequence): void {
            $this->lockPortLane($resourceContextKey);
            $this->recordCancellation($resourceContextKey, $draftDigest, $portSequence);
            $this->database->executeStatement(sprintf(
                "UPDATE %s SET state = 'cancelled' WHERE resource_context_key = ? AND draft_digest = ? "
                    . "AND state IN ('pending', 'ready') AND port_sequence < ?",
                $this->tables->quoted('studio_preview_grants'),
            ), [$resourceContextKey, $draftDigest, $portSequence]);
        });
    }

    /**
     * Acquire the portable transaction fence already established by transport authorization.
     *
     * Every port operation first claims its sequence row. Updating that same row to its current value
     * takes a write lock on MariaDB, MySQL, PostgreSQL and SQLite without changing the next expected
     * sequence, so delayed begin and cancel transactions have one monotonic order on every platform.
     *
     * @param   string  $resourceContextKey  Opaque trusted host context.
     *
     * @return  void
     *
     * @throws  RuntimeException  When transport authorization did not establish the port-lane fence.
     *
     * @since   2.0.0
     */
    private function lockPortLane(string $resourceContextKey): void
    {
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET next_sequence = next_sequence WHERE resource_context_key = ? AND lane = ?',
            $this->tables->quoted('studio_preview_sequences'),
        ), [$resourceContextKey, 'port']);
        $next = $this->database->fetchOne(sprintf(
            'SELECT next_sequence FROM %s WHERE resource_context_key = ? AND lane = ?',
            $this->tables->quoted('studio_preview_sequences'),
        ), [$resourceContextKey, 'port']);
        if ($next === false) {
            throw new RuntimeException('The Studio preview port sequence fence is unavailable.');
        }
    }

    /**
     * Retain the greatest accepted cancellation sequence under racing or delayed requests.
     *
     * @param   string  $resourceContextKey  Opaque trusted host context.
     * @param   string  $draftDigest         Exact canonical draft digest.
     * @param   int     $portSequence        Accepted cancellation transport sequence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordCancellation(string $resourceContextKey, string $draftDigest, int $portSequence): void
    {
        $table = $this->tables->raw('studio_preview_cancellations');
        $stored = $this->cancellationSequence($resourceContextKey, $draftDigest);
        if ($stored === null) {
            $this->database->insert($table, [
                'resource_context_key' => $resourceContextKey,
                'draft_digest' => $draftDigest,
                'cancel_port_sequence' => $portSequence,
            ], ['cancel_port_sequence' => Types::BIGINT]);

            return;
        }
        if ($stored >= $portSequence) {
            return;
        }
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET cancel_port_sequence = ? WHERE resource_context_key = ? AND draft_digest = ? '
                . 'AND cancel_port_sequence = ?',
            $this->tables->quoted('studio_preview_cancellations'),
        ), [$portSequence, $resourceContextKey, $draftDigest, $stored], [
            Types::BIGINT,
            Types::STRING,
            Types::STRING,
            Types::BIGINT,
        ]);
        if ($affected !== 1) {
            throw new RuntimeException('The Studio preview cancellation sequence changed outside its port fence.');
        }
    }

    /**
     * Read one persisted cancellation sequence without conflating zero with absence.
     *
     * @param   string  $resourceContextKey  Opaque trusted host context.
     * @param   string  $draftDigest         Exact canonical draft digest.
     *
     * @return  int|null  Highest cancellation sequence or null when none exists.
     *
     * @since   2.0.0
     */
    private function cancellationSequence(string $resourceContextKey, string $draftDigest): ?int
    {
        $sequence = $this->database->fetchOne(sprintf(
            'SELECT cancel_port_sequence FROM %s WHERE resource_context_key = ? AND draft_digest = ?',
            $this->tables->quoted('studio_preview_cancellations'),
        ), [$resourceContextKey, $draftDigest]);

        return is_int($sequence) || is_string($sequence) ? (int) $sequence : null;
    }

    /**
     * Retain a failed request identity while removing it from claimable state.
     *
     * @param   string  $resourceContextKey  Opaque trusted host context.
     * @param   string  $requestId           Session-unique render attempt.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function abandon(string $resourceContextKey, string $requestId): void
    {
        $this->database->update($this->tables->raw('studio_preview_grants'), [
            'state' => 'failed',
        ], [
            'resource_context_key' => $resourceContextKey,
            'request_id' => $requestId,
            'state' => 'pending',
        ]);
    }

    /**
     * Atomically consume one live authority- and transport-bound document grant.
     *
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted session binding.
     * @param   string                     $requestId  Session-unique render attempt.
     * @param   StudioPreviewTransport     $transport  Accepted document transport evidence.
     * @param   DateTimeImmutable          $now        Trusted claim time.
     *
     * @return  StudioPreviewGrant|null  Complete grant, or null after absence, expiry, cancellation, or replay.
     *
     * @since   2.0.0
     */
    public function claim(
        StudioHostSessionSnapshot $snapshot,
        string $requestId,
        StudioPreviewTransport $transport,
        DateTimeImmutable $now,
    ): ?StudioPreviewGrant {
        $session = $snapshot->session;
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET state = 'claimed', use_count = 1, claimed_at = ? "
                . "WHERE resource_context_key = ? AND request_id = ? AND actor_id = ? AND site_identifier = ? "
                . 'AND session_binding = ? AND session_generation = ? AND origin = ? AND channel_id = ? '
                . "AND source_id = ? AND state = 'ready' AND use_count = 0 AND expires_at >= ?",
            $this->tables->quoted('studio_preview_grants'),
        ), [
            $now,
            $session->resourceContextKey,
            $requestId,
            $session->actorId,
            $session->siteId,
            $session->sessionBinding,
            $snapshot->generation,
            $transport->origin,
            $transport->channelId,
            $transport->sourceId,
            $now,
        ], [
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
        ]);
        if ($affected !== 1) {
            return null;
        }
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE resource_context_key = ? AND request_id = ?',
            $this->tables->quoted('studio_preview_grants'),
        ), [$session->resourceContextKey, $requestId]);
        if ($row === false) {
            throw new RuntimeException('A claimed Studio preview grant disappeared.');
        }

        return $this->grant($row);
    }

    /**
     * Read one live, already-claimed grant for its authenticated same-origin subresources.
     *
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted session binding.
     * @param   string                     $requestId  Session-unique render attempt.
     * @param   StudioPreviewTransport     $transport  Exact channel/source/origin evidence.
     * @param   DateTimeImmutable          $now        Trusted read time.
     *
     * @return  StudioPreviewGrant|null  Claimed live grant, or null after absence, expiry or mismatch.
     *
     * @since   2.0.0
     */
    public function claimed(
        StudioHostSessionSnapshot $snapshot,
        string $requestId,
        StudioPreviewTransport $transport,
        DateTimeImmutable $now,
    ): ?StudioPreviewGrant {
        $session = $snapshot->session;
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE resource_context_key = ? AND request_id = ? AND actor_id = ? '
                . 'AND site_identifier = ? AND session_binding = ? AND session_generation = ? AND origin = ? '
                . "AND channel_id = ? AND source_id = ? AND state = 'claimed' AND use_count = 1 AND expires_at >= ?",
            $this->tables->quoted('studio_preview_grants'),
        ), [
            $session->resourceContextKey,
            $requestId,
            $session->actorId,
            $session->siteId,
            $session->sessionBinding,
            $snapshot->generation,
            $transport->origin,
            $transport->channelId,
            $transport->sourceId,
            $now,
        ], [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
        ]);

        return $row === false ? null : $this->grant($row);
    }

    /**
     * Reconstitute a fully claimed grant while revalidating every persisted scalar.
     *
     * @param   array<string, mixed>  $row  Claimed database row.
     *
     * @return  StudioPreviewGrant  Revalidated grant.
     *
     * @throws  RuntimeException  When persisted data is corrupt.
     *
     * @since   2.0.0
     */
    private function grant(array $row): StudioPreviewGrant
    {
        $markers = $this->decode($row, 'markers_json');
        $markerMap = $this->decode($row, 'marker_map_json');
        $diagnostics = $this->decode($row, 'diagnostics_json');
        if (!is_array($markers) || !array_is_list($markers) || !$markerMap instanceof stdClass) {
            throw new RuntimeException('A stored Studio preview marker inventory is corrupt.');
        }
        $markerStrings = [];
        foreach ($markers as $marker) {
            if (!is_string($marker)) {
                throw new RuntimeException('A stored Studio preview marker is corrupt.');
            }
            $markerStrings[] = $marker;
        }
        $map = $this->markerMap($markerMap);
        if (!is_array($diagnostics) || !array_is_list($diagnostics)) {
            throw new RuntimeException('Stored Studio preview diagnostics are corrupt.');
        }
        $diagnosticObjects = [];
        foreach ($diagnostics as $diagnostic) {
            if (!$diagnostic instanceof stdClass) {
                throw new RuntimeException('Stored Studio preview diagnostics are corrupt.');
            }
            $diagnosticObjects[] = $diagnostic;
        }
        $expiresAt = $row['expires_at'] ?? null;
        if (is_string($expiresAt)) {
            $expiresAt = new DateTimeImmutable($expiresAt);
        }
        if (!$expiresAt instanceof DateTimeImmutable) {
            throw new RuntimeException('A stored Studio preview expiry is corrupt.');
        }

        return new StudioPreviewGrant(
            $this->string($row, 'resource_context_key'),
            $this->string($row, 'actor_id'),
            $this->string($row, 'site_identifier'),
            $this->nullableString($row, 'organization_identifier'),
            $this->nullableString($row, 'workspace_identifier'),
            $this->string($row, 'session_binding'),
            $this->string($row, 'session_generation'),
            $this->string($row, 'origin'),
            $this->string($row, 'channel_id'),
            $this->string($row, 'source_id'),
            new StudioPreviewRenderRequest(
                $this->string($row, 'artifact_id'),
                $this->string($row, 'draft_digest'),
                $this->string($row, 'draft_revision'),
                $this->string($row, 'request_id'),
                $this->string($row, 'viewport'),
            ),
            new StudioPreviewRenderedDocument(
                $this->string($row, 'html_document'),
                $markerStrings,
                $map,
                $diagnosticObjects,
                $this->nullableString($row, 'theme_stylesheet'),
            ),
            $expiresAt,
        );
    }

    /**
     * Revalidate an object as the exact marker-to-node string map.
     *
     * @param   stdClass  $markerMap  Decoded persisted marker map.
     *
     * @return  array<string, string>  Revalidated marker inventory.
     *
     * @throws  RuntimeException  When any persisted node identity is not text.
     *
     * @since   2.0.0
     */
    private function markerMap(stdClass $markerMap): array
    {
        $map = [];
        foreach (get_object_vars($markerMap) as $marker => $nodeId) {
            if (!is_string($marker) || !is_string($nodeId)) {
                throw new RuntimeException('A stored Studio preview marker map is corrupt.');
            }
            $map[$marker] = $nodeId;
        }

        return $map;
    }

    /**
     * Decode one canonical JSON database column.
     *
     * @param   array<string, mixed>  $row   Persisted grant row.
     * @param   string                $name  Canonical JSON column name.
     *
     * @return  mixed  Decoded JSON value.
     *
     * @throws  RuntimeException  When bytes are missing, invalid or non-canonical.
     *
     * @since   2.0.0
     */
    private function decode(array $row, string $name): mixed
    {
        $bytes = $this->string($row, $name);
        try {
            $decoded = json_decode($bytes, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored Studio preview JSON is corrupt.', 0, $exception);
        }
        if (!hash_equals($bytes, CanonicalJson::stringify($decoded))) {
            throw new RuntimeException('Stored Studio preview JSON is not canonical.');
        }

        return $decoded;
    }

    /**
     * Read one required non-empty textual column.
     *
     * @param   array<string, mixed>  $row   Persisted grant row.
     * @param   string                $name  Textual column name.
     *
     * @return  string  Stored text.
     *
     * @throws  RuntimeException  When absent or empty.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $name): string
    {
        $value = $row[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored Studio preview column %s is invalid.', $name));
        }

        return $value;
    }

    /**
     * Read one nullable non-empty textual column.
     *
     * @param   array<string, mixed>  $row   Persisted grant row.
     * @param   string                $name  Nullable textual column name.
     *
     * @return  string|null  Stored text or null.
     *
     * @throws  RuntimeException  When a present value is not non-empty text.
     *
     * @since   2.0.0
     */
    private function nullableString(array $row, string $name): ?string
    {
        $value = $row[$name] ?? null;
        if ($value !== null && (!is_string($value) || $value === '')) {
            throw new RuntimeException(sprintf('Stored Studio preview column %s is invalid.', $name));
        }

        return $value;
    }
}
