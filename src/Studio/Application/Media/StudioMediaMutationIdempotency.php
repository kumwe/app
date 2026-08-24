<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

use DateTimeImmutable;
use JsonException;
use Kumwe\App\Application\Idempotency\IdempotencyLedger;
use Kumwe\App\Application\Persistence\TransactionManager;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use Psr\Clock\ClockInterface;
use stdClass;

/**
 * Replays supplied Studio mutation keys through the App's durable generic idempotency ledger.
 *
 * Request and trace identifiers are intentionally excluded from the intent digest. The key is scoped to
 * operation, actor, resource context and generation, and a changed argument under the same live key is
 * refused. Failed attempts release their reservation so Studio can retry safely. Upload authorization
 * uses a secret-free stored projection and deterministic, digest-checked token restoration, so the same
 * grant replays without putting its live capability in the ledger.
 *
 * @since  2.0.0
 */
final readonly class StudioMediaMutationIdempotency
{
    /**
     * Bind Studio mutation replay to the existing durable application ledger.
     *
     * @param  IdempotencyLedger   $ledger        Concurrency-arbitrated replay store.
     * @param  TransactionManager  $transactions  Shared mutation and replay transaction boundary.
     * @param  ClockInterface      $clock         Lease and retention cutoff clock.
     *
     * @since  2.0.0
     */
    public function __construct(
        private IdempotencyLedger $ledger,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Run or replay one mutation when its envelope carries an idempotency key.
     *
     * @param   StudioHostRequest  $request    Validated canonical host envelope.
     * @param   string             $actorId    Trusted actor identity.
     * @param   callable(): mixed  $operation  Mutation producing a canonical JSON value.
     *
     * @return  mixed  Fresh or integrity-checked replayed canonical value.
     *
     * @throws  StudioMediaPortRejected  On key reuse, changed authority, in-flight work or corrupt replay.
     *
     * @since   2.0.0
     */
    public function run(StudioHostRequest $request, string $actorId, callable $operation): mixed
    {
        $result = $this->execute($request, $actorId, $operation, null, null);
        if ($result instanceof StudioMediaPortRejected) {
            throw $result;
        }

        return $result;
    }

    /**
     * Run upload authorization while storing no plaintext transfer capability in the replay ledger.
     *
     * @param   StudioHostRequest             $request    Validated canonical host envelope.
     * @param   string                        $actorId    Trusted actor identity.
     * @param   callable(): mixed             $operation  Fresh authorization operation.
     * @param   callable(stdClass): stdClass  $restore    Rehydrates a verified secret-free replay.
     *
     * @return  mixed  Fresh grant or the exact safely rehydrated replay.
     *
     * @throws  StudioMediaPortRejected  On key reuse, changed authority, in-flight work or corrupt replay.
     *
     * @since   2.0.0
     */
    public function runGrant(
        StudioHostRequest $request,
        string $actorId,
        callable $operation,
        callable $restore,
    ): mixed {
        $result = $this->execute(
            $request,
            $actorId,
            $operation,
            self::redactGrant(...),
            $restore,
        );
        if ($result instanceof StudioMediaPortRejected) {
            throw $result;
        }

        return $result;
    }

    /**
     * Reserve outside the mutation transaction, then execute and settle the owned claim atomically.
     *
     * A uniqueness collision must not poison the mutation transaction on stores such as PostgreSQL, so
     * the insert-or-replay arbitration deliberately happens before the transaction opens. Once this
     * request owns the claim, the mutation and `complete()` share one transaction. Any non-durable
     * refusal or fault therefore rolls both back before the reservation is released in its own write.
     *
     * @param   StudioHostRequest                 $request    Validated canonical host envelope.
     * @param   string                            $actorId    Trusted actor identity.
     * @param   callable(): mixed                 $operation  Mutation producing a canonical JSON value.
     * @param   (callable(mixed): mixed)|null     $store      Secret-free stored projection when needed.
     * @param   (callable(stdClass): mixed)|null  $restore    Rehydrates an integrity-checked replay.
     *
     * @return  mixed  Fresh or integrity-checked replayed canonical value.
     *
     * @since   2.0.0
     */
    private function execute(
        StudioHostRequest $request,
        string $actorId,
        callable $operation,
        ?callable $store,
        ?callable $restore,
    ): mixed {
        $key = $request->idempotencyKey;
        if ($key === null) {
            return $this->transactions->transactional(
                static function () use ($operation): mixed {
                    try {
                        return $operation();
                    } catch (StudioMediaPortRejected $failure) {
                        if (!$failure->commitsState) {
                            throw $failure;
                        }

                        return $failure;
                    }
                },
            );
        }
        $scope = 'studio-media:' . hash(
            'sha256',
            $request->operationId . "\0" . $request->resourceContextKey . "\0" . $request->sessionGeneration,
        );
        $ledgerKey = hash('sha256', $key);
        $intent = (object) [
            'arguments' => $request->arguments,
            'expectedRevision' => $request->expectedRevision,
            'locale' => $request->locale,
            'protocolVersion' => $request->protocolVersion,
        ];
        $digest = hash('sha256', CanonicalJson::stringify($intent));
        $authorization = hash('sha256', $actorId . "\0" . $scope);
        $owner = bin2hex(random_bytes(32));
        $row = null;
        if (!$this->ledger->reserve($actorId, $scope, $ledgerKey, $digest, $authorization, $owner)) {
            $row = $this->replayOrAcquire(
                $actorId,
                $scope,
                $ledgerKey,
                $digest,
                $authorization,
                $owner,
            );
        }
        if ($row !== null) {
            $body = $row['result_body'] ?? null;
            $bodyDigest = $row['result_body_digest'] ?? null;
            if (!is_string($body) || !is_string($bodyDigest) || !hash_equals(hash('sha256', $body), $bodyDigest)) {
                throw new StudioMediaPortRejected('unavailable', 'studio.media/idempotency-corrupt');
            }
            try {
                $decoded = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new StudioMediaPortRejected('unavailable', 'studio.media/idempotency-corrupt');
            }
            $status = self::status($row['result_status'] ?? null);
            if ($status !== 200) {
                if (
                    !$decoded instanceof stdClass
                    || !is_string($decoded->category ?? null)
                    || !is_string($decoded->code ?? null)
                ) {
                    throw new StudioMediaPortRejected('unavailable', 'studio.media/idempotency-corrupt');
                }

                return new StudioMediaPortRejected($decoded->category, $decoded->code);
            }
            if ($restore !== null) {
                if (!$decoded instanceof stdClass) {
                    throw new StudioMediaPortRejected('unavailable', 'studio.media/idempotency-corrupt');
                }

                return $restore($decoded);
            }

            return $decoded;
        }
        try {
            return $this->transactions->transactional(
                function () use (
                    $actorId,
                    $scope,
                    $ledgerKey,
                    $owner,
                    $operation,
                    $store,
                ): mixed {
                    try {
                        $value = $operation();
                        $stored = $store === null ? $value : $store($value);
                        $body = CanonicalJson::stringify($stored);
                        if (
                            !$this->ledger->complete(
                                $actorId,
                                $scope,
                                $ledgerKey,
                                $owner,
                                200,
                                $body,
                                [],
                            )
                        ) {
                            throw new StudioMediaPortRejected(
                                'conflict',
                                'studio.media/idempotency-in-flight',
                            );
                        }

                        return $value;
                    } catch (StudioMediaPortRejected $failure) {
                        if (!$failure->commitsState) {
                            throw $failure;
                        }
                        $body = CanonicalJson::stringify((object) [
                            'category' => $failure->category,
                            'code' => $failure->failureCode,
                        ]);
                        if (
                            !$this->ledger->complete(
                                $actorId,
                                $scope,
                                $ledgerKey,
                                $owner,
                                422,
                                $body,
                                [],
                            )
                        ) {
                            throw new StudioMediaPortRejected(
                                'conflict',
                                'studio.media/idempotency-in-flight',
                            );
                        }

                        return $failure;
                    }
                },
            );
        } catch (\Throwable $failure) {
            $this->ledger->release($actorId, $scope, $ledgerKey, $owner);
            throw $failure;
        }
    }

    /**
     * Remove the live transfer capability from an authorize-upload result before persistence.
     *
     * @param   mixed  $value  Fresh canonical grant.
     *
     * @return  stdClass  Detached grant with every non-secret member unchanged.
     *
     * @throws  StudioMediaPortRejected  When the operation did not return the required grant shape.
     *
     * @since   2.0.0
     */
    private static function redactGrant(mixed $value): stdClass
    {
        $headers = $value instanceof stdClass ? ($value->headers ?? null) : null;
        if (
            !$value instanceof stdClass
            || !$headers instanceof stdClass
            || !is_string($headers->{'X-Studio-Upload-Token'} ?? null)
        ) {
            throw new StudioMediaPortRejected('unavailable', 'studio.media/idempotency-corrupt');
        }
        $stored = clone $value;
        $stored->headers = clone $headers;
        unset($stored->headers->{'X-Studio-Upload-Token'});

        return $stored;
    }

    /**
     * Read a completed ledger status without accepting driver-specific malformed values.
     *
     * @param   mixed  $value  Stored integer or decimal string.
     *
     * @return  int  Verified stored status.
     *
     * @throws  StudioMediaPortRejected  When storage returned an invalid status.
     *
     * @since   2.0.0
     */
    private static function status(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new StudioMediaPortRejected('unavailable', 'studio.media/idempotency-corrupt');
        }

        return (int) $value;
    }

    /**
     * Replay a live completed claim or conditionally take over an abandoned one.
     *
     * Expiry is evaluated before fingerprints because a record past its retention horizon no longer owns
     * the key. A live record must match request and authority before its state is disclosed. Failed claims
     * and lapsed processing leases use the ledger's fenced takeover writes; a winner returns null and runs
     * the operation, while a completed row is returned for integrity-checked replay.
     *
     * @param   string  $subject        Trusted actor scope.
     * @param   string  $operation      Hashed Studio operation/resource scope.
     * @param   string  $key            Hashed supplied idempotency key.
     * @param   string  $digest         Canonical intent digest.
     * @param   string  $authorization  Authority fingerprint.
     * @param   string  $owner          Fresh takeover owner token.
     *
     * @return  array<string, mixed>|null  Completed row to replay, or null when this request owns the claim.
     *
     * @throws  StudioMediaPortRejected  For changed intent/authority, corrupt storage or live contention.
     *
     * @since   2.0.0
     */
    private function replayOrAcquire(
        string $subject,
        string $operation,
        string $key,
        string $digest,
        string $authorization,
        string $owner,
    ): ?array {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $row = $this->ledger->find($subject, $operation, $key);
            if (!is_array($row)) {
                throw new StudioMediaPortRejected('unavailable', 'studio.media/idempotency-corrupt');
            }
            $id = self::requiredString($row, 'id');
            if (self::instant($row, 'expires_at') <= $this->clock->now()) {
                if ($this->ledger->takeOverExpired($id, $digest, $authorization, $owner)) {
                    return null;
                }
                continue;
            }
            if (!hash_equals(self::requiredString($row, 'request_digest'), $digest)) {
                throw new StudioMediaPortRejected('validation-failed', 'studio.media/idempotency-reused');
            }
            if (!hash_equals(self::requiredString($row, 'authorization_fingerprint'), $authorization)) {
                throw new StudioMediaPortRejected(
                    'conflict',
                    'studio.media/idempotency-authority-changed',
                );
            }
            $state = self::requiredString($row, 'state');
            if ($state === 'completed') {
                return $row;
            }
            if (
                $state === 'failed' && $this->ledger->takeOverFailed(
                    $id,
                    $digest,
                    $authorization,
                    $owner,
                )
            ) {
                return null;
            }
            $lockedUntil = $row['locked_until'] ?? null;
            if (
                $state === 'in_progress'
                && (!is_string($lockedUntil) || self::parseInstant($lockedUntil) <= $this->clock->now())
                && $this->ledger->takeOverStale($id, $authorization, $owner)
            ) {
                return null;
            }
            if (!in_array($state, ['failed', 'in_progress'], true)) {
                throw new StudioMediaPortRejected('unavailable', 'studio.media/idempotency-corrupt');
            }

            throw new StudioMediaPortRejected('conflict', 'studio.media/idempotency-in-flight');
        }

        throw new StudioMediaPortRejected('conflict', 'studio.media/idempotency-in-flight');
    }

    /**
     * Read one required non-empty string from a ledger row.
     *
     * @param   array<string, mixed>  $row   Stored ledger projection.
     * @param   string                $name  Required column name.
     *
     * @return  string  Validated stored value.
     *
     * @throws  StudioMediaPortRejected  When the column is absent or invalid.
     *
     * @since   2.0.0
     */
    private static function requiredString(array $row, string $name): string
    {
        $value = $row[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new StudioMediaPortRejected('unavailable', 'studio.media/idempotency-corrupt');
        }

        return $value;
    }

    /**
     * Read one required ledger instant through the safe parser.
     *
     * @param   array<string, mixed>  $row   Stored ledger projection.
     * @param   string                $name  Required temporal column.
     *
     * @return  DateTimeImmutable  Parsed immutable instant.
     *
     * @since   2.0.0
     */
    private static function instant(array $row, string $name): DateTimeImmutable
    {
        return self::parseInstant(self::requiredString($row, $name));
    }

    /**
     * Parse a stored ledger instant without surfacing corrupt bytes in an error.
     *
     * @param   string  $value  Stored temporal representation.
     *
     * @return  DateTimeImmutable  Parsed immutable instant.
     *
     * @throws  StudioMediaPortRejected  When the instant is malformed.
     *
     * @since   2.0.0
     */
    private static function parseInstant(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new StudioMediaPortRejected('unavailable', 'studio.media/idempotency-corrupt');
        }
    }
}
