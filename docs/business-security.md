# Business security

Kumwe applies one deny-by-default authorization model to browser, REST, CLI, MCP, worker, scheduler, and
extension traffic. A delivery adapter may authenticate a principal and select a server-owned context, but it may
not invent capabilities, memberships, policy attributes, approval facts, or step-up evidence. Every protected
business-record operation enters `BusinessRecordService` with the same immutable execution context and is
rechecked inside the application transaction.

## Authorization model

The signed core and extension contribution catalogs are the source of capability and resource-policy
definitions. Definitions have a stable owner, checksum, lifecycle, supported scopes, resource targets, and
human/delegable/system-only classification. The live registry accepts only current, trusted contributions;
disable, uninstall, replacement, or trust revocation removes their enforcement definitions immediately while
preserving durable ownership and diagnostic history.

The standard record actions are:

- `business.record.create`, `business.record.read`, `business.record.update`, `business.record.archive`,
  `business.record.delete`, and `business.record.restore`;
- `business.record.transition` plus the exact contributed action and transition capability;
- `business.record.export` and `business.record.report` for their distinct disclosure paths.

Capabilities may declare global, site, organization, workspace, entity, record, relation, ownership, assignment,
workflow, or temporal scope. Scope is an intersection, never a fallback: carrying a broad grant does not satisfy
an operation whose policy requires a narrower organization, workspace, record, or relation fact.

Authorization is evaluated in this order:

1. resolve the capability and target from the live owner-aware contribution registry;
2. validate the authenticated surface, site, organization, workspace, membership version, policy generation,
   security epoch, and session binding;
3. require the exact capability and permitted scope;
4. load the current, trusted record-policy set and validate its checksum and bounded canonical AST;
5. apply every matching deny before any allow; an absent allow is a denial;
6. compile the resulting row predicate and field plan into the repository operation;
7. for high-impact work, verify and atomically spend the exact approval and fresh step-up proof;
8. write the mutation, revision, idempotency result, and redacted audit event in the owning transaction.

Single-record reads deliberately return the same not-found result for an absent and an unauthorized record.
Browse counts, aggregates, cursors, relation traversal, includes, reports, exports, background jobs, and direct
identity lookup all receive the same policy plan before observing rows.

Plan resolution and repository execution share one transaction and hold a shared lock on the site's policy
generation. Policy and SoD writes increment that generation in their own transaction, so a change commits wholly
before a record operation plans or waits until that operation finishes; a plan cannot execute across the change.

## Conditional record policy language

Conditional policies are data, not code. The canonical representation is a bounded JSON document decoded into a
closed typed predicate tree. It cannot contain SQL, PHP, Twig, JavaScript, shell, arbitrary class names, database
identifiers, callbacks, or provider expressions. Limits cover document bytes, predicate depth and count, string
length, set size, fields, relations, and related-definition breadth.

The closed predicates are constant, comparison, null check, boolean `and`/`or`/`not`, typed attribute comparison,
field-to-attribute comparison, and attribute null check. Scalar types are boolean, integer, decimal, string, UUID,
date, local time, and UTC instant. Comparisons are type checked before persistence and before compilation.

Allowlisted attributes include the principal identifier and security epoch; server-selected site, organization,
workspace, surface, authentication strength, current date/time; membership identifier, version, and policy
generation; and resolved resource definition, version, operation, and scope. Client headers, query strings, form
fields, token claims that were not revalidated, and arbitrary clock values are never policy attributes.

An example structured policy is conceptually:

```json
{
  "effect": "allow",
  "action": "business.record.read",
  "predicate": {
    "type": "and",
    "children": [
      {"type": "field_attribute_comparison", "field": "owner_id", "attribute": "principal.id", "op": "eq"},
      {"type": "attribute_comparison", "attribute": "context.workspace", "op": "eq",
        "value_type": "string", "value": "operations"}
    ]
  }
}
```

The administrator builds this shape through allowlisted controls. There is no raw JSON policy editor.

## Field disclosure and query safety

Each policy has explicit fields for detail, list, filter, search, sort, aggregate, export, report, history, audit,
MCP, relation, include, and public-reference usage. A field omitted from a usage is denied for that usage. Explicit deny
rules subtract fields globally. When several conditional allow rules can admit different rows, their field grants
are intersected so one rule cannot disclose a field on a row admitted only by another rule.

The query compiler receives an immutable access plan containing the row predicate, allowed field sets, allowed
relations, related-definition plans, and a SHA-256 authorization digest. It applies policy predicates before
counting, grouping, sorting, keyset pagination, relation `EXISTS`, includes, or projection. Cursors bind the query
shape, scope, definition version, and access digest, so a cursor cannot be replayed after authority changes.

Secret fields remain encrypted envelopes and never enter predicates, full-text indexes, history plaintext,
exports, reports, or audit payloads. Restricted fields may appear as explicit redaction markers only where the
usage policy allows the surrounding record to be shown.

## Organizations, workspaces, and memberships

