<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSecurity\Application\Administration;

use DateTimeImmutable;

/**
 * Canonical persistence boundary for the administrator business-security workspace.
 *
 * The port exposes structured rows and narrow mutations over the existing identity and business-security
 * tables. It deliberately has no generic document writer: policy expressions and field disclosures are
 * assembled and validated by the application service before they cross this boundary.
 *
 * @since  2.0.0
 */
interface BusinessSecurityAdministrationRepository
{
    /**
     * Return the complete, bounded administrator read model for one site.
     *
     * @param   string  $siteIdentifier  Site whose security state may be disclosed.
     *
     * @return  array<string, list<array<string, mixed>>>  Structured organizations, identities, policies,
     *          approvals, step-up status, tokens and definition fields.
     *
     * @since   2.0.0
     */
    public function overview(string $siteIdentifier): array;

    /**
     * Resolve the actor and organization behind an exact site-bounded membership.
     *
     * @param   string  $membershipId    Membership row to resolve.
     * @param   string  $siteIdentifier  Site that must own the membership.
     * @param   bool    $lock            Whether the implementation must lock the membership for mutation.
     *
     * @return  ?array{user_id: string, organization_id: string, organization_identifier: string}  Exact
     *          authority scope, or null when the membership is unavailable in the site.
     *
     * @since   2.0.0
     */
    public function membershipAuthority(
        string $membershipId,
        string $siteIdentifier,
        bool $lock = false,
    ): ?array;

    /**
     * Resolve the public identifier of an organization owned by an exact site.
     *
     * @param   string  $organizationId  Organization row to resolve.
     * @param   string  $siteIdentifier  Site that must own the organization.
     * @param   bool    $lock            Whether the implementation must lock the organization for mutation.
     *
     * @return  ?string  Organization identifier, or null when no matching organization exists.
     *
     * @since   2.0.0
     */
    public function organizationIdentifier(
        string $organizationId,
        string $siteIdentifier,
        bool $lock = false,
    ): ?string;

    /**
     * Resolve an exact workspace and its parent organization inside one site.
     *
     * @param   string  $workspaceId     Workspace row to resolve.
     * @param   string  $siteIdentifier  Site that must own the parent organization.
     * @param   bool    $lock            Whether the implementation must lock the workspace for mutation.
     *
     * @return  ?array{identifier: string, organization_id: string, organization_identifier: string}  Exact
     *          workspace authority, or null when the row is unavailable in the site.
     *
     * @since   2.0.0
     */
    public function workspaceAuthority(
        string $workspaceId,
        string $siteIdentifier,
        bool $lock = false,
    ): ?array;

    /**
     * Return the grants conferred by one role for effective-access and delegation checks.
     *
     * @param   string  $roleId  Role whose grant ceiling is being inspected.
     *
     * @return  list<array{capability: string, scope_type: string, scope_identifier: ?string}>
     *
     * @since   2.0.0
     */
    public function roleGrants(string $roleId): array;

    /**
     * Resolve the comparable field schema of one published definition in the exact site.
     *
     * @param   string  $definitionId    Published definition whose policy fields are needed.
     * @param   string  $siteIdentifier  Site that must own the definition.
     *
     * @return  array<string, string>  Field handles keyed to record-policy value type, or an empty map when absent.
     *
     * @since   2.0.0
     */
    public function definitionFieldTypes(string $definitionId, string $siteIdentifier): array;

    /**
     * Return the published action handles of one exact site-owned definition.
     *
     * @param   string  $definitionId    Published definition whose actions are needed.
     * @param   string  $siteIdentifier  Site that must own the definition.
     *
     * @return  list<string>  Stable action handles, or an empty list when the definition is unavailable.
     *
     * @since   2.0.0
     */
    public function definitionActions(string $definitionId, string $siteIdentifier): array;

    /**
     * Resolve the authority-bearing fields of one exact site-owned resource policy.
     *
     * @param   string  $policyId        Policy row whose activation is being evaluated.
     * @param   string  $siteIdentifier  Site that must own the policy scope.
     * @param   bool    $lock            Whether the implementation must lock the policy for mutation.
     *
     * @return  ?array{effect: string, capability: string, organization_id: ?string,
     *          organization_identifier: ?string}  Policy authority, or null when unavailable in the site.
     *
     * @since   2.0.0
     */
    public function resourcePolicyAuthority(
        string $policyId,
        string $siteIdentifier,
        bool $lock = false,
    ): ?array;

