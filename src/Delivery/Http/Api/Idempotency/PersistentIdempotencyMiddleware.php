<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Idempotency;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Persistence\TransactionManager;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Laminas\Diactoros\Response;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Makes an API mutation idempotent by reserving its key, running it once, and replaying the stored result.
 *
 * This is the general-purpose half of the idempotency pair: the mutating API routes mount it behind
 * `RequireIdempotencyKeyMiddleware`. It stores the handler's response verbatim, which is precisely why
 * token issuance and rotation use `SecretOnceIdempotencyMiddleware` instead — those bodies carry a live
 * credential. A record is keyed by subject, operation and key, and the unique index over those three is
 * what arbitrates the reservation, so two simultaneous first attempts cannot both reach the handler.
 * What a caller is promised: a completed operation is replayed with `Idempotency-Replayed: true` rather
 * than repeated, a key reused for different content is refused with 422, a key presented under
 * different credentials with 409, and an attempt still in flight with 409. Exact authorization runs
 * before the ledger is read at all, so a replay can never be used to probe for a mutation the caller
 * may not perform. The handler's writes and the record that marks the key spent commit in one
 * transaction, so a stored replay always corresponds to an effect that actually landed; a 5xx or a
 * thrown fault deletes the reservation instead, leaving the key free for another attempt.
 *
 * @since  2.0.0
 */