Site ownership and organizational authority are separate dimensions. An organization contains workspaces;
memberships bind a subject to an organization and optionally a workspace with an optimistic version, policy
generation, status, and role assignments. The context picker lists only current memberships from the membership
directory. Selecting one rotates the session identifier, opaque token, and CSRF secret.

Every use rechecks the persisted membership, version, policy generation, site, organization, workspace, and
principal security epoch. Mutations lock the membership row for the transaction. Suspension, removal, role or
policy changes, trust changes, password changes, and emergency credential revocation therefore invalidate stale
sessions and tokens instead of waiting for expiry.

## Separation of duties and approvals

SoD rules are generic action/resource rules. They may require a quorum, one exact approver role, distinct actors,
requester and approver separation, an expiry, and fresh multi-factor proof. Approval requests bind an immutable
digest of:

- site, organization, workspace, requester, requester authority, action, and resource identity;
- resource version and canonical operation payload;
- rule version, required quorum and roles, creation time, and expiry.

Votes cannot change the request, count twice, or be cast by the requester when maker-checker separation applies.
Approver eligibility uses the frozen request rule version, role,
distinct-actor requirement, and scope, then requires that exact rule version to remain live. Rejection,
cancellation, revocation, expiry, resource-version change, context change, and authority change fail closed.
Execution locks and spends one approved request exactly once. The requester also presents a fresh step-up proof
bound to the exact action and current rotated session.

## Step-up authentication

Kumwe implements RFC 6238 TOTP with a 30-second period, six digits, SHA-1 authenticator compatibility, and a
one-step verification window. Enrollment secrets are generated from the operating-system CSPRNG, encrypted at
rest with a separately derived sodium key, and disclosed once. Ten high-entropy recovery codes are separately
keyed and stored only as digests.

Enrollment expires, code acceptance is replay fenced by time step, recovery codes are single use, and attempts
are rate limited by actor and trusted-proxy-resolved source. A successful challenge atomically rotates the session
and CSRF secret and persists a short-lived nonce proof bound to actor, replacement session, site, organization,
workspace, purpose, security epoch, method, and expiry. Proof consumption checks every binding and marks the nonce
spent. A `step_up_at` timestamp alone is never authorization evidence.

Portal and administrator delivery compose separate providers with concrete portal and administrator session
rotators; no global rotator alias can accidentally rotate the other surface. Both may verify the same canonical
subject credential, but every proof remains bound to its replacement surface session.

The master application secret is not used directly. The frozen HKDF labels are
`kumwe-step-up-encryption-v1`, `kumwe-step-up-recovery-v1`, `kumwe-step-up-throttle-v1`, and
`kumwe-portal-session-binding-v1`. Changing a label makes existing material unreadable by design. Rotate the
installation secret only with the documented credential and session invalidation procedure.

## Scoped tokens and delegation

API, CLI, and MCP tokens carry a closed audience, purpose, capability subset, site, optional organization and
workspace, membership version, policy generation, security epoch, family, and bounded delegation depth. The
verifier re-resolves the subject and membership and rejects a disabled subject, stale epoch, stale membership,
wrong audience or purpose, revoked family, expired token, or unsupported contributed capability.

Issuance and rotation may only reduce the caller's effective capabilities and scope. Delegation cannot broaden
site, organization, workspace, record authority, lifetime, audience, purpose, or depth. Token secrets are shown
once and stored as digests. Use emergency family or subject revocation for suspected compromise.

## Extension security contributions

An extension declares capabilities and resource policies in its signed manifest. Registration verifies trust,
owner, checksum, target type, scope, and references before any portal or business contribution becomes visible.
Lifecycle synchronization is transactional and records declarations in owner/FK-backed tables. Conditional
business-record ABAC policies remain in their separate policy table and cannot be confused with manifest
declarations.

Activation order is capability and resource policy, then business/portal definitions, then routes. Removal is the
reverse. A disabled, uninstalled, replaced, or untrusted owner cannot retain an executable capability, policy,
route, template, or navigation item. Core and extensions use the same registries and enforcement gateways.

## Audit, incident response, and verification

Security administration, membership and role changes, policy and SoD lifecycle, approval votes and consumption,
step-up enrollment/challenges/recovery, token lifecycle, trust changes, authorization denial, and record mutations
produce redacted, correlated audit events. Never log passwords, TOTP secrets, codes, session or token plaintext,
policy-sensitive field values, or encrypted-field plaintext.

For suspected compromise, suspend the subject or membership, increment the security epoch, revoke token families
and sessions, disable affected extension owners, and preserve audit and approval evidence. See
[incident response](operations/incident-response.md), [backup and restore](operations/backup-restore.md), and
[portal security](portal.md).

Release verification runs unit, integration, functional, browser, architecture, static-analysis, migration, and
database-matrix gates. Policy tests must prove default denial, deny precedence, attribute bounds, field usage
isolation, pre-pagination SQL enforcement, non-enumeration, stale membership/token rejection, approval replay
rejection, TOTP/recovery replay rejection, and extension trust/lifecycle removal.
