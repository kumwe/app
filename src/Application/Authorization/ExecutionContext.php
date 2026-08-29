<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use InvalidArgumentException;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\Extension\Spi\Application\ExecutionContext as ExtensionExecutionContext;

/**
 * Immutable envelope naming who is acting, in which site, and under which request, for one unit of work.
 *
 * Authentication middleware mints one of these and attaches it to the request under `REQUEST_ATTRIBUTE`;
 * from there every authorization decision, audit record and idempotency fingerprint reads it instead of
 * re-deriving the actor further down the stack. It carries exactly one identity — a human
 * `AuthenticatedPrincipal` or a `SystemIdentity`, never both and never neither — and the authentication
 * strength is held to agree with that choice. Its provenance object ties it to the authority that issued
 * it, which is what `DenyByDefaultAuthorizationGateway` checks before anything else: a context assembled
 * anywhere but that authority authorizes nothing, whatever identity it claims to carry.
 *
 * @since  2.0.0
 */
final readonly class ExecutionContext implements ExtensionExecutionContext
{
    /**
     * PSR-7 request attribute the authentication middleware stores the active context under.
     *
     * The class name doubles as the key, so no two components can drift apart on the spelling.
     *
     * @var    string
     * @since  2.0.0
     */
    public const REQUEST_ATTRIBUTE = self::class;

    /**
     * Assemble a context and enforce the identity, strength and identifier invariants.
     *
     * @param   object                   $provenance              Authority that issued this context; the gateway
     *          compares it by object identity.
     * @param   ?AuthenticatedPrincipal  $principal               Human actor, or null for a system context.
     * @param   ?SystemIdentity          $systemIdentity          System actor, or null for a human context.
     * @param   SiteContext              $site                    Site this unit of work executes in.
     * @param   AuthenticationStrength   $authenticationStrength  How the actor proved itself.
     * @param   string                   $requestId               Identifier of this single unit of work.
     * @param   string                   $correlationId           Identifier shared across one trace.
     * @param   AuthenticatedSurface     $surface                 Authenticated delivery boundary.
     * @param   ?MembershipContext       $membership              Server-resolved organization membership.
     * @param   ?string                  $sessionId               Rotated browser-session identity, when present.
     * @param   ?StepUpProof             $stepUpProof             Fresh multi-factor proof, when completed.
     *
     * @throws  InvalidArgumentException  When both or neither identity is supplied, when the identity and
     *          the authentication strength disagree, or when either identifier
     *          is empty, longer than 191 bytes, or carries a control character.
     *
     * @since   2.0.0
     */
    private function __construct(
        private object $provenance,
        private ?AuthenticatedPrincipal $principal,
        private ?SystemIdentity $systemIdentity,
        private SiteContext $site,
        private AuthenticationStrength $authenticationStrength,
        private string $requestId,
        private string $correlationId,
        private AuthenticatedSurface $surface,
        private ?MembershipContext $membership,
        private ?string $sessionId,
        private ?StepUpProof $stepUpProof,
    ) {
        if (($principal === null) === ($systemIdentity === null)) {
            throw new InvalidArgumentException(
                'An execution context must contain exactly one human or system identity.',
            );
        }

        if ($principal !== null && $authenticationStrength === AuthenticationStrength::System) {
            throw new InvalidArgumentException('A human execution context cannot use system authentication.');
        }

        if ($systemIdentity !== null && $authenticationStrength !== AuthenticationStrength::System) {
            throw new InvalidArgumentException('A system execution context must use system authentication.');
        }

        self::assertIdentity($requestId, 'request');
        self::assertIdentity($correlationId, 'correlation');
        if ($sessionId !== null) {
            self::assertIdentity($sessionId, 'session');
        }
        if (($authenticationStrength === AuthenticationStrength::MultiFactor) !== ($stepUpProof !== null)) {
            throw new InvalidArgumentException('Multi-factor authentication requires exactly one step-up proof.');
        }
        if (
            $stepUpProof !== null && (
            $principal === null
            || $sessionId === null
            || $stepUpProof->actorId() !== $principal->subject()
            || $stepUpProof->sessionId() !== $sessionId
            || $stepUpProof->site()->identifier() !== $site->identifier()
            || $stepUpProof->organization()?->identifier() !== $membership?->organization()->identifier()
            || $stepUpProof->workspace()?->identifier() !== $membership?->workspace()?->identifier()
            || $stepUpProof->securityEpoch() !== $principal->securityEpoch()
            )
        ) {
            throw new InvalidArgumentException('The step-up proof does not match the execution context.');
        }
        if ($systemIdentity !== null && $surface !== AuthenticatedSurface::Background) {
            throw new InvalidArgumentException('A system execution context must use the background surface.');
        }
    }

    /**
     * Mint a context for a signed-in human actor.
     *
     * The principal must carry the same provenance as the context being issued, so a principal
     * authenticated by one authority cannot be re-wrapped in a context that another authority trusts.
     *
     * @param   object                  $provenance              Authority issuing the context.
     * @param   AuthenticatedPrincipal  $principal               Authenticated actor and its effective grants.
     * @param   SiteContext             $site                    Site this unit of work executes in.
     * @param   AuthenticationStrength  $authenticationStrength  How the actor proved itself; `System` is
     *          rejected here.
     * @param   string                  $requestId               Identifier of this single unit of work.
     * @param   ?string                 $correlationId           Trace identifier; defaults to `$requestId`.
     * @param   ?AuthenticatedSurface   $surface                 Delivery boundary; inferred for legacy callers.
     * @param   ?MembershipContext      $membership              Server-resolved organization membership.
     * @param   ?string                 $sessionId               Rotated browser-session identity.
     * @param   ?StepUpProof            $stepUpProof             Fresh proof for multi-factor strength.
     *
     * @return  self  A human context bound to the supplied authority.
     *
     * @throws  InvalidArgumentException  When the principal came from a different authority, when the
     *          strength is `System`, or when an identifier is invalid.
     *
     * @since   2.0.0
     */
    public static function issueHuman(
        object $provenance,
        AuthenticatedPrincipal $principal,
        SiteContext $site,
        AuthenticationStrength $authenticationStrength,
        string $requestId,
        ?string $correlationId = null,
        ?AuthenticatedSurface $surface = null,
        ?MembershipContext $membership = null,
        ?string $sessionId = null,
        ?StepUpProof $stepUpProof = null,
    ): self {
        if (!$principal->hasProvenance($provenance)) {
            throw new InvalidArgumentException('A human context requires a principal from the same authority.');
        }

        return new self(
            $provenance,
            $principal,
            null,
            $site,
            $authenticationStrength,
            $requestId,
            $correlationId ?? $requestId,
            $surface ?? match ($authenticationStrength) {
                AuthenticationStrength::BearerToken => AuthenticatedSurface::Api,
                default => AuthenticatedSurface::Administrator,
            },
            $membership,
            $sessionId,
            $stepUpProof,
        );
    }

    /**
     * Mint a context for unattended work that has no human behind it.
     *
     * The strength is fixed at `AuthenticationStrength::System`, which is what tells the gateway to
     * authorize against the identity's fixed capability list rather than against a principal's grants.
     *
     * @param   object          $provenance     Authority issuing the context.
     * @param   SystemIdentity  $identity       Which unattended actor is running.
     * @param   SiteContext     $site           Site this unit of work executes in.
     * @param   string          $requestId      Identifier of this single unit of work.
     * @param   ?string         $correlationId  Trace identifier; defaults to `$requestId`.
     *
     * @return  self  A system context carrying no human principal.
     *
     * @throws  InvalidArgumentException  When the request or correlation identifier is invalid.
     *
     * @since   2.0.0
     */
    public static function issueSystem(
        object $provenance,
        SystemIdentity $identity,
        SiteContext $site,
        string $requestId,
        ?string $correlationId = null,
    ): self {
        return new self(
            $provenance,
            null,
            $identity,
            $site,
            AuthenticationStrength::System,
            $requestId,
            $correlationId ?? $requestId,
            AuthenticatedSurface::Background,
            null,
            null,
            null,
        );
    }

    /**
     * Reach the human actor this context was issued for.
     *
     * @return  ?AuthenticatedPrincipal  The principal, or null when a system identity holds this context.
     *
     * @since   2.0.0
     */
    public function principal(): ?AuthenticatedPrincipal
    {
        return $this->principal;
    }

    /**
     * Reach the unattended actor this context was issued for.
     *
     * @return  ?SystemIdentity  The identity, or null when a human principal holds this context.
     *
     * @since   2.0.0
     */
    public function systemIdentity(): ?SystemIdentity
    {
        return $this->systemIdentity;
    }

    /**
     * Report which site this unit of work executes in.
     *
     * @return  SiteContext  Site every resource touched here must belong to, unless the action is global.
     *
     * @since   2.0.0
     */
    public function site(): SiteContext
    {
        return $this->site;
    }

    /**
     * Report how the actor proved itself for this unit of work.
     *
     * @return  AuthenticationStrength  Password or bearer token for a human, `System` for unattended work.
     *
     * @since   2.0.0
     */
    public function authenticationStrength(): AuthenticationStrength
    {
        return $this->authenticationStrength;
    }

    /**
     * Report the authenticated delivery boundary.
     *
     * @return  AuthenticatedSurface  Surface whose session or credential issued this context.
     *
     * @since   2.0.0
     */
    public function surface(): AuthenticatedSurface
    {
        return $this->surface;
    }

    /**
     * Reach the server-resolved organization, when this unit of work is organization-scoped.
     *
     * @return  ?OrganizationContext  Organization from live membership state, never request input.
     *
     * @since   2.0.0
     */
    public function organization(): ?OrganizationContext
    {
        return $this->membership?->organization();
    }

    /**
     * Reach the optional workspace nested inside the active membership.
     *
     * @return  ?WorkspaceContext  Selected workspace or null for organization-wide work.
     *
     * @since   2.0.0
     */
    public function workspace(): ?WorkspaceContext
    {
        return $this->membership?->workspace();
    }

    /**
     * Reach the versioned membership snapshot used by authorization.
     *
     * @return  ?MembershipContext  Membership, versions and policy generation, when organization-scoped.
     *
     * @since   2.0.0
     */
    public function membership(): ?MembershipContext
    {
        return $this->membership;
    }

    /**
     * Reach the rotated browser-session identity without exposing a cookie secret.
     *
     * @return  ?string  Stored session row identity, or null for non-session credentials.
     *
     * @since   2.0.0
     */
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Reach the fresh multi-factor proof attached to this context.
     *
     * @return  ?StepUpProof  Bound proof, present only with multi-factor strength.
     *
     * @since   2.0.0
     */
    public function stepUpProof(): ?StepUpProof
    {
        return $this->stepUpProof;
    }

    /**
     * Report the identifier of this single unit of work.
     *
     * @return  string  Distinct per operation; a context derived with `child()` carries a new one.
     *
     * @since   2.0.0
     */
    public function requestId(): string
    {
        return $this->requestId;
    }

    /**
     * Report the identifier shared by every unit of work in the same trace.
     *
     * @return  string  Carried unchanged into child contexts, so nested operations group in the audit trail.
     *
     * @since   2.0.0
     */
    public function correlationId(): string
    {
        return $this->correlationId;
    }

    /**
     * Name the actor that denials and audit records are attributed to.
     *
     * @return  string  The principal's subject for a human context, otherwise the system identity's value.
     *
     * @throws  \LogicException  When neither identity is present, which the constructor invariant prevents.
     *
     * @since   2.0.0
     */
    public function actorId(): string
    {
        return $this->principal?->subject() ?? $this->systemIdentity->value
            ?? throw new \LogicException('The execution context has no identity.');
    }

    /** @since 2.0.0 */
    public function siteIdentifier(): string
    {
        return $this->site->identifier();
    }

    /** @since 2.0.0 */
    public function organizationIdentifier(): ?string
    {
        return $this->organization()?->identifier();
    }

    /** @since 2.0.0 */
    public function workspaceIdentifier(): ?string
    {
        return $this->workspace()?->identifier();
    }

    /** @since 2.0.0 */
    public function deliverySurface(): string
    {
        return $this->surface->value;
    }

    /**
     * Digest everything about the caller's authority that must be unchanged for a replay to be safe.
     *
     * The idempotency middleware stores this beside a cached response and compares it when the same
     * idempotency key returns. A replay that arrives with different authority — a re-issued credential, a
     * bumped security epoch, an altered grant set, another site or a different authentication strength — no
     * longer matches, so it is refused rather than served the earlier result. Identity alone would not be
     * enough, since the same user can legitimately lose authority between the two requests.
     *
     * @return  string  Hex-encoded SHA-256 over the actor's own fingerprint, the site and the strength.
     *
     * @since   2.0.0
     */
    public function authorizationFingerprint(): string
    {
        $identity = $this->principal?->authorizationFingerprint()
            ?? 'system:' . ($this->systemIdentity->value ?? 'unknown');

        return hash('sha256', implode("\n", [
            $identity,
            $this->site->identifier(),
            $this->authenticationStrength->value,
            $this->surface->value,
            $this->membership?->fingerprint() ?? '-',
            $this->sessionId === null ? '-' : hash('sha256', $this->sessionId),
            $this->stepUpProof?->nonce() ?? '-',
        ]));
    }

    /**
     * Digest authority that remains stable across a password-to-step-up elevation of one session.
     *
     * Approval bindings use this digest so a request can wait for independent approvers and still be
     * consumed after a fresh proof and its mandatory session rotation, while any principal grant,
     * epoch, site, membership, or surface change makes the binding unusable.
     *
     * @return  string  Lowercase SHA-256 authority fingerprint without ephemeral proof state.
     *
     * @since   2.0.0
     */
    public function approvalFingerprint(): string
    {
        $identity = $this->principal?->authorityFingerprint()
            ?? 'system:' . ($this->systemIdentity->value ?? 'unknown');

        return hash('sha256', implode("\n", [
            $identity,
            $this->site->identifier(),
            $this->surface->value,
            $this->membership?->fingerprint() ?? '-',
        ]));
    }

    /**
     * Check that this context, and the principal inside it, came from a given authority.
     *
     * Comparison is by object identity, not equality, so the caller has to hold the very instance the
     * installation wired in. A context rebuilt from serialized data therefore cannot pass, which is what
     * makes the gateway's provenance check meaningful.
     *
     * @param   object  $provenance  Authority object to test this context against.
     *
     * @return  bool  True only when the context and its principal both originate from that authority.
     *
     * @since   2.0.0
     */
    public function hasProvenance(object $provenance): bool
    {
        return $this->provenance === $provenance
            && ($this->principal === null || $this->principal->hasProvenance($provenance));
    }

    /**
     * Derive a context for a nested operation, keeping the identity, site and strength.
     *
     * The MCP handlers use this so that each tool call within one session is authorized and audited under
     * its own request identifier while staying inside the caller's trace. Authority is unchanged: this is
     * a relabelling, not a way to acquire more.
     *
     * @param   string   $requestId      Identifier for the nested unit of work.
     * @param   ?string  $correlationId  Trace identifier; defaults to this context's own.
     *
     * @return  self  A context differing only in its request and correlation identifiers.
     *
     * @throws  InvalidArgumentException  When an identifier is empty, over 191 bytes, or has a control character.
     *
     * @since   2.0.0
     */
    public function child(string $requestId, ?string $correlationId = null): self
    {
        return new self(
            $this->provenance,
            $this->principal,
            $this->systemIdentity,
            $this->site,
            $this->authenticationStrength,
            $requestId,
            $correlationId ?? $this->correlationId,
            $this->surface,
            $this->membership,
            $this->sessionId,
            $this->stepUpProof,
        );
    }

    /**
     * Reject a request or correlation identifier that could not be stored or logged safely.
     *
     * @param   string  $value  Candidate identifier.
     * @param   string  $name   Which identifier is being checked, quoted in the failure message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is empty, over 191 bytes, or has a control character.
     *
     * @since   2.0.0
     */
    private static function assertIdentity(string $value, string $name): void
    {
        if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('The %s identity is invalid.', $name));
        }
    }
}
