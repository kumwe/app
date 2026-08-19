<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Fresh, single-session proof produced by a successful multi-factor challenge.
 *
 * A proof is not authority by itself. High-impact policy verifies its actor, rotated session, site,
 * organization, method, freshness and nonce before consuming the action or approval in one transaction.
 *
 * @since  2.0.0
 */
final readonly class StepUpProof
{
    /**
     * Validate a proof issued by the configured step-up provider.
     *
     * @param   string                $actorId        Principal subject bound to the proof.
     * @param   string                $sessionId      Rotated session identifier bound to the proof.
     * @param   SiteContext           $site           Exact site for which it is valid.
     * @param   ?OrganizationContext  $organization   Exact organization, when organization-scoped.
     * @param   string                $method         Provider method such as `totp` or `recovery_code`.
     * @param   DateTimeImmutable     $verifiedAt     UTC instant at successful verification.
     * @param   DateTimeImmutable     $expiresAt      Exclusive freshness boundary.
     * @param   string                $nonce          Unpredictable proof identity used to prevent replay.
     * @param   ?WorkspaceContext     $workspace      Exact workspace, when the protected operation is narrower.
     * @param   string                $purpose        Narrow protected-operation purpose.
     * @param   int                   $securityEpoch  Actor authorization epoch at verification time.
     *
     * @throws  InvalidArgumentException  When a bound identifier, method, interval or nonce is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $actorId,
        private string $sessionId,
        private SiteContext $site,
        private ?OrganizationContext $organization,
        private string $method,
        private DateTimeImmutable $verifiedAt,
        private DateTimeImmutable $expiresAt,
        private string $nonce,
        private ?WorkspaceContext $workspace = null,
        private string $purpose = 'legacy.step_up',
        private int $securityEpoch = 1,
    ) {
        foreach (['actor' => $actorId, 'session' => $sessionId] as $name => $value) {
            if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException(sprintf('The step-up %s identity is invalid.', $name));
            }
        }
        if (preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $method) !== 1) {
            throw new InvalidArgumentException('The step-up method is invalid.');
        }
        if ($expiresAt <= $verifiedAt || $expiresAt > $verifiedAt->modify('+15 minutes')) {
            throw new InvalidArgumentException('The step-up freshness interval is invalid.');
        }
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $nonce) !== 1) {
            throw new InvalidArgumentException('The step-up proof nonce is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9._:-]{0,126}$/D', $purpose) !== 1) {
            throw new InvalidArgumentException('The step-up proof purpose is invalid.');
        }
        if ($securityEpoch < 1) {
            throw new InvalidArgumentException('The step-up proof security epoch must be positive.');
        }
    }

    /** Return the bound actor identity. @return string Bound actor identity. @since 2.0.0 */
    public function actorId(): string
    {
        return $this->actorId;
    }

    /** Return the rotated session identity. @return string Rotated session identity. @since 2.0.0 */
    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /** Return the bound site. @return SiteContext Bound site. @since 2.0.0 */
    public function site(): SiteContext
    {
        return $this->site;
    }

    /** Return the bound organization. @return ?OrganizationContext Bound organization. @since 2.0.0 */
    public function organization(): ?OrganizationContext
    {
        return $this->organization;
    }

    /** Return the verified method. @return string Verified provider method. @since 2.0.0 */
    public function method(): string
    {
        return $this->method;
    }

    /** Return the verification instant. @return DateTimeImmutable Verification instant. @since 2.0.0 */
    public function verifiedAt(): DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    /** Return the freshness boundary. @return DateTimeImmutable Freshness boundary. @since 2.0.0 */
    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** Return the replay nonce. @return string Replay-resistant proof nonce. @since 2.0.0 */
    public function nonce(): string
    {
        return $this->nonce;
    }

    /** Return the bound workspace. @return ?WorkspaceContext Bound workspace. @since 2.0.0 */
    public function workspace(): ?WorkspaceContext
    {
        return $this->workspace;
    }

    /** Return the operation purpose. @return string Protected-operation purpose. @since 2.0.0 */
    public function purpose(): string
    {
        return $this->purpose;
    }

    /** Return the actor epoch. @return int Authorization epoch at verification. @since 2.0.0 */
    public function securityEpoch(): int
    {
        return $this->securityEpoch;
    }

    /**
     * Test every binding and freshness boundary for a high-impact decision.
     *
     * @param   string                $actorId       Expected actor.
     * @param   string                $sessionId     Current rotated session.
     * @param   SiteContext           $site          Current site.
     * @param   ?OrganizationContext  $organization  Current organization.
     * @param   DateTimeImmutable     $now           Current trusted time.
     *
     * @return  bool  True only while every exact binding still matches and the proof is fresh.
     *
     * @since   2.0.0
     */
    public function isValidFor(
        string $actorId,
        string $sessionId,
        SiteContext $site,
        ?OrganizationContext $organization,
        DateTimeImmutable $now,
    ): bool {
        return hash_equals($this->actorId, $actorId)
            && hash_equals($this->sessionId, $sessionId)
            && $this->site->identifier() === $site->identifier()
            && $this->organization?->identifier() === $organization?->identifier()
            && $now >= $this->verifiedAt
            && $now < $this->expiresAt;
    }
}
