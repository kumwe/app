<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use JsonException;
use Kumwe\App\Studio\Domain\Artifact\StudioStoredDocumentPolicy;
use Kumwe\App\Studio\Domain\Artifact\UnsafeStudioStoredDocument;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\Port\RecoveryPortInterface;
use Kumwe\Producer\Wire\RequestContext;
use Psr\Clock\ClockInterface;
use RuntimeException;
use stdClass;

/**
 * Scoped, bounded and idempotent implementation of Studio's recovery port.
 *
 * @since  2.0.0
 */
final readonly class StudioRecoveryHostPort implements RecoveryPortInterface
{
    /**
     * Bind scoped recovery persistence to shared idempotency, time and bounded limits.
     *
     * @param  StudioRecoveryRepository  $recovery            Recovery and rate-limit persistence.
     * @param  ClockInterface            $clock               Trusted server clock.
     * @param  int                       $maximumBytes        Maximum canonical envelope bytes.
     * @param  int                       $maximumWrites       Maximum writes per fixed window.
     * @param  int                       $windowMilliseconds  Fixed-window duration.
     * @param  StudioProducerRequestAuthority|null $authority Authorized Producer request scope, when bound.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioRecoveryRepository $recovery,
        private ClockInterface $clock,
        private int $maximumBytes = 262144,
        private int $maximumWrites = 60,
        private int $windowMilliseconds = 60000,
        private ?StudioProducerRequestAuthority $authority = null,
    ) {
        if ($maximumBytes < 1 || $maximumWrites < 1 || $windowMilliseconds < 1) {
            throw new RuntimeException('Studio recovery limits must be positive.');
        }
    }

    /**
     * Bind this App-owned port implementation to one successfully authorized Producer request.
     *
     * @param   StudioProducerRequestAuthority  $authority  Trusted evidence for one exact dispatch.
     *
     * @return  self  Request-scoped recovery port.
     *
     * @since   2.0.0
     */
    public function forRequest(StudioProducerRequestAuthority $authority): self
    {
        return new self(
            $this->recovery,
            $this->clock,
            $this->maximumBytes,
            $this->maximumWrites,
            $this->windowMilliseconds,
            $authority,
        );
    }

    /**
     * Load the envelope from the exact trusted actor, session and resource scope.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Stored envelope or null.
     *
     * @since   2.0.0
     */
    public function load(mixed $arguments, RequestContext $context): HostResult
    {
        $snapshot = $this->requestAuthority()->snapshot();
        $this->requireEmptyArguments($arguments);
        if ($context->expectedRevision !== null || $context->idempotencyKey !== null) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-context');
        }
        $bytes = $this->recovery->loadEnvelope(
            $snapshot->session->actorId,
            $snapshot->session->sessionBinding,
            $context->resourceContextKey,
        );
        if ($bytes === null) {
            return new HostResult(null);
        }
        try {
            $envelope = json_decode($bytes, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            StudioProducerError::refuse('internal', 'studio.recovery/corrupt');
        }
        if (!$envelope instanceof stdClass || !hash_equals($bytes, CanonicalJson::stringify($envelope))) {
            StudioProducerError::refuse('internal', 'studio.recovery/corrupt');
        }

        return new HostResult($envelope);
    }

    /**
     * Canonicalize and atomically store one bounded recovery envelope.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Empty canonical success result.
     *
     * @since   2.0.0
     */
    public function store(mixed $arguments, RequestContext $context): HostResult
    {
        $snapshot = $this->requestAuthority()->snapshot();
        if ($context->expectedRevision !== null) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-context');
        }
        $document = $this->exactArguments($arguments, ['envelope']);
        if (!$document->envelope instanceof stdClass) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        try {
            StudioStoredDocumentPolicy::assertSafe($document->envelope);
        } catch (UnsafeStudioStoredDocument $refused) {
            StudioProducerError::refuse(
                'validation-failed',
                'studio.artifact/' . $refused->rejection->value,
            );
        }
        $bytes = CanonicalJson::stringify($document->envelope);
        if (strlen($bytes) > $this->maximumBytes) {
            StudioProducerError::refuse('limit-exceeded', 'studio.recovery/size-limit');
        }

        $now = $this->nowMilliseconds();
        $rateScope = hash('sha256', CanonicalJson::stringify((object) [
            'actorId' => $snapshot->session->actorId,
            'operationId' => $context->operationId,
            'resourceContextKey' => $context->resourceContextKey,
            'sessionBinding' => $snapshot->session->sessionBinding,
        ]));
        try {
            $retryAfter = $this->recovery->consumeRateLimit(
                $rateScope,
                $now,
                $this->windowMilliseconds,
                $this->maximumWrites,
            );
        } catch (StudioPersistenceRace) {
            StudioProducerError::refuse(
                'unavailable',
                'studio.host/concurrent-mutation',
                retryable: true,
            );
        }
        if ($retryAfter !== null) {
            StudioProducerError::refuse(
                'rate-limited',
                'studio.recovery/rate-limited',
                retryable: true,
                retryAfterMilliseconds: $retryAfter,
            );
        }
        try {
            $this->recovery->saveEnvelope(
                $snapshot->session->actorId,
                $snapshot->session->sessionBinding,
                $context->resourceContextKey,
                $bytes,
                $now,
            );
        } catch (StudioPersistenceRace) {
            StudioProducerError::refuse(
                'unavailable',
                'studio.host/concurrent-mutation',
                retryable: true,
            );
        }

        return new HostResult(null);
    }

    /**
     * Atomically discard the envelope from its exact trusted scope.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Empty canonical success result.
     *
     * @since   2.0.0
     */
    public function discard(mixed $arguments, RequestContext $context): HostResult
    {
        $snapshot = $this->requestAuthority()->snapshot();
        $this->requireEmptyArguments($arguments);
        if ($context->expectedRevision !== null) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-context');
        }
        $this->recovery->discardEnvelope(
            $snapshot->session->actorId,
            $snapshot->session->sessionBinding,
            $context->resourceContextKey,
        );

        return new HostResult(null);
    }

    /**
     * Require the published operation's empty-object argument wrapper.
     *
     * @param   mixed  $arguments  Validated Producer operation arguments.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requireEmptyArguments(mixed $arguments): void
    {
        $this->exactArguments($arguments, []);
    }

    /**
     * Require the published operation's exact wrapper object.
     *
     * @param   mixed         $arguments  Validated Producer operation arguments.
     * @param   list<string>       $members  Exact member set.
     *
     * @return  stdClass  Exact argument wrapper.
     *
     * @since   2.0.0
     */
    private function exactArguments(mixed $arguments, array $members): stdClass
    {
        if (!$arguments instanceof stdClass) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $actual = array_keys(get_object_vars($arguments));
        sort($actual, SORT_STRING);
        sort($members, SORT_STRING);
        if ($actual !== $members) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }

        return $arguments;
    }

    /**
     * Require the per-request authority installed by the Producer host factory.
     *
     * @return  StudioProducerRequestAuthority  Trusted evidence for this dispatch.
     *
     * @since   2.0.0
     */
    private function requestAuthority(): StudioProducerRequestAuthority
    {
        return $this->authority ?? throw new \LogicException('A Studio recovery port requires request authority.');
    }

    /**
     * Return the trusted server clock as epoch milliseconds.
     *
     * @return  int  Current epoch milliseconds.
     *
     * @since   2.0.0
     */
    private function nowMilliseconds(): int
    {
        $now = $this->clock->now();

        return $now->getTimestamp() * 1000 + (int) $now->format('v');
    }
}
