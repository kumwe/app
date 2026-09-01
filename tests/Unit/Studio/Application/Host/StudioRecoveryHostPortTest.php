<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use DateTimeImmutable;
use Kumwe\App\Studio\Application\Host\StudioPersistenceRace;
use Kumwe\App\Studio\Application\Host\StudioRecoveryHostPort;
use Kumwe\App\Studio\Application\Host\StudioRecoveryRepository;
use Kumwe\App\Tests\Support\StudioProducerRequest;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Error\HostRefusal;
use Kumwe\Producer\Wire\RequestContext;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use stdClass;

/**
 * Proves the recovery port stays bounded, scoped, canonical and rate-limited under every refusal branch.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioRecoveryHostPort::class)]
final class StudioRecoveryHostPortTest extends TestCase
{
    /**
     * Prove an unbound port never touches persistence, even for a well-formed load.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnboundPortRefusesDispatch(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires request authority');

        (new StudioRecoveryHostPort($this->repository(), $this->clock()))
            ->load(new stdClass(), $this->context('studio.operation/recovery.load'));
    }

    /**
     * Prove non-positive limits can never configure a recovery port.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNonPositiveLimitsAreRefusedAtConstruction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be positive');

        new StudioRecoveryHostPort($this->repository(), $this->clock(), maximumBytes: 0);
    }

    /**
     * Prove a load request smuggling mutation context is refused before persistence is read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALoadCarryingMutationContextIsRefused(): void
    {
        $request = StudioProducerRequest::authorized('studio.operation/recovery.load', new stdClass());
        $port = (new StudioRecoveryHostPort($this->repository('{"draft":"kept"}'), $this->clock()))
            ->forRequest($request->authority);

        foreach (
            [
                $this->context('studio.operation/recovery.load', expectedRevision: 'entry-r1'),
                $this->context('studio.operation/recovery.load', idempotencyKey: 'idempotency/recovery-load'),
            ] as $context
        ) {
            try {
                $port->load(new stdClass(), $context);
                self::fail('A load carrying mutation context must be refused.');
            } catch (HostRefusal $refused) {
                self::assertSame('invalid-request', $refused->error()->category());
            }
        }
    }

    /**
     * Prove stored bytes that no longer decode as JSON are refused instead of served.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCorruptStoredRecoveryJsonIsRefusedAtLoad(): void
    {
        $request = StudioProducerRequest::authorized('studio.operation/recovery.load', new stdClass());
        $port = (new StudioRecoveryHostPort($this->repository('{"broken'), $this->clock()))
            ->forRequest($request->authority);

        try {
            $port->load($request->arguments(), $request->context());
            self::fail('Corrupt stored recovery JSON must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('internal', $refused->error()->category());
        }
    }

    /**
     * Prove decodable bytes that are non-canonical or not an object are refused as corrupt.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNonCanonicalStoredRecoveryBytesAreRefusedAtLoad(): void
    {
        foreach (['{"b":1,"a":2}', '"just-text"'] as $stored) {
            $request = StudioProducerRequest::authorized('studio.operation/recovery.load', new stdClass());
            $port = (new StudioRecoveryHostPort($this->repository($stored), $this->clock()))
                ->forRequest($request->authority);

            try {
                $port->load($request->arguments(), $request->context());
                self::fail('Non-canonical stored recovery bytes must be refused.');
            } catch (HostRefusal $refused) {
                self::assertSame('internal', $refused->error()->category());
            }
        }
    }

    /**
     * Prove a store request carrying a concurrency coordinate is refused before validation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStoreCarryingExpectedRevisionIsRefused(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'bounded']],
            idempotencyKey: 'idempotency/recovery-store-context',
        );
        $repository = $this->repository();
        $port = (new StudioRecoveryHostPort($repository, $this->clock()))->forRequest($request->authority);

        try {
            $port->store(
                $request->arguments(),
                $this->context('studio.operation/recovery.store', expectedRevision: 'entry-r1'),
            );
            self::fail('A store carrying an expected revision must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('invalid-request', $refused->error()->category());
        }

        self::assertSame([], $repository->saved);
    }

    /**
     * Prove store arguments outside the exact published wrapper shape are refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedStoreArgumentsAreRefused(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'bounded']],
            idempotencyKey: 'idempotency/recovery-store-arguments',
        );
        $repository = $this->repository();
        $port = (new StudioRecoveryHostPort($repository, $this->clock()))->forRequest($request->authority);

        foreach (
            [
                'not-an-object-wrapper',
                (object) ['envelope' => new stdClass(), 'extra' => true],
                new stdClass(),
            ] as $arguments
        ) {
            try {
                $port->store($arguments, $request->context());
                self::fail('Store arguments outside the exact wrapper must be refused.');
            } catch (HostRefusal $refused) {
                self::assertSame('invalid-request', $refused->error()->category());
            }
        }

        self::assertSame([], $repository->saved);
    }

    /**
     * Prove an envelope the stored-document policy rejects never reaches persistence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnsafeEnvelopeIsRefusedBeforePersistence(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'safe']],
            idempotencyKey: 'idempotency/recovery-store-unsafe',
        );
        $repository = $this->repository();
        $port = (new StudioRecoveryHostPort($repository, $this->clock()))->forRequest($request->authority);

        try {
            $port->store((object) ['envelope' => (object) ['script' => 'nope']], $request->context());
            self::fail('An unsafe recovery envelope must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('validation-failed', $refused->error()->category());
        }

        self::assertSame([], $repository->saved);
    }

    /**
     * Prove a bounded safe envelope persists as exact canonical bytes at the trusted server instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStoreWithinLimitsPersistsCanonicalBytesAtTheServerInstant(): void
    {
        $envelope = (object) ['b' => 2, 'a' => 1];
        $request = StudioProducerRequest::authorized(
            'studio.operation/recovery.store',
            (object) ['envelope' => $envelope],
            idempotencyKey: 'idempotency/recovery-store-happy',
        );
        $repository = $this->repository();
        $port = (new StudioRecoveryHostPort($repository, $this->clock()))->forRequest($request->authority);

        $result = $port->store($request->arguments(), $request->context());

        self::assertNull($result->value);
        self::assertCount(1, $repository->saved);
        [$actorId, $sessionBinding, $contextKey, $bytes, $updatedAt] = $repository->saved[0];
        self::assertSame($request->snapshot->session->actorId, $actorId);
        self::assertSame($request->snapshot->session->sessionBinding, $sessionBinding);
        self::assertSame($request->context()->resourceContextKey, $contextKey);
        self::assertSame(CanonicalJson::stringify($envelope), $bytes);
        self::assertSame(1_788_004_800_500, $updatedAt);
    }

    /**
     * Prove a racing rate-window claim is refused as a retryable outage, not persisted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConcurrentRateWindowClaimIsRefusedAsRetryable(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'raced']],
            idempotencyKey: 'idempotency/recovery-store-rate-race',
        );
        $repository = $this->repository(raceOnConsume: true);
        $port = (new StudioRecoveryHostPort($repository, $this->clock()))->forRequest($request->authority);

        try {
            $port->store($request->arguments(), $request->context());
            self::fail('A racing rate-limit claim must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('unavailable', $refused->error()->category());
        }

        self::assertSame([], $repository->saved);
    }

    /**
     * Prove an exhausted fixed write window refuses with the store's remaining delay.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExhaustedWriteWindowIsRefusedAsRateLimited(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'throttled']],
            idempotencyKey: 'idempotency/recovery-store-throttled',
        );
        $repository = $this->repository(retryAfter: 1500);
        $port = (new StudioRecoveryHostPort($repository, $this->clock()))->forRequest($request->authority);

        try {
            $port->store($request->arguments(), $request->context());
            self::fail('An exhausted recovery write window must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('rate-limited', $refused->error()->category());
        }

        self::assertSame([], $repository->saved);
    }

    /**
     * Prove a racing envelope save is refused as a retryable outage after the window was consumed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConcurrentEnvelopeSaveIsRefusedAsRetryable(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/recovery.store',
            (object) ['envelope' => (object) ['draft' => 'save-raced']],
            idempotencyKey: 'idempotency/recovery-store-save-race',
        );
        $repository = $this->repository(raceOnSave: true);
        $port = (new StudioRecoveryHostPort($repository, $this->clock()))->forRequest($request->authority);

        try {
            $port->store($request->arguments(), $request->context());
            self::fail('A racing envelope save must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('unavailable', $refused->error()->category());
        }

        self::assertSame([], $repository->saved);
    }

    /**
     * Prove a discard request carrying a concurrency coordinate is refused before persistence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADiscardCarryingExpectedRevisionIsRefused(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/recovery.discard',
            new stdClass(),
            idempotencyKey: 'idempotency/recovery-discard-context',
        );
        $repository = $this->repository();
        $port = (new StudioRecoveryHostPort($repository, $this->clock()))->forRequest($request->authority);

        try {
            $port->discard(
                $request->arguments(),
                $this->context('studio.operation/recovery.discard', expectedRevision: 'entry-r1'),
            );
            self::fail('A discard carrying an expected revision must be refused.');
        } catch (HostRefusal $refused) {
            self::assertSame('invalid-request', $refused->error()->category());
        }

        self::assertSame(0, $repository->discarded);
    }

    /**
     * Build one observable scripted recovery repository double.
     *
     * @param   string|null  $envelope       Envelope bytes every load returns, or null for none.
     * @param   int|null     $retryAfter     Remaining window delay the rate limiter reports, or null.
     * @param   bool         $raceOnConsume  Whether consuming the rate window loses a persistence race.
     * @param   bool         $raceOnSave     Whether saving the envelope loses a persistence race.
     *
     * @return  StudioRecoveryRepository  In-memory repository observing saves and discards.
     *
     * @since   2.0.0
     */
    private function repository(
        ?string $envelope = null,
        ?int $retryAfter = null,
        bool $raceOnConsume = false,
        bool $raceOnSave = false,
    ): StudioRecoveryRepository {
        return new class ($envelope, $retryAfter, $raceOnConsume, $raceOnSave) implements StudioRecoveryRepository {
            /**
             * Every accepted save, as ordered argument tuples.
             *
             * @var    list<array{string, string, string, string, int}>
             * @since  2.0.0
             */
            public array $saved = [];

            /**
             * Number of discards that reached persistence.
             *
             * @var    int
             * @since  2.0.0
             */
            public int $discarded = 0;

            /**
             * Retain the scripted behaviour for this repository double.
             *
             * @param   string|null  $envelope       Envelope bytes every load returns, or null.
             * @param   int|null     $retryAfter     Remaining delay the rate limiter reports, or null.
             * @param   bool         $raceOnConsume  Whether the rate window loses a persistence race.
             * @param   bool         $raceOnSave     Whether the envelope save loses a persistence race.
             *
             * @since   2.0.0
             */
            public function __construct(
                private ?string $envelope,
                private ?int $retryAfter,
                private bool $raceOnConsume,
                private bool $raceOnSave,
            ) {
            }

            /**
             * Serve the scripted envelope bytes regardless of scope.
             *
             * @param   string  $actorId             Trusted actor identifier.
             * @param   string  $sessionBinding      Trusted browser-session binding.
             * @param   string  $resourceContextKey  Opaque resource context key.
             *
             * @return  string|null  The scripted canonical envelope bytes, or null.
             *
             * @since   2.0.0
             */
            public function loadEnvelope(
                string $actorId,
                string $sessionBinding,
                string $resourceContextKey,
            ): ?string {
                unset($actorId, $sessionBinding, $resourceContextKey);

                return $this->envelope;
            }

            /**
             * Record one accepted save, or lose the scripted persistence race.
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
                if ($this->raceOnSave) {
                    throw new StudioPersistenceRace('Scripted concurrent envelope save.');
                }
                $this->saved[] = [
                    $actorId,
                    $sessionBinding,
                    $resourceContextKey,
                    $canonicalEnvelope,
                    $updatedAtMilliseconds,
                ];
            }

            /**
             * Count one discard that reached persistence.
             *
             * @param   string  $actorId             Trusted actor identifier.
             * @param   string  $sessionBinding      Trusted browser-session binding.
             * @param   string  $resourceContextKey  Opaque resource context key.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function discardEnvelope(
                string $actorId,
                string $sessionBinding,
                string $resourceContextKey,
            ): void {
                unset($actorId, $sessionBinding, $resourceContextKey);
                $this->discarded++;
            }

            /**
             * Consume one scripted rate-window unit, delay, or lose the scripted race.
             *
             * @param   string  $scopeDigest         Complete recovery-write scope digest.
             * @param   int     $nowMilliseconds     Server instant in epoch milliseconds.
             * @param   int     $windowMilliseconds  Fixed window duration.
             * @param   int     $maximumRequests     Maximum accepted writes per window.
             *
             * @return  int|null  The scripted remaining delay, or null to accept the write.
             *
             * @since   2.0.0
             */
            public function consumeRateLimit(
                string $scopeDigest,
                int $nowMilliseconds,
                int $windowMilliseconds,
                int $maximumRequests,
            ): ?int {
                unset($scopeDigest, $nowMilliseconds, $windowMilliseconds, $maximumRequests);
                if ($this->raceOnConsume) {
                    throw new StudioPersistenceRace('Scripted concurrent rate-window claim.');
                }

                return $this->retryAfter;
            }
        };
    }

    /**
     * Build the fixed deterministic test clock with a non-zero millisecond component.
     *
     * @return  ClockInterface  Clock frozen at 2026-08-29T12:00:00.500Z.
     *
     * @since   2.0.0
     */
    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            /**
             * Return the fixed deterministic test instant.
             *
             * @return  DateTimeImmutable  Constant timestamp with a 500-millisecond fraction.
             *
             * @since   2.0.0
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-29T12:00:00.500+00:00');
            }
        };
    }

    /**
     * Build one already-validated request context carrying optional mutation coordinates.
     *
     * @param   string       $operationId       Closed Producer operation capability.
     * @param   string|null  $expectedRevision  Optional concurrency coordinate.
     * @param   string|null  $idempotencyKey    Optional mutation replay coordinate.
     *
     * @return  RequestContext  Direct request context for exercising port guards.
     *
     * @since   2.0.0
     */
    private function context(
        string $operationId,
        ?string $expectedRevision = null,
        ?string $idempotencyKey = null,
    ): RequestContext {
        return new RequestContext(
            $operationId,
            '1.0',
            'requests/recovery-unit-test',
            'contexts/producer-port-test',
            'generation-1',
            $expectedRevision,
            $idempotencyKey,
        );
    }
}
