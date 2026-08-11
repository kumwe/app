# Actions and safety

## Action hierarchy

Each task state has at most one primary action. Secondary actions remain visible when frequently needed;
infrequent and advanced actions move into a labelled menu or disclosure. Destructive actions are visually
separate and never become the default because an ordinary action is unavailable.

An action declaration includes owner, capability, target type, scope, expected version, destructive and
high-impact classifications, idempotency semantics, review requirements, success destination, failure
states, and audit event.

## Review and confirmation

A dedicated review is required for destructive, irreversible, high-impact, externally visible, bulk,
schema-changing, approval, revocation, purge, or recovery operations. It names:

- actor and authorization context;
- exact resource and version;
- selected scope and affected count;
- operation and material consequences;
- data, policy, external, and recovery impact;
- conflicts or prerequisites;
- whether the operation can be retried or reversed;
- the next observable state.

A confirmation button repeats the action, not a generic “Yes”. Typed confirmation is reserved for cases
where identifying the exact target or checksum materially prevents error. Browser-native confirmation is
only a no-JavaScript fallback; an enhanced review must preserve the same server checks.

## Step-up and approvals

Step-up is collected at the final submission/review boundary and bound to actor, action, target, context,
version, and expiry. It is not repeated in every management panel. Passwords, recovery codes, and tokens
are never retained in form repopulation, audit payloads, logs, screenshots, or idempotency records.

Maker-checker and separation-of-duty flows visibly distinguish request, review, approval, execution, and
immutable decision history. Hiding an approval action does not implement the rule; the application service
re-evaluates it transactionally.

## Long-running work

Long-running work redirects to a status workspace with operation identity, current state, progress or
bounded stage, last update, retry classification, recovery action, and safe navigation away. Refresh and
duplicate submission do not duplicate the effect. Failure messages distinguish retryable, operator-action,
permanent, and integrity-blocking outcomes without exposing secrets.

## Dangerous zones

Purge, permanent delete, trust revocation, credential emergency action, schema execution, and recovery
controls live in a labelled danger zone outside routine settings. Their warnings, audit meaning,
version/conflict state, and recovery consequences cannot be hidden by user preferences or themes.
