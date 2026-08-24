<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use DateTimeImmutable;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewGrant;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderedDocument;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;

/**
 * Portable pending-render, cancellation and single-use document-grant ledger.
 *
 * @since  2.0.0
 */
interface StudioPreviewGrantRepository
{
    /**
     * Claim one unique render attempt and supersede older attempts in this exact resource context.
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
    ): StudioPreviewRenderAdmission;

    /**
     * Attach a rendered document only while this attempt is still pending.
     *
     * @param   string                         $resourceContextKey  Opaque trusted host context.
     * @param   StudioPreviewRenderRequest     $request             Exact render attempt identity.
     * @param   StudioPreviewRenderedDocument  $document            Canonical rendered page and markers.
     *
     * @return  bool  False after cancellation or supersession, so a late result cannot escape.
     *
     * @since   2.0.0
     */
    public function complete(
        string $resourceContextKey,
        StudioPreviewRenderRequest $request,
        StudioPreviewRenderedDocument $document,
    ): bool;

    /**
     * Record a sequence-aware tombstone and cancel only older renders for this digest and context.
     *
     * @param   string  $resourceContextKey  Opaque trusted host context.
     * @param   string  $draftDigest         Exact canonical draft digest.
     * @param   int     $portSequence        Accepted cancellation transport sequence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function cancel(string $resourceContextKey, string $draftDigest, int $portSequence): void;

    /**
     * Abandon a failed renderer attempt without turning it into a reusable request identity.
     *
     * @param   string  $resourceContextKey  Opaque trusted host context.
     * @param   string  $requestId           Session-unique render attempt.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function abandon(string $resourceContextKey, string $requestId): void;

    /**
     * Atomically claim a complete, live, authority-bound grant once.
     *
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted session binding.
     * @param   string                     $requestId  Session-unique render attempt.
     * @param   StudioPreviewTransport     $transport  Accepted document transport evidence.
     * @param   DateTimeImmutable          $now        Trusted claim time.
     *
     * @return  StudioPreviewGrant|null  Complete grant, or null for absent, stale, cancelled or replayed claims.
     *
     * @since   2.0.0
     */
    public function claim(
        StudioHostSessionSnapshot $snapshot,
        string $requestId,
        StudioPreviewTransport $transport,
        DateTimeImmutable $now,
    ): ?StudioPreviewGrant;

    /**
     * Read one complete live grant after its document was claimed, for authenticated subresources only.
     *
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted session binding.
     * @param   string                     $requestId  Session-unique render attempt.
     * @param   StudioPreviewTransport     $transport  Exact channel/source/origin evidence.
     * @param   DateTimeImmutable          $now        Trusted read time.
     *
     * @return  StudioPreviewGrant|null  Claimed live grant, or null for absent, stale or mismatched reads.
     *
     * @since   2.0.0
     */
    public function claimed(
        StudioHostSessionSnapshot $snapshot,
        string $requestId,
        StudioPreviewTransport $transport,
        DateTimeImmutable $now,
    ): ?StudioPreviewGrant;
}
