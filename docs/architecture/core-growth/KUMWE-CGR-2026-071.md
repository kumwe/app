---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-071
title: "Every-grant-path authority read for the credential-takeover delegation ceiling"
symbols:
  - Kumwe\App\Identity\Application\Administration\AccessControlRepository
layer: application
capability_index_sha256: "ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826"
packages_reviewed:
  - package: kumwe/access-control
    version: v0.1.2
    symbols_inspected:
      - Kumwe\Access\AuthorizationGateway
      - Kumwe\Access\MembershipDirectory
      - Kumwe\Access\MembershipContextValidator
      - Kumwe\Access\GrantScope
      - Kumwe\Access\Capability
    source_inspected:
      - vendor/kumwe/access-control/src/AuthorizationGateway.php
      - vendor/kumwe/access-control/src/MembershipDirectory.php
      - vendor/kumwe/access-control/CHARTER.md
    tests_inspected:
      - vendor/kumwe/access-control/tests
  - package: kumwe/access-context
    version: v0.1.2
    symbols_inspected:
      - Kumwe\Context\Value\ExecutionContext
      - Kumwe\Context\Value\MembershipContext
    source_inspected:
      - vendor/kumwe/access-context/src/Value
    tests_inspected:
      - vendor/kumwe/access-context/tests
search_terms:
  - delegation ceiling
  - user grants
  - membership roles
  - effective authority
  - credential takeover
  - password reset authority
required_capability: "Read every capability a user could exercise through any grant path, direct roles and every organization membership whatever its state, so a password reset or second-factor retirement is refused when the account holds authority the actor could not delegate."
consumers:
  - src/Identity/Application/Administration/AccessControlService.php
overlap_reviewed: []
decision: approved
decided_by: "Security and privacy qualification agent under standing maintainer mandate"
reviewer: "Security and privacy qualification agent (source ownership review; not human approval)"
decided_on: "2026-09-24"
pull_request: "https://github.com/kumwe/app/pull/152"
---

## Capability required

A holder of `users.manage` must not be able to reset the password of, or retire the second factor of, an
account whose effective capabilities from any source exceed the actor's own, because choosing another
account's credential is taking that account over. The authorization model confers capabilities through two
grant paths, both ending in `role_capability_grants`: a user's direct roles (`user_roles`) and the roles of
each organization membership (`organization_memberships` joined to `membership_roles`). The ceiling needs the
union of both, including memberships that are inactive, not yet valid or expired today, since any of them can
be reactivated after the credential has changed hands.

## Why existing package APIs are insufficient

`kumwe/access-control` supplies `AuthorizationGateway::assertCanDelegate()`, which the ceiling reuses
unchanged for every returned grant, and `MembershipDirectory`, which resolves and lists only the active,
current selections a subject may enter; it deliberately never reports an inactive or expired membership and
never reads role grants. Neither package stores roles or grants: those tables and their persistence belong to
the App identity bounded context. The existing App port method `userGrants()` returns direct-role grants only,
because it answers what a site-level token may carry, and must keep that meaning.

## Why extending the owning package is inappropriate

Role and membership-role storage is App persistence behind an App port. Teaching `kumwe/access-control` to
enumerate stored grants would make the portable authorization library depend on App tables and on the App's
choice of which grant paths exist.

## Why a new focused package is inappropriate

There is no portable bounded context: the growth is one read on the App's own access-control port, composed
with an existing package gateway call inside an existing App use case.

## App-specific responsibility

This is security enforcement over App-owned identity storage. `userAuthorityGrants()` is read under the user
lock `resetUserPassword()` and `revokeStepUpCredentials()` already take, and each row is handed to the
package's delegation ceiling. The one implementation, `DoctrineAccessControlRepository`, unions the direct-role
and membership-role joins over the same `role_capability_grants` table every principal this installation
builds is fed from, so no grant path that confers a capability is left out of the ceiling.

## Tests proving the boundary

- `CredentialTakeoverCeilingTest::testMembershipRoleAuthorityCountsTowardsTheCeiling` gives an account
  `settings.manage` only through an inactive organization membership and requires the REST password reset and
  second-factor retirement by a `users.manage` holder to be refused, on MariaDB and PostgreSQL; it fails on
  the previous direct-roles-only ceiling.
- `CredentialTakeoverCeilingTest::testAUserManagerCannotTakeOverAStrongerAccount` keeps the direct-role
  refusal and the recovery of an account inside the ceiling.
- `CredentialLifecycleIntegrationTest` and `AccessControlIntegrationTest` keep the existing administrative
  reset, retirement and grant behaviour on both engines.

## Decision

Approved as App security enforcement over App-owned identity storage under the standing maintainer mandate.
The ownership review is by the implementing agent, not an independent review or human GitHub approval.
Revisit if `kumwe/access-control` gains a port that enumerates a subject's stored grants.
