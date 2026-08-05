<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\GrantScope;

final readonly class DenyByDefaultAuthorizationGateway implements AuthorizationGateway
{
    /** @var array<string, list<string>> */
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

    /** @var list<string> */
    private const INSTALLATION_GLOBAL_SYSTEM_IDENTITIES = [
        'system:extension-materializer',
        'system:installation-maintenance',
    ];

    public function __construct(
        private object $provenance,
        private AuthorizationPolicyRegistry $policies,
        private ResourceSiteOwnership $ownership,
        private AuthorizationDecisionRecorder $decisions,
    ) {
    }

    public function decide(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
    ): AuthorizationDecision {
        $decision = $this->evaluate($context, $action, $resource);
        $this->record($context, $action, $resource, $decision);

        return $decision;
    }

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
        $systemIdentity = $context->systemIdentity()?->value ?? '';
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

    private function siteFor(ExecutionContext $context, AuthorizationResource $resource): SiteContext
    {
        // Collections are created/listed within the caller's site. Queues are configured
        // transport partitions shared by sites; durable jobs carry the actual ownership.
        if ($resource->identifier() === '*' || $resource->type() === 'queue') {
            return $context->site();
        }

        return $this->ownership->siteFor($resource);
    }

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
