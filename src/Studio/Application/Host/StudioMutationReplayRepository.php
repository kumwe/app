<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Studio\Domain\Host\StudioMutationReplayRecord;
use Kumwe\Producer\Wire\RequestContext;

/**
 * Atomic durable store for optional keyed Producer mutation replay.
 *
 * @since  2.0.0
 */
interface StudioMutationReplayRepository
{
    /**
     * Find one claim by the App's complete trusted replay scope digest.
     *
     * @param   string  $scopeDigest  App-namespaced lowercase SHA-256 scope digest.
     *
     * @return  StudioMutationReplayRecord|null  Existing claim or null.
     *
     * @since  2.0.0
     */
    public function findReplay(string $scopeDigest): ?StudioMutationReplayRecord;

    /**
     * Claim one scope by unique insert inside the caller's transaction.
     *
     * @param   StudioMutationReplayRecord  $record    New pending claim.
     * @param   StudioHostSessionSnapshot   $snapshot  Trusted live App host session.
     * @param   RequestContext               $request   Validated Producer request context.
     *
     * @return  void
     *
     * @throws  StudioMutationReplayRace  When another transaction won the scope.
     *
     * @since  2.0.0
     */
    public function beginReplay(
        StudioMutationReplayRecord $record,
        StudioHostSessionSnapshot $snapshot,
        RequestContext $request,
    ): void;

    /**
     * Complete one pending claim with a versioned authenticated logical outcome.
     *
     * @param   string  $scopeDigest      Existing App-namespaced scope digest.
     * @param   string  $protectedOutcome  Authenticated completed outcome envelope.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function completeReplay(string $scopeDigest, string $protectedOutcome): void;
}
