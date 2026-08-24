<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use JsonException;
use Kumwe\App\Studio\Domain\Artifact\StudioStoredDocumentPolicy;
use Kumwe\App\Studio\Domain\Artifact\UnsafeStudioStoredDocument;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use Psr\Clock\ClockInterface;
use RuntimeException;
use stdClass;

/**
 * Scoped, bounded and idempotent implementation of Studio's recovery port.
 *
 * @since  2.0.0
 */
final readonly class StudioRecoveryHostPort
{
    /**
     * Bind scoped recovery persistence to shared idempotency, time and bounded limits.
     *
     * @param  StudioRecoveryRepository  $recovery            Recovery and rate-limit persistence.
     * @param  StudioMutationExecutor    $mutations           Atomic idempotency executor.
     * @param  ClockInterface            $clock               Trusted server clock.
     * @param  int                       $maximumBytes        Maximum canonical envelope bytes.
     * @param  int                       $maximumWrites       Maximum writes per fixed window.
     * @param  int                       $windowMilliseconds  Fixed-window duration.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioRecoveryRepository $recovery,
        private StudioMutationExecutor $mutations,
        private ClockInterface $clock,
        private int $maximumBytes = 262144,
        private int $maximumWrites = 60,
        private int $windowMilliseconds = 60000,
    ) {
        if ($maximumBytes < 1 || $maximumWrites < 1 || $windowMilliseconds < 1) {
            throw new RuntimeException('Studio recovery limits must be positive.');
        }
    }

    /**
     * Dispatch one recovery operation after the common host session fence succeeds.
     *
     * @param   string                     $operation  Route operation segment.
     * @param   StudioHostRequest          $request    Validated canonical request.
     * @param   StudioHostSessionSnapshot  $snapshot   Trusted live host session.
     *
     * @return  StudioHostResult  Canonical recovery result.
     *
     * @since   2.0.0
     */
    public function dispatch(
        string $operation,
        StudioHostRequest $request,
        StudioHostSessionSnapshot $snapshot,
    ): StudioHostResult {
        if ($request->expectedRevision !== null) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-context');
        }

        return match ($operation) {
            'discard' => $this->discard($request, $snapshot),
            'load' => $this->load($request, $snapshot),
            'store' => $this->store($request, $snapshot),
            default => throw new StudioHostOperationRefused('incompatible', 'studio.host/operation-unavailable'),
        };
    }

    /**
     * Load the envelope from the exact trusted actor, session and resource scope.
     *
     * @param   StudioHostRequest          $request   Validated load request.
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live host session.
     *
     * @return  StudioHostResult  Stored envelope or null.
     *
     * @since   2.0.0
     */
    private function load(StudioHostRequest $request, StudioHostSessionSnapshot $snapshot): StudioHostResult
    {
        $this->requireEmptyArguments($request);
        if ($request->idempotencyKey !== null) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-context');
        }
        $bytes = $this->recovery->loadEnvelope(
            $snapshot->session->actorId,
            $snapshot->session->sessionBinding,
            $request->resourceContextKey,
        );
        if ($bytes === null) {
            return new StudioHostResult(null);
        }
        try {
            $envelope = json_decode($bytes, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new StudioHostOperationRefused('internal', 'studio.recovery/corrupt');
        }
        if (!$envelope instanceof stdClass || !hash_equals($bytes, CanonicalJson::stringify($envelope))) {
            throw new StudioHostOperationRefused('internal', 'studio.recovery/corrupt');
        }

        return new StudioHostResult($envelope);
    }

    /**
     * Canonicalize and atomically store one bounded recovery envelope.
     *
     * @param   StudioHostRequest          $request   Validated store request.
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live host session.
     *
     * @return  StudioHostResult  Empty canonical success result.
     *
     * @since   2.0.0
     */
    private function store(StudioHostRequest $request, StudioHostSessionSnapshot $snapshot): StudioHostResult
    {
        $arguments = $this->exactArguments($request, ['envelope']);
        if (!$arguments->envelope instanceof stdClass) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        try {
            StudioStoredDocumentPolicy::assertSafe($arguments->envelope);
        } catch (UnsafeStudioStoredDocument $refused) {
            throw new StudioHostOperationRefused(
                'validation-failed',
                'studio.artifact/' . $refused->rejection->value,
            );
        }
        $bytes = CanonicalJson::stringify($arguments->envelope);
        if (strlen($bytes) > $this->maximumBytes) {
            throw new StudioHostOperationRefused('limit-exceeded', 'studio.recovery/size-limit');
        }

        return $this->mutations->execute(
            $snapshot,
            $request,
            $arguments->envelope,
            function () use ($snapshot, $request, $bytes): StudioHostResult {
                $now = $this->nowMilliseconds();
                $rateScope = hash('sha256', CanonicalJson::stringify((object) [
                    'actorId' => $snapshot->session->actorId,
                    'operationId' => $request->operationId,
                    'resourceContextKey' => $request->resourceContextKey,
                    'sessionBinding' => $snapshot->session->sessionBinding,
                ]));
                $retryAfter = $this->recovery->consumeRateLimit(
                    $rateScope,
                    $now,
                    $this->windowMilliseconds,
                    $this->maximumWrites,
                );
                if ($retryAfter !== null) {
                    throw new StudioHostOperationRefused(
                        'rate-limited',
                        'studio.recovery/rate-limited',
                        null,
                        true,
                        $retryAfter,
                    );
                }
                $this->recovery->saveEnvelope(
                    $snapshot->session->actorId,
                    $snapshot->session->sessionBinding,
                    $request->resourceContextKey,
                    $bytes,
                    $now,
                );

                return new StudioHostResult(null);
            },
        );
    }

    /**
     * Atomically discard the envelope from its exact trusted scope.
     *
     * @param   StudioHostRequest          $request   Validated discard request.
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live host session.
     *
     * @return  StudioHostResult  Empty canonical success result.
     *
     * @since   2.0.0
     */
    private function discard(StudioHostRequest $request, StudioHostSessionSnapshot $snapshot): StudioHostResult
    {
        $this->requireEmptyArguments($request);

        return $this->mutations->execute(
            $snapshot,
            $request,
            null,
            function () use ($snapshot, $request): StudioHostResult {
                $this->recovery->discardEnvelope(
                    $snapshot->session->actorId,
                    $snapshot->session->sessionBinding,
                    $request->resourceContextKey,
                );

                return new StudioHostResult(null);
            },
        );
    }

    /**
     * Require the actual HTTP adapter's empty-object argument wrapper.
     *
     * @param   StudioHostRequest  $request  Validated canonical request.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function requireEmptyArguments(StudioHostRequest $request): void
    {
        $this->exactArguments($request, []);
    }

    /**
     * Require the actual published HTTP adapter's exact wrapper object.
     *
     * @param   StudioHostRequest  $request  Validated canonical request.
     * @param   list<string>       $members  Exact member set.
     *
     * @return  stdClass  Exact argument wrapper.
     *
     * @since   2.0.0
     */
    private function exactArguments(StudioHostRequest $request, array $members): stdClass
    {
        if (!$request->arguments instanceof stdClass) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $actual = array_keys(get_object_vars($request->arguments));
        sort($actual, SORT_STRING);
        sort($members, SORT_STRING);
        if ($actual !== $members) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }

        return $request->arguments;
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
