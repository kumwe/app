---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-007
title: "The identity authorization service and role-grant policy answer with the canonical four-state decision"
symbols:
  - Kumwe\App\Identity\Application\Authorization\AuthorizationService
  - Kumwe\App\Identity\Application\Authorization\AuthorizationPolicy
  - Kumwe\App\Identity\Application\Authorization\RoleGrantPolicy
layer: application
capability_index_sha256: "dd6380d5f3cbe868c69de22a87808bca7f552d1560fb5d9e411dd4d1bc6bbef2"
packages_reviewed:
  - package: kumwe/access-control
    version: 0.1.2
    symbols_inspected:
      - Kumwe\Access\AuthorizationDecision
      - Kumwe\Access\DecisionState
      - Kumwe\Access\DecisionCombiner
      - Kumwe\Access\AuthorizationGateway
      - Kumwe\Access\GrantScope
      - Kumwe\Access\Capability
    source_inspected:
      - vendor/kumwe/access-control/src
      - vendor/kumwe/access-control/docs/architecture.md
      - vendor/kumwe/access-control/MIGRATION-HANDOFF.md
    tests_inspected:
      - vendor/kumwe/access-control/resources/conformance/decisions-v1.json
      - vendor/kumwe/access-control/resources/public-api/v1.json
  - package: kumwe/access-context
    version: 0.1.2
    symbols_inspected:
      - Kumwe\Context\Contract\Principal
      - Kumwe\Context\Value\ExecutionContext
    source_inspected:
      - vendor/kumwe/access-context/src
    tests_inspected:
      - vendor/kumwe/access-context/docs/test-ownership.md
search_terms:
  - "authorization decision"
  - "decision combiner"
  - "deny by default"
  - "role grant"
  - "capability grant"
  - "user status"
  - "step-up"
  - "not applicable"
  - "policy.no_allowance"
  - "user.inactive"
required_capability: "Combine the identity-layer policies into one deny-by-default verdict over a user's status and role-derived grants, expressed as the canonical four-state decision."
consumers:
  - "src/Identity/Application/Authorization/AuthorizationService.php"
  - "src/Identity/Application/Authorization/RoleGrantPolicy.php"
  - "tests/Unit/Identity/Application/Authorization/AuthorizationServiceTest.php"
  - "tests/Unit/Identity/Application/Authorization/RoleGrantPolicyTest.php"
overlap_reviewed: []
decision: approved
decided_by: "eWɘyn (KUMWE-MIG-2026-009 adoption, standing maintainer mandate)"
reviewer: "eWɘyn (package-boundary review against the installed kumwe/access-control 0.1.2 sources, manifests and record)"
decided_on: "2026-09-23"
pull_request: null
---

## Capability required

The identity layer answers one question for the administrator and portal surfaces: may this user, given
the status of the account and the capability grants the assigned roles confer, exercise a capability over
a scope. The answer must be denied unless a rule allows it, an inactive account must be refused before any
rule is consulted, a denial from any rule must settle the outcome whatever another rule said, the rules
must be consulted in registration order and the first denial must stop the walk, and every verdict must
carry a stable policy code and reason token for the audit trail. With `kumwe/access-control` adopted, the
verdict is the package's four-state `AuthorizationDecision`, so the combination must also say what a
`step_up` and a `not_applicable` answer from a rule mean: the former is not permission and outranks an
allowance, the latter is an abstention.

## Why existing package APIs are insufficient

`Kumwe\Access\AuthorizationDecision` and `DecisionState` are consumed directly: they replace the App's
boolean identity-layer decision entirely, which is why the three surfaces changed. `DecisionCombiner` was
inspected as the candidate for the combination itself and does not fit the contract these classes keep:
it consumes every decision it is given, orders equal outcomes by policy and reason bytes rather than by
registration order, and returns a `not_applicable` decision for an empty or wholly abstaining input,
whereas this service must stop at the first denial, keep the first allowance in registration order, and
answer an abstaining set with a denial reasoned `policy.no_allowance`; it also knows nothing of the
`User` status check that precedes every rule. `AuthorizationGateway` is the resource-and-context gateway
the App implements as `DenyByDefaultAuthorizationGateway`; it decides over an execution context and a
resource, not over a user aggregate and its role grants. `kumwe/access-context` owns the principal and
context values and has no decision or grant vocabulary.

## Why extending the owning package is inappropriate

The package's charter names principal, session, token and role authority as non-responsibilities and the
handoff's `intentionally_excluded` list keeps principal issuance and role grants in App. A combiner that
reads the App `User` aggregate and its `CapabilityGrant` values would carry that authority into the
package; the package's own combiner is deliberately stateless and neutral, and the ordering these classes
guarantee is an App contract pinned by App tests, not a portable algebra.

## Why a new focused package is inappropriate

The three classes are one policy interface, one combination rule over App role grants and one rule that
reads them. They have no consumer outside App and exist only to bind App's role model to the canonical
decision; a package of them would own App authority without owning anything portable.

## App-specific responsibility

This is authority: which account statuses may act at all, how the App's registered policies combine, and
what a role grant is worth. The public surfaces changed only in what they return and consume: the
package decision instead of the retired identity-layer one, the package `Capability` and `GrantScope`
instead of the retired App names. `AuthorizationService` attributes its own verdicts to
`core.identity-authorization.v1` and `RoleGrantPolicy` its allowance to `core.role-grant.v1`, keeping the
`user.inactive`, `policy.no_allowance` and `role.grant` reason tokens the audit trail already records. If
the combination lived outside App, an account status or grant rule could be changed by a dependency
release rather than by this build.

## Tests proving the boundary

- `tests/Unit/Identity/Application/Authorization/AuthorizationServiceTest.php` pins deny-by-default for an
  empty policy set, the inactive-user refusal before any policy runs, a denial overriding an earlier
  allowance, a step-up outranking an allowance while conferring no authority, and `not_applicable` as an
  abstention that alone leaves the request denied.
- `tests/Unit/Identity/Application/Authorization/RoleGrantPolicyTest.php` pins that a covering grant
  yields `allow` under `core.role-grant.v1` reasoned `role.grant` and that an uncovered capability abstains.
- `tests/Unit/Identity/Domain/CapabilityGrantTest.php` pins the grant matching the policy relies on.
- The package's `resources/conformance/decisions-v1.json` and its `tests/Case/DecisionTest.php` own the
  decision value and the neutral combiner; no App test duplicates them.

## Decision

Approved on 2026-09-23 under the standing maintainer mandate as part of the KUMWE-MIG-2026-009 adoption.
The review compared the three classes against the installed 0.1.2 decision value, state enum and combiner
and the handoff's exclusions, and found that they consume the package decision without duplicating its
algebra and keep only App authority. This record is the adoption custodian's decision and review under
that mandate; it is not a human pull-request review event. Revisit when `kumwe/access-control` ships an
ordered, first-denial combiner or a user-status port, so that the combination can move upstream.