    /**
     * Resolve the optional organization scope of one exact site-owned separation rule.
     *
     * @param   string  $ruleId          Separation rule to resolve.
     * @param   string  $siteIdentifier  Site that must own the rule and optional organization.
     * @param   bool    $lock            Whether the implementation must lock the rule for mutation.
     *
     * @return  ?array{organization_id: ?string, organization_identifier: ?string}  Rule authority,
     *          or null when unavailable in the site.
     *
     * @since   2.0.0
     */
    public function separationRuleAuthority(
        string $ruleId,
        string $siteIdentifier,
        bool $lock = false,
    ): ?array;

    /**
     * Insert one site-owned organization at the supplied trusted instant.
     *
     * @param   string             $id              UUID assigned by the application service.
     * @param   string             $siteIdentifier  Site that owns the organization.
     * @param   string             $identifier      Stable operator-facing organization identifier.
     * @param   string             $name            Human-readable organization name.
     * @param   DateTimeImmutable  $at              Trusted creation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertOrganization(
        string $id,
        string $siteIdentifier,
        string $identifier,
        string $name,
        DateTimeImmutable $at,
    ): void;

    /**
     * Insert one workspace inside an active site-owned organization.
     *
     * @param   string             $id              UUID assigned by the application service.
     * @param   string             $organizationId  Parent organization row.
     * @param   string             $siteIdentifier  Site that must own the parent organization.
     * @param   string             $identifier      Stable operator-facing workspace identifier.
     * @param   string             $name            Human-readable workspace name.
     * @param   DateTimeImmutable  $at              Trusted creation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertWorkspace(
        string $id,
        string $organizationId,
        string $siteIdentifier,
        string $identifier,
        string $name,
        DateTimeImmutable $at,
    ): void;

    /**
     * Insert one versioned organization membership for an existing site user.
     *
     * @param   string              $id              UUID assigned by the application service.
     * @param   string              $organizationId  Organization receiving the member.
     * @param   string              $siteIdentifier  Site that must own the organization and user.
     * @param   string              $userId          User who owns the membership.
     * @param   DateTimeImmutable   $validFrom       First instant at which the membership is valid.
     * @param   ?DateTimeImmutable  $validUntil      Optional exclusive membership expiry.
     * @param   string              $actorId         Administrator creating the membership.
     * @param   DateTimeImmutable   $at              Trusted creation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertMembership(
        string $id,
        string $organizationId,
        string $siteIdentifier,
        string $userId,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil,
        string $actorId,
        DateTimeImmutable $at,
    ): void;

    /**
     * Apply one optimistic membership lifecycle transition and invalidate its credentials.
     *
     * @param   string             $membershipId     Membership row to change.
     * @param   string             $siteIdentifier   Site that must own the membership.
     * @param   string             $status           Validated target lifecycle state.
     * @param   int                $expectedVersion  Version the operator observed.
     * @param   string             $actorId          Administrator applying the transition.
     * @param   DateTimeImmutable  $at               Trusted transition instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function updateMembershipStatus(
        string $membershipId,
        string $siteIdentifier,
        string $status,
        int $expectedVersion,
        string $actorId,
        DateTimeImmutable $at,
    ): void;

    /**
     * Assign one organization-owned workspace to an exact site membership.
     *
     * @param   string             $membershipId    Membership receiving the workspace.
     * @param   string             $workspaceId     Workspace to assign.
     * @param   string             $siteIdentifier  Site that must own both rows.
     * @param   string             $actorId         Administrator applying the assignment.
     * @param   DateTimeImmutable  $at              Trusted assignment instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assignMembershipWorkspace(
        string $membershipId,
        string $workspaceId,
        string $siteIdentifier,
        string $actorId,
        DateTimeImmutable $at,
    ): void;

    /**
     * Withdraw one workspace assignment and invalidate the membership snapshot.
     *
     * @param   string             $membershipId    Membership losing the workspace.
     * @param   string             $workspaceId     Workspace to revoke.
     * @param   string             $siteIdentifier  Site that must own both rows.
     * @param   DateTimeImmutable  $at              Trusted revocation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function revokeMembershipWorkspace(
        string $membershipId,
        string $workspaceId,
        string $siteIdentifier,
        DateTimeImmutable $at,
    ): void;

    /**
     * Assign one role to a membership and invalidate the membership snapshot.
     *
     * @param   string             $membershipId    Membership receiving the role.
     * @param   string             $roleId          Role to assign.
     * @param   string             $siteIdentifier  Site that must own the membership and role.
     * @param   string             $actorId         Administrator applying the assignment.
     * @param   DateTimeImmutable  $at              Trusted assignment instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assignMembershipRole(
        string $membershipId,
        string $roleId,
        string $siteIdentifier,
        string $actorId,
        DateTimeImmutable $at,
    ): void;

    /**
     * Withdraw one role and invalidate the membership snapshot.
     *
     * @param   string             $membershipId    Membership losing the role.
     * @param   string             $roleId          Role to revoke.
     * @param   string             $siteIdentifier  Site that must own the membership and role.
     * @param   DateTimeImmutable  $at              Trusted revocation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function revokeMembershipRole(
        string $membershipId,
        string $roleId,
        string $siteIdentifier,
        DateTimeImmutable $at,
    ): void;

    /**
     * Persist one already-canonical row/field policy.
     *
     * @param   string                       $id                  UUID assigned by the application service.
     * @param   string                       $policyCode          Stable operator-facing policy code.
     * @param   string                       $capability          Capability controlled by the policy.
     * @param   string                       $action              Operation token evaluated by policy compilation.
     * @param   string                       $effect              Closed allow-or-deny effect.
     * @param   ?string                      $organizationId      Optional organization restriction.
     * @param   ?string                      $entityDefinitionId  Optional business-definition restriction.
     * @param   array<string, mixed>         $predicate           Closed policy AST produced by the service.
     * @param   array<string, list<string>>  $fields              Explicit field/action disclosures by usage.
     * @param   string                       $checksum            Digest of the canonical predicate and disclosures.
     * @param   int                          $priority            Stable policy ordering priority.
     * @param   string                       $actorId             Administrator creating the policy.
     * @param   string                       $siteIdentifier      Site that owns the policy.
     * @param   DateTimeImmutable            $at                  Trusted creation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertResourcePolicy(
        string $id,
        string $policyCode,
        string $capability,
        string $action,
        string $effect,
        ?string $organizationId,
        ?string $entityDefinitionId,
        array $predicate,
        array $fields,
        string $checksum,
        int $priority,
        string $actorId,
        string $siteIdentifier,
        DateTimeImmutable $at,
    ): void;

    /**
     * Apply an optimistic policy lifecycle change and bump the affected policy generation.
     *
     * @param   string             $id               Policy row to change.
     * @param   string             $siteIdentifier   Site that must own the policy.
     * @param   string             $status           Validated target lifecycle state.
     * @param   int                $expectedVersion  Version the operator observed.
     * @param   DateTimeImmutable  $at               Trusted transition instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function updateResourcePolicyStatus(
        string $id,
        string $siteIdentifier,
        string $status,
        int $expectedVersion,
        DateTimeImmutable $at,
    ): void;

    /**
     * Insert one structured separation-of-duty rule in an optional organization scope.
     *
     * @param   string             $id               UUID assigned by the application service.
     * @param   ?string            $organizationId   Optional organization restriction.
     * @param   string             $ruleCode         Stable operator-facing rule code.
     * @param   string             $resourceType     Resource type governed by the rule.
     * @param   string             $requestAction    Exact operation token that creates a request.
     * @param   string             $approvalAction   Capability required on the frozen approval request.
     * @param   ?string            $requesterRoleId  Optional role required of requesters.
     * @param   ?string            $approverRoleId   Optional role required of approvers.
     * @param   int                $quorum           Number of approvals required.
     * @param   bool               $distinctActors   Whether votes must come from distinct actors.
     * @param   string             $actorId          Administrator creating the rule.
     * @param   string             $siteIdentifier   Site that owns the rule.
     * @param   DateTimeImmutable  $at               Trusted creation instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertSeparationRule(
        string $id,
        ?string $organizationId,
        string $ruleCode,
        string $resourceType,
        string $requestAction,
        string $approvalAction,
        ?string $requesterRoleId,
        ?string $approverRoleId,
        int $quorum,
        bool $distinctActors,
        string $actorId,
        string $siteIdentifier,
        DateTimeImmutable $at,
    ): void;

    /**
     * Apply an optimistic separation-rule lifecycle transition.
     *
     * @param   string             $id               Separation rule to change.
     * @param   string             $siteIdentifier   Site that must own the rule.
     * @param   string             $status           Validated target lifecycle state.
     * @param   int                $expectedVersion  Version the operator observed.
     * @param   DateTimeImmutable  $at               Trusted transition instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function updateSeparationRuleStatus(
        string $id,
        string $siteIdentifier,
        string $status,
        int $expectedVersion,
        DateTimeImmutable $at,
    ): void;
}
