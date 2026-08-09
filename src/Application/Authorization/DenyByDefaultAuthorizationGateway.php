<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\GrantScope;

/**
 * The authorization gateway every guarded operation in Kumwe runs through, refusing whatever it cannot
 * positively justify.
 *
 * A decision is allowed only when four independent checks agree: the execution context was minted by this
 * installation's own authority, the policy registry declares the action legal on that resource type, the
 * site owning the resource is the site the caller is executing in, and the caller's authority covers the
 * request — a principal's scoped grants, or the fixed capability list its system identity is confined to.
 * Every other outcome denies, and each decision names the policy and reason that settled it, so the audit
 * trail explains itself rather than merely recording a verdict. Recording happens before the decision is
 * acted on, and an allowed decision whose audit record cannot be written is escalated rather than
 * proceeding unlogged.
 *
 * @since  2.0.0
 */
final readonly class DenyByDefaultAuthorizationGateway implements AuthorizationGateway
{
    /**
     * Capabilities each system identity may exercise when no human principal is present.
     *
     * A system identity holds exactly what its entry lists and nothing more, so widening a background
     * job's reach is a deliberate edit here rather than a configuration change. An identity absent from
     * the map, or present with an empty list as `system:cli` is, can authorize nothing at all.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const SYSTEM_CAPABILITIES = [
        'system:bootstrap' => ['administrator.bootstrap'],
        'system:cli' => [],
        'system:extension-materializer' => ['extensions.manage'],
        'system:installation-maintenance' => ['automation.manage'],
        'system:migration' => ['system.migrate'],
        'system:scheduler' => ['system.scheduler.dispatch'],
        'system:worker' => [
            'automation.manage',
            'content.archive',
            'content.publish',
            'content.read',
            'content.restore',
            'content.review',
            'content.submit',
            'content.unpublish',
            'content.update',
            'system.worker.operate',
        ],
    ];

    /**
     * System identities permitted to act on resources the policy registry marks installation-global.
     *
     * Materializing extensions and running installation maintenance legitimately reach across every site;
     * the remaining system identities are confined to the site their context names, so they are refused a
     * global resource even when their capability list contains the action.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const INSTALLATION_GLOBAL_SYSTEM_IDENTITIES = [
        'system:extension-materializer',
        'system:installation-maintenance',
    ];

    /**
     * Wire the gateway to the authority, policy table, ownership registry and audit sink it decides with.
     *
     * @param  object                         $provenance  Authority object contexts must have been minted
     *         with; anything else is untrusted.
     * @param  AuthorizationPolicyRegistry    $policies    Table of which actions are legal on which
     *         resource types, and which need a global grant.
     * @param  ResourceSiteOwnership          $ownership   Resolver for the site owning a given resource.
     * @param  AuthorizationDecisionRecorder  $decisions   Sink every decision is written to before it is acted on.
     *
     * @since  2.0.0
     */
    public function __construct(
        private object $provenance,
        private AuthorizationPolicyRegistry $policies,
        private ResourceSiteOwnership $ownership,
        private AuthorizationDecisionRecorder $decisions,
    ) {
    }

    /**
     * Evaluate an action against a resource and record the outcome before handing it back.
     *
     * The decision is audited whether or not it was allowed, so a caller that only wants to know whether
     * an operation would be permitted — deciding what to render, say — still leaves a trail. Use
     * `assertAllowed()` instead wherever a denial must stop the caller.
     *
     * @param   ExecutionContext       $context   Caller identity, site and provenance the work runs under.
     * @param   Capability             $action    Capability being exercised.
     * @param   AuthorizationResource  $resource  Target the action is aimed at.
     *
     * @return  AuthorizationDecision  The verdict together with the policy and reason that produced it.
     *
     * @throws  AuthorizationAuditUnavailable  When an allowed decision could not be recorded.
     *
     * @since   2.0.0
     */
    public function decide(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): AuthorizationDecision {
        $decision = $this->evaluate($context, $action, $resource);
        $this->record($context, $action, $resource, $decision);

        return $decision;
    }

    /**
     * Evaluate an action and stop the caller unless it is permitted.
     *
     * This is the guard to place ahead of any state change: it returns normally only on an allow, and the
     * decision is recorded either way.
     *
     * @param   ExecutionContext       $context   Caller identity, site and provenance the work runs under.
     * @param   Capability             $action    Capability being exercised.
     * @param   AuthorizationResource  $resource  Target the action is aimed at.
     *
     * @return  void
     *
     * @throws  AuthorizationDenied  When the decision is a denial, carrying its policy and reason.
     * @throws  AuthorizationAuditUnavailable  When an allowed decision could not be recorded.
     *
     * @since   2.0.0
     */
    public function assertAllowed(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): void {
        $decision = $this->decide($context, $action, $resource);

        if (!$decision->allowed) {
            $this->deny($context, $action, $resource, $decision);
        }
    }

    /**
     * Assert that the caller may hand a capability on to someone else within a scope.
     *
     * Delegation is capped by the caller's own effective authority, so a principal can only hand on what
     * its own grants already cover. A grant over the requested scope satisfies that ceiling, and so does
     * one over the site the caller is executing in, since a site-wide holder may already act on every
     * resource the scope could name. A scope naming a concrete resource must additionally be owned by
     * that same site. System identities never delegate: they carry no grants to draw a ceiling from.
     *
     * @param   ExecutionContext  $context  Caller identity, site and provenance the work runs under.
     * @param   Capability        $action   Capability the caller wants to grant onward.
     * @param   GrantScope        $scope    Scope the new grant would be issued in.
     *
     * @return  void
     *
     * @throws  AuthorizationDenied  When the delegation exceeds the caller's authority, the registry
     *          forbids delegating this action in this scope, or the scope's resource has no recorded owner.
     * @throws  AuthorizationAuditUnavailable  When an allowed decision could not be recorded.
     *
     * @since   2.0.0
     */
    public function assertCanDelegate(
        ExecutionContext $context,
        Capability $action,
        GrantScope $scope,
    ): void {
        $identifier = $scope->identifier() ?? '*';
        $type = $scope->isGlobal() ? 'capability' : $scope->type();
        $resource = AuthorizationResource::item($type, $identifier);
        $principal = $context->principal();
        $requested = [$scope];

        if (!$scope->isGlobal() && $scope->type() !== 'site') {
            $requested[] = GrantScope::named('site', $context->site()->identifier());
        }

        try {
            $siteMatches = $scope->isGlobal()
                || $scope->type() === 'site'
                || $this->siteFor($context, $resource)->identifier() === $context->site()->identifier();
        } catch (AuthorizationResourceOwnershipUnknown) {
            $decision = new AuthorizationDecision(false, 'core.site-ownership.v1', 'resource_site_unknown');
            $this->record($context, $action, $resource, $decision);
            $this->deny($context, $action, $resource, $decision);
        }

        $allowed = $context->hasProvenance($this->provenance)
            && $this->policies->supportsDelegation($action, $scope)
            && $siteMatches
            && $principal !== null
            && $principal->allows($action, $requested);
        $decision = new AuthorizationDecision(
            $allowed,
            'core.delegation-ceiling.v1',
            $allowed ? 'delegation_within_effective_authority' : 'delegation_exceeds_effective_authority',
        );
        $this->record($context, $action, $resource, $decision);

        if (!$allowed) {
            $this->deny($context, $action, $resource, $decision);
        }
    }

    /**
     * Run the checks a request must survive, without recording the outcome.
     *
     * The order is deliberate and every step short-circuits: an untrusted context is rejected before
     * anything else is consulted, then an action that is not legal on this resource type at all, then
     * site ownership, and only last the caller's own authority. Where the registry marks a resource
     * installation-global, a human needs a grant that is itself global — a site-wide grant does not
     * reach it — and a system identity must be one of the few permitted to cross sites. A `system.`
     * capability is refused to any human principal, and each system identity is confined to the
     * capabilities its `SYSTEM_CAPABILITIES` entry lists.
     *
     * @param   ExecutionContext       $context   Caller identity, site and provenance the work runs under.
     * @param   Capability             $action    Capability being exercised.
     * @param   AuthorizationResource  $resource  Target the action is aimed at.
     *
     * @return  AuthorizationDecision  The verdict, naming the check that settled it as policy and reason.
     *
     * @since   2.0.0
     */
    private function evaluate(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): AuthorizationDecision {
        if (!$context->hasProvenance($this->provenance)) {
            return new AuthorizationDecision(false, 'core.provenance.v1', 'untrusted_execution_context');
        }

        if (!$this->policies->supports($action, $resource)) {
            return new AuthorizationDecision(false, 'core.registry.v1', 'unsupported_action_resource');
        }

        try {
            $owner = $this->siteFor($context, $resource);
        } catch (AuthorizationResourceOwnershipUnknown) {
            return new AuthorizationDecision(false, 'core.site-ownership.v1', 'resource_site_unknown');
        }
        $globalGrantRequired = $this->policies->requiresGlobalGrant($action, $resource);
        if (!$globalGrantRequired && $owner->identifier() !== $context->site()->identifier()) {
            return new AuthorizationDecision(false, 'core.site-ownership.v1', 'resource_site_mismatch');
        }

        $principal = $context->principal();
        if ($principal !== null && str_starts_with($action->value(), 'system.')) {
            return new AuthorizationDecision(false, 'core.system-identity.v1', 'system_identity_required');
        }
        $identity = $context->systemIdentity();
        $systemIdentity = $identity === null ? '' : $identity->value;
        $allowed = $principal !== null
            ? $principal->allows(
                $action,
                $globalGrantRequired
                    ? [GrantScope::global()]
                    : [
                        GrantScope::named('site', $owner->identifier()),
                        GrantScope::named($resource->type(), $resource->identifier()),
                    ],
            )
            : (!$globalGrantRequired
                || in_array($systemIdentity, self::INSTALLATION_GLOBAL_SYSTEM_IDENTITIES, true))
                && in_array(
                    $action->value(),
                    self::SYSTEM_CAPABILITIES[$systemIdentity] ?? [],
                    true,
                );

        return new AuthorizationDecision(
            $allowed,
            'core.scoped-grants.v1',
            $allowed
                ? ($globalGrantRequired ? 'matching_global_grant' : 'matching_effective_grant')
                : ($globalGrantRequired ? 'global_grant_required' : 'no_matching_effective_grant'),
        );
    }

    /**
     * Resolve the site owning a resource, short-circuiting the targets that have no ownership record.
     *
     * A collection has no single owner, and a queue is a configured transport partition shared between
     * sites rather than a durable business resource, so both are treated as belonging to the calling site;
     * for queued work the ownership that matters travels with the durable job. Everything else is answered
     * by the ownership registry, which is authoritative and never guesses.
     *
     * @param   ExecutionContext       $context   Caller's context, used as the owner for the two shortcuts.
     * @param   AuthorizationResource  $resource  Target whose owning site is being established.
     *
     * @return  SiteContext  The site the resource belongs to.
     *
     * @throws  AuthorizationResourceOwnershipUnknown  When the registry records no owner for the resource.
     *
     * @since   2.0.0
     */
    private function siteFor(ExecutionContext $context, AuthorizationResource $resource): SiteContext
    {
        // Collections are created/listed within the caller's site. Queues are configured
        // transport partitions shared by sites; durable jobs carry the actual ownership.
        if ($resource->identifier() === '*' || $resource->type() === 'queue') {
            return $context->site();
        }

        return $this->ownership->siteFor($resource);
    }

    /**
     * Write a decision to the audit sink, failing the operation when an allow cannot be recorded.
     *
     * For a permitted action the record is part of the guarantee, not a side effect, so a recorder failure
     * there is escalated rather than swallowed and the caller never proceeds unlogged. A denial that
     * cannot be recorded is deliberately left alone: the caller is being refused in any case, and raising
     * an infrastructure error would replace a precise denial with a misleading one.
     *
     * @param   ExecutionContext       $context   Caller identity, site and provenance the work runs under.
     * @param   Capability             $action    Capability that was evaluated.
     * @param   AuthorizationResource  $resource  Target the action was aimed at.
     * @param   AuthorizationDecision  $decision  Verdict to record.
     *
     * @return  void
     *
     * @throws  AuthorizationAuditUnavailable  When the recorder fails while writing an allowed decision.
     *
     * @since   2.0.0
     */
    private function record(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
        AuthorizationDecision $decision,
    ): void {
        try {
            $this->decisions->record($context, $action, $resource, $decision);
        } catch (\Throwable $failure) {
            if ($decision->allowed) {
                throw new AuthorizationAuditUnavailable($failure);
            }
        }
    }

    /**
     * Turn a refused decision into the exception callers see, carrying the reasoning with it.
     *
     * The actor, action, resource, site, policy and reason all travel on the exception so that a handler
     * can report or classify the refusal without re-running the decision.
     *
     * @param   ExecutionContext       $context   Caller identity and site quoted in the refusal.
     * @param   Capability             $action    Capability that was refused.
     * @param   AuthorizationResource  $resource  Target the action was aimed at.
     * @param   AuthorizationDecision  $decision  Verdict supplying the policy and reason.
     *
     * @return  never
     *
     * @throws  AuthorizationDenied  Always; raising it is the whole purpose of the method.
     *
     * @since   2.0.0
     */
    private function deny(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
        AuthorizationDecision $decision,
    ): never {
        throw new AuthorizationDenied(
            $context->actorId(),
            $action->value(),
            $resource->type(),
            $resource->identifier(),
            $context->site()->identifier(),
            $decision->policy,
            $decision->reason,
        );
    }
}
