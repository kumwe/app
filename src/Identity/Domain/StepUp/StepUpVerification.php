<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain\StepUp;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Fresh, context-bound result of a successful second-factor challenge.
 *
 * Application wiring adapts this value to the platform `StepUpProof`. The check method is deliberately
 * exact: a proof cannot be reused with the old session, another purpose, a changed authorization epoch,
 * or another site, organization, or workspace.
 *
 * @since  2.0.0
 */
final readonly class StepUpVerification
{
    /**
     * Capture a successful verification after session rotation.
     *
     * @param   StepUpIntent          $intent          Server-issued context that was challenged.
     * @param   string                $credentialId    TOTP credential UUID used by the challenge.
     * @param   StepUpMethod          $method          TOTP or recovery-code path.
     * @param   DateTimeImmutable     $issuedAt        Verification instant.
     * @param   DateTimeImmutable     $expiresAt       Exclusive freshness deadline.
     * @param   string                $nonce           Unpredictable proof nonce.
     * @param   RotatedStepUpSession  $rotatedSession  Replacement session the proof is bound to.
     *
     * @throws  InvalidArgumentException  When the credential, freshness interval, nonce, or session expiry is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public StepUpIntent $intent,
        public string $credentialId,
        public StepUpMethod $method,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public string $nonce,
        public RotatedStepUpSession $rotatedSession,
    ) {
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}'
            . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di';
        if (preg_match($uuid, $credentialId) !== 1) {
            throw new InvalidArgumentException('A step-up verification credential ID must be a canonical UUID.');
        }
        if ($expiresAt <= $issuedAt || $expiresAt > $issuedAt->modify('+15 minutes')) {
            throw new InvalidArgumentException('A step-up verification freshness interval is invalid.');
        }
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $nonce) !== 1) {
            throw new InvalidArgumentException('A step-up verification nonce is invalid.');
        }
        if ($rotatedSession->expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('A step-up verification requires a live rotated session.');
        }
    }

    /**
     * Prove that this result is fresh and exactly matches an attempted protected operation.
     *
     * @param   DateTimeImmutable  $now                     Current instant.
     * @param   string             $sessionId               Replacement session presented now.
     * @param   string             $purpose                 Protected operation purpose.
     * @param   string             $siteIdentifier          Current server-resolved site.
     * @param   ?string            $organizationIdentifier  Current server-resolved organization.
     * @param   ?string            $workspaceIdentifier     Current server-resolved workspace.
     * @param   int                $securityEpoch           Current actor authorization epoch.
     *
     * @return  bool  True only when every binding and the freshness deadline agree.
     *
     * @since   2.0.0
     */
    public function isFreshFor(
        DateTimeImmutable $now,
        string $sessionId,
        string $purpose,
        string $siteIdentifier,
        ?string $organizationIdentifier,
        ?string $workspaceIdentifier,
        int $securityEpoch,
    ): bool {
        return $now >= $this->issuedAt
            && $now < $this->expiresAt
            && hash_equals($this->rotatedSession->sessionId, $sessionId)
            && hash_equals($this->intent->purpose, $purpose)
            && hash_equals($this->intent->siteIdentifier, $siteIdentifier)
            && $this->intent->organizationIdentifier === $organizationIdentifier
            && $this->intent->workspaceIdentifier === $workspaceIdentifier
            && $this->intent->securityEpoch === $securityEpoch;
    }
}
