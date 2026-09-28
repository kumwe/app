# ADR 0023 — Host-local credential recovery restores existing authority

**Status:** Implemented in #152; accepted by the maintainer's merge.
**Decision maker:** Integrating agent under the standing maintainer mandate, 2026-09-28.
**Scope:** `P7-C`, credential recovery during a total administrator lockout.

## Evidence

`user:recover-credentials` is already the documented total-lockout route. The composition root gives
that command the dedicated `SystemIdentity::CredentialRecovery` principal. The authorization gateway
verifies its private kernel provenance and the `users.manage` resource policy before the service changes
anything. Ordinary bearer credentials cannot perform the browser's payload-bound recovery actions.

The credential-takeover ceiling introduced a contradiction: a system principal holds no human grants,
so `assertCanDelegateUser()` refused recovery whenever the locked-out subject already held a grant.
It could repair an empty account but not the administrator whose recovery the command exists to perform.

## Decision

After the ordinary resource authorization succeeds, the exact credential-recovery system identity may
repair an existing account without satisfying the human delegation ceiling. This exception is private
to the password-reset and second-factor-retirement service paths. It grants no role, capability or token,
and does not change the authorization gateway's rule that system identities never delegate authority.

Human callers retain their step-up and delegation checks. Other system identities remain refused.
Constructing a recovery identity with a different provenance object remains refused before the exception.
Host access remains the authority for the emergency console, as documented in `docs/administration.md`;
no HTTP or MCP recovery endpoint is added.

## Proof and effects

`CredentialLifecycleIntegrationTest` invokes the real registered command against an account with grants,
checks old-password and token invalidation, and checks that all three actions name the dedicated recovery
actor in the audit trail. It also refuses a forged recovery context and a valid worker context without
changing the password. `IdentityRecoveryMachineEquivalenceIntegrationTest` retains the machine-surface
refusals. Existing credential-lifecycle tests cover session termination, security epochs and second-factor
retirement under the same transaction and subject lock.

The independent security review and required three-engine CI qualify the implementation. This decision
does not claim that a draft PR has been accepted or that possessing an ordinary administrator account
confers host access.
