<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Media;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable host-side record of one canonical Studio upload-session state machine.
 *
 * @since  2.0.0
 */
final readonly class StudioMediaUploadSession
{
    /**
     * Capture one fully scoped upload snapshot.
     *
     * @param   string                         $id           Opaque upload identity.
     * @param   string                         $actorId      Trusted actor owner.
     * @param   string                         $siteId       Trusted site owner.
     * @param   string                         $contextKey   Opaque Studio resource context.
     * @param   string                         $generation   Authority generation at authorization.
     * @param   StudioMediaUploadRequest       $request      Immutable declared request.
     * @param   StudioMediaUploadPlan          $plan         Host-derived transfer plan.
     * @param   StudioMediaUploadState         $state        Canonical lifecycle state.
     * @param   int                            $transferred  Exact received byte count.
     * @param   string                         $tokenDigest  SHA-256 grant-token digest.
     * @param   DateTimeImmutable              $expiresAt    Exclusive grant expiry.
     * @param   StudioMediaAcceptedAsset|null  $asset        Accepted asset after completion.
     * @param   string|null                    $failureCode  Stable failure code for a failed session.
     * @param   int                            $version      Optimistic persistence version.
     *
     * @throws  InvalidArgumentException  When state and retained data disagree.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public string $actorId,
        public string $siteId,
        public string $contextKey,
        public string $generation,
        public StudioMediaUploadRequest $request,
        public StudioMediaUploadPlan $plan,
        public StudioMediaUploadState $state,
        public int $transferred,
        public string $tokenDigest,
        public DateTimeImmutable $expiresAt,
        public ?StudioMediaAcceptedAsset $asset = null,
        public ?string $failureCode = null,
        public int $version = 1,
    ) {
        self::stableId($id);
        foreach ([$actorId, $siteId, $generation] as $coordinate) {
            if ($coordinate === '' || strlen($coordinate) > 200 || preg_match('/[\x00-\x1F\x7F]/', $coordinate) === 1) {
                throw new InvalidArgumentException('A Studio upload scope coordinate is invalid.');
            }
        }
        self::stableId($contextKey);
        if (preg_match('/^[a-f0-9]{64}$/D', $tokenDigest) !== 1) {
            throw new InvalidArgumentException('The Studio upload grant digest is invalid.');
        }
        if ($transferred < 0 || $transferred > $request->byteSize || $version < 1) {
            throw new InvalidArgumentException('The Studio upload progress is invalid.');
        }
        if (($state === StudioMediaUploadState::Complete) !== ($asset !== null)) {
            throw new InvalidArgumentException('A complete Studio upload requires exactly one accepted asset.');
        }
        if (($state === StudioMediaUploadState::Failed) !== ($failureCode !== null)) {
            throw new InvalidArgumentException('A failed Studio upload requires exactly one failure code.');
        }
    }

    /**
     * Apply one legal state/progress replacement and advance the optimistic version.
     *
     * @param   StudioMediaUploadState         $state        Next canonical state.
     * @param   int                            $transferred  Exact transferred byte count.
     * @param   StudioMediaAcceptedAsset|null  $asset        Accepted identity only for completion.
     * @param   string|null                    $failureCode  Stable code only for failure.
     *
     * @return  self  Detached next snapshot.
     *
     * @throws  InvalidArgumentException  When the transition is not legal.
     *
     * @since   2.0.0
     */
    public function transition(
        StudioMediaUploadState $state,
        int $transferred,
        ?StudioMediaAcceptedAsset $asset = null,
        ?string $failureCode = null,
    ): self {
        $allowed = match ($this->state) {
            StudioMediaUploadState::Authorized => [
                StudioMediaUploadState::Transferring,
                StudioMediaUploadState::Verifying,
                StudioMediaUploadState::Cancelled,
                StudioMediaUploadState::Failed,
            ],
            StudioMediaUploadState::Transferring => [
                StudioMediaUploadState::Verifying,
                StudioMediaUploadState::Cancelled,
                StudioMediaUploadState::Failed,
            ],
            StudioMediaUploadState::Verifying => [
                StudioMediaUploadState::Complete,
                StudioMediaUploadState::Cancelled,
                StudioMediaUploadState::Failed,
            ],
            default => [],
        };
        if (!in_array($state, $allowed, true) || $transferred < $this->transferred) {
            throw new InvalidArgumentException('The Studio upload transition is invalid.');
        }

        return new self(
            $this->id,
            $this->actorId,
            $this->siteId,
            $this->contextKey,
            $this->generation,
            $this->request,
            $this->plan,
            $state,
            $transferred,
            $this->tokenDigest,
            $this->expiresAt,
            $asset,
            $failureCode,
            $this->version + 1,
        );
    }

    /**
     * Advance a verifying snapshot as an optimistic exclusive-completion claim.
     *
     * The state remains canonical; only the persistence version advances. A transaction that later
     * fails rolls this claim back, while a concurrent completer loses the compare-and-swap before it
     * can admit another asset.
     *
     * @return  self  Verifying snapshot with the next optimistic version.
     *
     * @throws  InvalidArgumentException  When completion is claimed outside verifying state.
     *
     * @since   2.0.0
     */
    public function claimCompletion(): self
    {
        if ($this->state !== StudioMediaUploadState::Verifying) {
            throw new InvalidArgumentException('Only a verifying Studio upload can claim completion.');
        }

        return new self(
            $this->id,
            $this->actorId,
            $this->siteId,
            $this->contextKey,
            $this->generation,
            $this->request,
            $this->plan,
            $this->state,
            $this->transferred,
            $this->tokenDigest,
            $this->expiresAt,
            version: $this->version + 1,
        );
    }

    /**
     * Validate one opaque stable identity without retaining its value in a failure.
     *
     * @param   string  $value  Candidate identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function stableId(string $value): void
    {
        if (
            strlen($value) > 240
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/D', $value) !== 1
            || in_array($value, ['__proto__', 'prototype', 'constructor'], true)
        ) {
            throw new InvalidArgumentException('A Studio upload identity is invalid.');
        }
    }
}