final readonly class PersistentIdempotencyMiddleware implements MiddlewareInterface
{
    /**
     * How long a reservation stays owned by the request that took it, in seconds.
     *
     * The window is generous because the mutations behind it can be slow. A request that died mid-flight
     * blocks its key for at most this long, after which another attempt may take the record over.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int PROCESSING_LEASE_SECONDS = 900;

    /**
     * How long a record stays replayable after it is written, in seconds.
     *
     * Once the window closes the record no longer answers a repeat: the key may be claimed afresh by the
     * next request that presents it, and `DoctrineIdempotencyPurger` is free to delete the row.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int RETENTION_SECONDS = 86_400;

    /**
     * Wire the middleware to the ledger, the clock and the policy check a reservation depends on.
     *
     * @param  Connection                     $database          Connection the idempotency ledger is read
     *         and written on.
     * @param  TableNames                     $tables            Resolves the physical `idempotency` table
     *         name.
     * @param  ClockInterface                 $clock             Supplies the instants leases, retention and
     *         expiry are measured from.
     * @param  ProblemDetailsResponseFactory  $problems          Renders the refusals a reused or contested
     *         key is answered with.
     * @param  TransactionManager             $transactions      Commits the mutation and the record marking
     *         its key spent together, or neither.
     * @param  HttpMutationPreauthorizer      $preauthorization  Applies the route's exact policy before any
     *         record is observed or reserved.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private ProblemDetailsResponseFactory $problems,
        private TransactionManager $transactions,
        private HttpMutationPreauthorizer $preauthorization,
    ) {
    }

    /**
     * Reserve the key, run the mutation at most once, and keep its response for later repeats.
     *
     * The order of the steps is the security property. Authorization is applied first, before a single
     * ledger row is read, so a caller cannot learn from a replay or a 409 that an operation it may not
     * perform is already under way. The reservation is then an insert, so the unique index decides
     * between simultaneous first attempts rather than a read-then-write that could interleave; only the
     * loser walks the slower replay-or-takeover path, and when that path yields a response the handler is
     * never called. The handler and the write that marks the record `completed` share one transaction, so
     * a stored replay can only exist for a mutation that committed. Both failure paths give the key back:
     * a 5xx is carried out of the transaction as `ServerFailureResponse` so the rollback happens first and
     * the client still receives the handler's own response, while any other fault deletes the reservation
     * without asserting ownership — a lost lease must not mask the original exception — and re-throws.
     *
     * @param   ServerRequestInterface   $request  Mutation carrying the validated `Idempotency-Key`, the
     *          authenticated principal and the execution context bound to it, all attached upstream.
     * @param   RequestHandlerInterface  $handler  Rest of the pipeline, which performs the mutation.
     *
     * @return  ResponseInterface  The handler's response on a first run; on a repeat, either the stored
     *          response marked `Idempotency-Replayed: true`, or a problem document reporting a malformed
     *          report-export identifier, reused key, changed authorization context, or attempt still in flight.
     *
     * @throws  RuntimeException  When the request reaches this middleware without a validated key, an
     *          authenticated principal or a context bound to that principal; when the record cannot be
     *          read back; when the lease is lost before the completion write; or when a stored result
     *          fails its integrity check while being replayed.
     * @throws  \InvalidArgumentException  When the route carries no policy in `HttpMutationPreauthorizer`,
     *          a path segment is not a usable resource identifier, or the body that policy reads is not a
     *          usable JSON object.
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not perform the
     *          mutation, or may not delegate a capability it would hand on — checked before the ledger is
     *          touched.
     * @throws  \Kumwe\CMS\Content\Application\ContentNotFound  When a workflow transition names an entry
     *          the context cannot reach.
     * @throws  \Kumwe\CMS\Content\Application\ContentModelNotFound  When the entry's pinned workflow
     *          version is no longer published.
     * @throws  \Kumwe\CMS\Workflow\Domain\InvalidWorkflowTransition  When the workflow declares no edge to
     *          the requested status.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $request->getAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE);
        $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        $context = $request->getAttribute(ExecutionContext::REQUEST_ATTRIBUTE);
        if (
            !$key instanceof IdempotencyKey
            || !$principal instanceof AuthenticatedPrincipal
            || !$context instanceof ExecutionContext
            || $context->principal() !== $principal
        ) {
            throw new RuntimeException('Persistent idempotency requires an authenticated request and validated key.');
        }

        // Replay is an authorization-sensitive read and must never precede exact use-case authorization.
        try {
            $this->preauthorization->authorize($request, $context);
        } catch (InvalidReportExportRequest) {
            return $this->problems->create(
                422,
                'Invalid business report export request',
                'The business report export identifier is invalid.',
                'urn:kumwe:problem:invalid-business-report-export',
                (string) $request->getUri(),
            )->withHeader('Cache-Control', 'no-store');
        }
        $subject = $principal->subject();
        $authorizationFingerprint = $context->authorizationFingerprint();
        $operation = strtoupper($request->getMethod()) . ' ' . $request->getUri()->getPath();
        $keyValue = (string) $key;
        $digest = $this->requestDigest($request);
        $ownerToken = Uuid::uuid7()->toString();
        $now = $this->clock->now();

        try {
            $this->database->insert($this->tables->raw('idempotency'), [
                'id' => Uuid::uuid7()->toString(),
                'idempotency_key' => $keyValue,
                'subject' => $subject,
                'operation' => $operation,
                'request_digest' => $digest,
                'authorization_fingerprint' => $authorizationFingerprint,
                'state' => 'in_progress',
                'owner_token' => $ownerToken,
                'locked_until' => $now->modify('+' . self::PROCESSING_LEASE_SECONDS . ' seconds'),
                'lease_owner' => $ownerToken,
                'lease_expires_at' => $now->modify('+' . self::PROCESSING_LEASE_SECONDS . ' seconds'),
                'result_status' => null,
                'result_body' => null,
                'result_headers' => null,
                'result_body_digest' => null,
                'created_at' => $now,
                'completed_at' => null,
                'expires_at' => $now->modify('+' . self::RETENTION_SECONDS . ' seconds'),
            ], [
                'locked_until' => Types::DATETIME_IMMUTABLE,
                'lease_expires_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException) {
            $result = $this->replayOrAcquire(
                $subject,
                $operation,
                $keyValue,
                $digest,
                $authorizationFingerprint,
                $ownerToken,
                $request,
            );
            if ($result instanceof ResponseInterface) {
                return $result;
            }
        }

        try {
            return $this->transactions->transactional(function () use (
                $handler,
                $request,
                $subject,
                $operation,
                $keyValue,
                $ownerToken,
            ): ResponseInterface {
                $response = $handler->handle($request);
                if ($response->getStatusCode() >= 500) {
                    throw new ServerFailureResponse($response);
                }
                $this->complete($subject, $operation, $keyValue, $ownerToken, $response);
                return $response;
            });
        } catch (ServerFailureResponse $failure) {
            $this->release($subject, $operation, $keyValue, $ownerToken, true);
            return $failure->response;
        } catch (Throwable $failure) {
            $this->release($subject, $operation, $keyValue, $ownerToken, false);
            throw $failure;
        }
    }

    /**
     * Answer the repeat that lost the reservation, or take the record over when it is nobody's any more.
     *
     * Reached only after the unique index refused the insert, so a record for this subject, operation and
     * key certainly existed a moment ago. Expiry is judged before anything else: a record past its
     * retention window is dead, so it is taken over without comparing it to this request at all, and
     * losing that takeover re-reads the row — the one path that loops, three times over before the caller
     * is told to retry the request itself. A live record is instead held to both fingerprints before its
     * state is revealed — a key reused for different content is refused with 422, one presented under a
     * different credential with 409 — and only then is a completed result replayed. A failed record, or an
     * in-progress one whose lease has lapsed, is claimed by a conditional update, and losing that claim
     * falls through to the 409 that reports an attempt still in flight.
     *
     * @param   string                  $subject                   Principal the record is keyed against.
     * @param   string                  $operation                 Method and path pair the key is scoped to.
     * @param   string                  $key                       The client's `Idempotency-Key`.
     * @param   string                  $digest                    Digest of this request, compared against
     *          the stored one and written back when a dead record is taken over.
     * @param   string                  $authorizationFingerprint  Credential and site fingerprint this
     *          request carries, compared against the stored one and re-stored on takeover.
     * @param   string                  $ownerToken                Random token this request claims the
     *          lease with.
     * @param   ServerRequestInterface  $request                   Request whose URI is quoted in any
     *          problem document produced here.
     *
     * @return  ?ResponseInterface  Null when this request now owns the reservation and must run the
     *          handler; otherwise the response to return instead of running it.
     *
     * @throws  RuntimeException  When the record cannot be read back after the collision, a column it
     *          needs is missing or malformed, or a stored result fails its integrity check.
     *
     * @since   2.0.0
     */
    private function replayOrAcquire(
        string $subject,
        string $operation,
        string $key,
        string $digest,
        string $authorizationFingerprint,
        string $ownerToken,
        ServerRequestInterface $request,
    ): ?ResponseInterface {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT id, request_digest, authorization_fingerprint, state, owner_token, locked_until, '
                . 'result_status, result_body, result_body_digest, result_headers, expires_at FROM %s '
                . 'WHERE subject = ? AND operation = ? AND idempotency_key = ?',
                $this->tables->quoted('idempotency'),
            ), [$subject, $operation, $key]);
            if ($row === false) {
                throw new RuntimeException('The idempotency record could not be loaded.');
            }

            /** @var array<string, mixed> $row */
            $now = $this->clock->now();
            $id = $this->requiredString($row, 'id');
            $expiresAt = new DateTimeImmutable($this->requiredString($row, 'expires_at'));
            if ($expiresAt <= $now) {
                if ($this->acquireExpired($id, $digest, $authorizationFingerprint, $ownerToken, $now)) {
                    return null;
                }
                continue;
            }

            if (!hash_equals($this->requiredString($row, 'request_digest'), $digest)) {
                return $this->problems->create(
                    422,
                    'Idempotency Key Reused',
                    'This Idempotency-Key was already used for a different request.',
                    'urn:kumwe:problem:idempotency-key-reused',
                    (string) $request->getUri(),
                );
            }

            if (
                !hash_equals(
                    $this->requiredString($row, 'authorization_fingerprint'),
                    $authorizationFingerprint,
                )
            ) {
                return $this->problems->create(
                    409,
                    'Authorization Context Changed',
                    'This Idempotency-Key belongs to a different credential or authorization state.',
                    'urn:kumwe:problem:idempotency-authorization-changed',
                    (string) $request->getUri(),
                );
            }

            $state = $this->requiredString($row, 'state');
            if ($state === 'completed') {
                return $this->replay($row);
            }
            if (
                $state === 'failed' && $this->acquireFailed(
                    $id,
                    $digest,
                    $authorizationFingerprint,
                    $ownerToken,
                    $now,
                )
            ) {
                return null;
            }

            $lockedUntil = $row['locked_until'] ?? null;
            if (
                $state === 'in_progress'
                && (!is_string($lockedUntil) || new DateTimeImmutable($lockedUntil) <= $now)
                && $this->acquireStale($id, $authorizationFingerprint, $ownerToken, $now)
            ) {
                return null;
            }

            return $this->problems->create(
                409,
                'Operation In Progress',
                'An operation with this Idempotency-Key is still in progress.',
                'urn:kumwe:problem:idempotency-in-progress',
                (string) $request->getUri(),
            );
        }

        return $this->problems->create(
            409,
            'Operation In Progress',
            'Ownership of this idempotent operation changed concurrently; retry the request.',
            'urn:kumwe:problem:idempotency-in-progress',
            (string) $request->getUri(),
        );
    }

    /**
     * Rebuild the stored response for a repeat of a key whose operation already completed.
     *
     * The body is checked against its stored digest before any of it is sent, so a row edited in the
     * database is refused rather than replayed as though the service had produced it. The rebuilt response
     * carries `Idempotency-Replayed: true` on top of the stored headers, which is how a client tells a
     * replay from a fresh run.
     *
     * @param   array<string, mixed>  $row  Stored record, keyed by column name as the driver returned it.
     *
     * @return  ResponseInterface  The stored status, headers and body, plus the replay marker.
     *
     * @throws  RuntimeException  When the stored body or its digest is missing, the two disagree, the
     *          stored headers are not a JSON object of non-empty names to strings, or the stored status is
     *          not a number.
     *
     * @since   2.0.0
     */
    private function replay(array $row): ResponseInterface
    {
        $body = $this->storedString($row, 'result_body');
        if (!hash_equals($this->requiredString($row, 'result_body_digest'), hash('sha256', $body))) {
            throw new RuntimeException('The stored idempotency response body failed its integrity check.');
        }
        $headers = $this->headers($row['result_headers'] ?? null);
        $headers['Idempotency-Replayed'] = 'true';
        $response = (new Response())->withStatus($this->integer($row, 'result_status'));
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $response->getBody()->write($body);
        return $response;
    }

    /**
     * Claim a record whose retention window has already closed.
     *
     * The expiry test is repeated inside the update rather than trusted from the read, so a record another
     * request revived in between is left alone and this one loses the race cleanly. The stored digest is
     * overwritten, because an expired record no longer speaks for any particular request: its key is free
     * for whatever content the new claimant carries.
     *
     * @param   string             $id                        Identifier of the record being taken over.
     * @param   string             $digest                    Digest of this request, replacing the expired
     *          record's own.
     * @param   string             $authorizationFingerprint  Credential and site fingerprint stored with
     *          the new reservation.
     * @param   string             $ownerToken                Token proving the new reservation is this
     *          request's.
     * @param   DateTimeImmutable  $now                       Instant expiry is judged against and the new
     *          lease is dated from.
     *
     * @return  bool  True when this request now owns the record; false when it was revived concurrently.
     *
     * @since   2.0.0
     */
    private function acquireExpired(
        string $id,
        string $digest,
        string $authorizationFingerprint,
        string $ownerToken,
        DateTimeImmutable $now,
    ): bool {
        return $this->reset(
            $id,
            $digest,
            $authorizationFingerprint,
            $ownerToken,
            $now,
            'expires_at <= ?',
            [$now],
            [Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Claim a record left behind by an attempt that ended in failure.
     *
     * Reached only once the stored digest and fingerprint have matched, so the digest written back is the
     * one already there and the retry is genuinely a retry of the same request. The `failed` state is
     * re-tested inside the update, so a record another retry has already picked up is not stolen from it.
     *
     * @param   string             $id                        Identifier of the record being retried.
     * @param   string             $digest                    Digest of this request, equal to the one the
     *          failed attempt stored.
     * @param   string             $authorizationFingerprint  Credential and site fingerprint stored with
     *          the new reservation.
     * @param   string             $ownerToken                Token proving the new reservation is this
     *          request's.
     * @param   DateTimeImmutable  $now                       Instant the new lease and retention window
     *          are dated from.
     *
     * @return  bool  True when this request now owns the record; false when another retry claimed it first.
     *
     * @since   2.0.0
     */
    private function acquireFailed(
        string $id,
        string $digest,
        string $authorizationFingerprint,
        string $ownerToken,
        DateTimeImmutable $now,
    ): bool {
        return $this->reset(
            $id,
            $digest,
            $authorizationFingerprint,
            $ownerToken,
            $now,
            "state = 'failed'",
            [],
            [],
        );
    }

    /**
     * Claim an in-progress record whose processing lease has run out.
     *
     * This is the recovery path for a request that died mid-flight: after `PROCESSING_LEASE_SECONDS` its
     * hold is no longer honoured and the next attempt may proceed. No digest is passed, because the caller
     * has already proved this request's digest equals the stored one, so there is nothing to rewrite. The
     * lease test is repeated inside the update, so only one of several waiting attempts wins.
     *
     * @param   string             $id                        Identifier of the record being taken over.
     * @param   string             $authorizationFingerprint  Credential and site fingerprint stored with
     *          the new reservation.
     * @param   string             $ownerToken                Token proving the new reservation is this
     *          request's.
     * @param   DateTimeImmutable  $now                       Instant the lapsed lease is judged against
     *          and the new one is dated from.
     *
     * @return  bool  True when this request now owns the record; false when the lease was still held or
     *          another attempt claimed it first.
     *
     * @since   2.0.0
     */
    private function acquireStale(
        string $id,
        string $authorizationFingerprint,
        string $ownerToken,
        DateTimeImmutable $now,
    ): bool {
        return $this->reset(
            $id,
            null,
            $authorizationFingerprint,
            $ownerToken,
            $now,
            "state = 'in_progress' AND (locked_until IS NULL OR locked_until <= ?)",
            [$now],
            [Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Re-arm a record as this request's reservation, but only while the caller's condition still holds.
     *
     * The single writer behind all three takeover paths. Its point is that the condition is carried into
     * the statement instead of being checked beforehand: the row is re-tested and claimed together, so the
     * affected-row count is a truthful answer to "did I win", and two claimants cannot both walk away
     * believing they own the record. Everything the previous attempt left is wiped — result columns
     * cleared, creation, lease and expiry re-dated from now — so the record is indistinguishable from a
     * reservation taken by a first attempt.
     *
     * @param   string             $id                        Identifier of the record to claim.
     * @param   ?string            $digest                    New request digest, or null to leave the
     *          stored one untouched.
     * @param   string             $authorizationFingerprint  Credential and site fingerprint stored with
     *          the new reservation.
     * @param   string             $ownerToken                Token written to both the owner and lease
     *          columns to mark this request as holder.
     * @param   DateTimeImmutable  $now                       Instant the new lease and retention window
     *          are measured from.
     * @param   string             $condition                 SQL predicate ANDed with the identifier
     *          match, naming the state that makes this takeover legal.
     * @param   list<mixed>        $conditionValues           Values bound to the condition's placeholders,
     *          in the order they appear.
     * @param   list<string>       $conditionTypes            DBAL types for those values, positionally.
     *
     * @return  bool  True when exactly one row was claimed, which is the caller's proof of ownership.
     *
     * @since   2.0.0
     */
    private function reset(
        string $id,
        ?string $digest,
        string $authorizationFingerprint,
        string $ownerToken,
        DateTimeImmutable $now,
        string $condition,
        array $conditionValues,
        array $conditionTypes,
    ): bool {
        $digestAssignment = $digest === null ? '' : 'request_digest = ?, ';
        $values = $digest === null ? [] : [$digest];
        $types = $digest === null ? [] : [Types::STRING];
        $values = array_merge($values, [
            $authorizationFingerprint,
            $ownerToken,
            $ownerToken,
            $now,
            $now->modify('+' . self::PROCESSING_LEASE_SECONDS . ' seconds'),
            $now->modify('+' . self::PROCESSING_LEASE_SECONDS . ' seconds'),
            $now->modify('+' . self::RETENTION_SECONDS . ' seconds'),
            $id,
        ], $conditionValues);
        $types = array_merge($types, [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
        ], $conditionTypes);
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET %sauthorization_fingerprint = ?, state = 'in_progress', owner_token = ?, "
            . 'lease_owner = ?, created_at = ?, locked_until = ?, lease_expires_at = ?, expires_at = ?, '
            . 'result_status = NULL, result_body = NULL, result_body_digest = NULL, '
            . 'result_headers = NULL, completed_at = NULL WHERE id = ? AND %s',
            $this->tables->quoted('idempotency'),
            $digestAssignment,
            $condition,
        ), $values, $types);
        return (string) $affected === '1';
    }

    /**
     * Settle the record as completed and store the response future repeats will be answered with.
     *
     * Runs inside the transaction that wraps the handler, so the mutation and the record marking its key
     * spent become durable together. The predicate re-states the whole claim — same owner token, still
     * `in_progress`, lease not yet lapsed — and `assertOwner()` turns a lost race into a thrown failure
     * rather than a silent no-op, which rolls the mutation back instead of leaving an effect no replay
     * describes. Only the four headers a replay must reproduce are kept; the rest are regenerated when the
     * stored response is rebuilt.
     *
     * @param   string             $subject     Principal the record is keyed against.
     * @param   string             $operation   Method and path pair the key is scoped to.
     * @param   string             $key         The client's `Idempotency-Key`.
     * @param   string             $ownerToken  Token this request stored when it claimed the reservation.
     * @param   ResponseInterface  $response    Response to store and replay; its body is read in full.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the reservation is no longer this request's to settle, because the
     *          lease lapsed or another attempt took the record over.
     *
     * @since   2.0.0
     */
    private function complete(
        string $subject,
        string $operation,
        string $key,
        string $ownerToken,
        ResponseInterface $response,
    ): void {
        $body = (string) $response->getBody();
        $headers = [];
        foreach (['Content-Type', 'Cache-Control', 'ETag', 'Location'] as $name) {
            if ($response->hasHeader($name)) {
                $headers[$name] = $response->getHeaderLine($name);
            }
        }
        $now = $this->clock->now();
        $affected = $this->database->executeStatement(sprintf(
            "UPDATE %s SET state = 'completed', owner_token = NULL, locked_until = NULL, "
            . 'lease_owner = NULL, lease_expires_at = NULL, result_status = ?, result_body = ?, '
            . 'result_body_digest = ?, result_headers = ?, completed_at = ? '
            . 'WHERE subject = ? AND operation = ? AND idempotency_key = ? AND state = '
            . "'in_progress' AND owner_token = ? AND locked_until > ?",
            $this->tables->quoted('idempotency'),
        ), [
            $response->getStatusCode(), $body, hash('sha256', $body), $headers, $now,
            $subject, $operation, $key, $ownerToken, $now,
        ], [
            Types::INTEGER, Types::TEXT, Types::STRING, Types::JSON, Types::DATETIME_IMMUTABLE,
            Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE,
        ]);
        $this->assertOwner($affected);
    }

    /**
     * Give the key back after an attempt that did not settle.
     *
     * The row is deleted rather than marked failed, so the next request presenting the key starts from
     * nothing: an operation whose transaction rolled back left no effect worth replaying. Owner token and
     * `in_progress` state are both in the predicate, so a record another attempt has since taken over, or
     * one that already completed, is untouched. Ownership is only asserted on the path that still returns
     * a response — while unwinding from a fault the delete is best-effort, because a lost lease must not
     * replace the exception the caller is about to see.
     *
     * @param   string  $subject      Principal the record is keyed against.
     * @param   string  $operation    Method and path pair the key is scoped to.
     * @param   string  $key          The client's `Idempotency-Key`.
     * @param   string  $ownerToken   Token this request stored when it claimed the reservation.
     * @param   bool    $assertOwner  Whether failing to delete the record should be raised: true after a
     *          5xx, false while a thrown fault is propagating.
     *
     * @return  void
     *
     * @throws  RuntimeException  When ownership is asserted and the record was no longer this request's
     *          to delete.
     *
     * @since   2.0.0
     */
    private function release(
        string $subject,
        string $operation,
        string $key,
        string $ownerToken,
        bool $assertOwner,
    ): void {
        $affected = $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE subject = ? AND operation = ? AND idempotency_key = ? '
            . "AND state = 'in_progress' AND owner_token = ?",
            $this->tables->quoted('idempotency'),
        ), [$subject, $operation, $key, $ownerToken]);
        if ($assertOwner) {
            $this->assertOwner($affected);
        }
    }

    /**
     * Fingerprint the parts of a request that must not change between attempts at one key.
     *
     * Method, path, query, content type, precondition and body are hashed together, so a client that
     * reuses a key for different content is caught rather than handed the earlier result. Everything else
     * is excluded deliberately: a retry through another proxy, or with a fresh trace header, is the same
     * request and must still match.
     *
     * @param   ServerRequestInterface  $request  Request to fingerprint; its body is read in full.
     *
     * @return  string  Hex SHA-256 digest, compared with `hash_equals()` against the stored one.
     *
     * @since   2.0.0
     */
    private function requestDigest(ServerRequestInterface $request): string
    {
        return hash('sha256', implode("\n", [
            strtoupper($request->getMethod()),
            $request->getUri()->getPath(),
            $request->getUri()->getQuery(),
            $request->getHeaderLine('Content-Type'),
            $request->getHeaderLine('If-Match'),
            (string) $request->getBody(),
        ]));
    }

    /**
     * Insist that a ledger write landed on exactly the one row this request owns.
     *
     * Drivers disagree on whether an affected-row count comes back as an int or a decimal string, so the
     * comparison is made as text. Anything but one row means the reservation moved on while the mutation
     * was running, and failing is the only safe answer: raised from `complete()` it unwinds the mutation
     * with the transaction, and raised from `release()` after a 5xx it replaces the handler's response,
     * because a record this request no longer owns was not this request's to clear.
     *
     * @param   int|string  $affected  Rows the completion or release statement reported changing.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the statement did not change exactly one row.
     *
     * @since   2.0.0
     */
    private function assertOwner(int|string $affected): void
    {
        if ((string) $affected !== '1') {
            throw new RuntimeException('The request no longer owns the active idempotency lease.');
        }
    }

    /**
     * Read a stored JSON column back as an object, whatever form the driver handed it over in.
     *
     * Drivers differ on JSON columns — some decode them, some return the text — so both are accepted and
     * only text is parsed. A JSON list is refused rather than tolerated, because the one column this backs
     * is the stored header map, and accepting a list would rebuild a replay with numeric header names.
     *
     * @param   mixed  $stored  Value of a JSON column as the driver returned it: already decoded, or text.
     *
     * @return  array<string, mixed>  The decoded object, keyed by member name.
     *
     * @throws  RuntimeException  When the text is not valid JSON, or the value is not a JSON object.
     *
     * @since   2.0.0
     */
    private function jsonObject(mixed $stored): array
    {
        try {
            $decoded = is_string($stored) ? json_decode($stored, true, 64, JSON_THROW_ON_ERROR) : $stored;
        } catch (JsonException $exception) {
            throw new RuntimeException('An idempotency result contains invalid JSON.', 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('An idempotency result must contain a JSON object.');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Read the stored header map back in a form that can be set on a response.
     *
     * Every entry is proved to be a non-empty name against a string value before any of them is applied,
     * so a corrupt row is refused outright instead of half-populating a replayed response.
     *
     * @param   mixed  $stored  Value of the `result_headers` column as the driver returned it.
     *
     * @return  array<non-empty-string, string>  Header names to their single stored line.
     *
     * @throws  RuntimeException  When the value is not a JSON object, or an entry has a blank name or a
     *          value that is not a string.
     *
     * @since   2.0.0
     */
    private function headers(mixed $stored): array
    {
        $headers = $this->jsonObject($stored);
        foreach ($headers as $name => $value) {
            if ($name === '' || !is_string($value)) {
                throw new RuntimeException('Stored idempotency response headers must contain strings.');
            }
        }
        /** @var array<non-empty-string, string> $headers */
        return $headers;
    }

    /**
     * Read a ledger column that has to carry text for the record to be usable.
     *
     * Blank counts as missing here, which is why identifiers, states, digests and timestamps go through
     * this one rather than `storedString()`: an empty digest compared with `hash_equals()` would quietly
     * weaken the check it exists to make.
     *
     * @param   array<string, mixed>  $row    Stored record, keyed by column name.
     * @param   string                $field  Column to read, named in the failure message.
     *
     * @return  string  The stored value, guaranteed non-empty.
     *
     * @throws  RuntimeException  When the column is absent, is not a string, or is empty.
     *
     * @since   2.0.0
     */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Idempotency field %s is invalid.', $field));
        }
        return $value;
    }

    /**
     * Read a ledger column that must be text but may legitimately be empty.
     *
     * This is the reading used for `result_body`, where a stored empty body is a real response — a 204,
     * for instance — and must be replayed as such rather than rejected the way `requiredString()` would.
     *
     * @param   array<string, mixed>  $row    Stored record, keyed by column name.
     * @param   string                $field  Column to read, named in the failure message.
     *
     * @return  string  The stored value, possibly the empty string.
     *
     * @throws  RuntimeException  When the column is absent or is not a string.
     *
     * @since   2.0.0
     */
    private function storedString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Idempotency field %s is invalid.', $field));
        }
        return $value;
    }

    /**
     * Read a stored numeric column back as an integer, whatever spelling the driver used.
     *
     * A `SMALLINT` arrives as an int from one driver and as a decimal string from another, so both are
     * accepted. Anything else is corrupt storage rather than a client mistake, and is refused instead of
     * being cast into a status code that would then be replayed as though it had been served.
     *
     * @param   array<string, mixed>  $row    Stored record, keyed by column name.
     * @param   string                $field  Column to read, named in the failure message.
     *
     * @return  int  The stored number, for `result_status` the HTTP status to replay.
     *
     * @throws  RuntimeException  When the column is neither an integer nor a string of decimal digits.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('Idempotency field %s is not an integer.', $field));
        }
        return (int) $value;
    }
}
