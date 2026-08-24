<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use Kumwe\App\Studio\Domain\Host\StudioIdempotencyRecord;

/**
 * Atomic durable ledger for retryable Studio host mutations.
 *
 * @since  2.0.0
 */
interface StudioIdempotencyRepository
{
    /**
     * Find one mutation claim by its complete opaque scope digest.
     *
     * @param   string  $scopeDigest  Actor/session/resource/operation/key scope digest.
     *
     * @return  StudioIdempotencyRecord|null  Existing claim or null.
     *
     * @since   2.0.0
     */
    public function find(string $scopeDigest): ?StudioIdempotencyRecord;

    /**
     * Claim a scope by unique insert inside the caller's transaction.
     *
     * @param   StudioIdempotencyRecord    $record    New pending claim.
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted live host session.
     * @param   StudioHostRequest          $request   Validated canonical request.
     *
     * @return  void
     *
     * @throws  StudioIdempotencyRace  When another transaction won the scope.
     *
     * @since   2.0.0
     */
    public function begin(
        StudioIdempotencyRecord $record,
        StudioHostSessionSnapshot $snapshot,
        StudioHostRequest $request,
    ): void;

    /**
     * Complete a pending claim with the canonical result bytes.
     *
     * @param   string  $scopeDigest  Existing claim scope.
     * @param   string  $resultBytes  Canonical completed-result bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function complete(string $scopeDigest, string $resultBytes): void;
}
