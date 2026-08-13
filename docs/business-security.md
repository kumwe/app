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

Generated metadata resolves the same plan before describing a field or operation. A denied handle is omitted from
HTML, JSON, CLI/MCP, OpenAPI, filters, sorts, search fields, relation choices, aggregates, errors, and history
changed-field lists; adapters never serialize the internal redaction sentinel. Operation-status lookup is also
non-enumerating and binds the original actor, site, organization, authority fingerprint, definition generation,
and current record access plan before releasing a stored result.

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

Entity-reference and relationship selectors consume the source operation's exact nested target plan. Create,
update, and owned-line writes reapply that same nested row predicate and public-reference grant before translating
the selected public identity to an internal key, so submitting a forged identifier cannot bypass selector policy.

Secret fields remain encrypted envelopes and never enter predicates, full-text indexes, history plaintext,
exports, reports, or audit payloads. Application-internal withheld values may use a redaction marker, but generated
HTML, REST, OpenAPI, CLI, and MCP projectors omit the denied handle entirely, including metadata and errors.

## Record encryption key lifecycle

A `core.secret` field is stored as an envelope — ciphertext, nonce, key identifier, algorithm — sealed with
XChaCha20-Poly1305 and bound by associated data to its exact site, definition, record, and field. The envelope
records the *name* of the key that sealed it, never the key, which is what makes rotation possible at all.

### The key ring

`SecretKeyRing` holds one active key plus every retired key the deployment still needs. New writes always use the
active key; a read resolves whichever key the stored envelope names, by identifier and never by trial. A key the
ring does not hold fails as `SecretKeyUnavailable` — a distinct condition from a failed authentication, so
"restore the key" and "investigate tampering" are never confused. Removing a key from configuration is how a
revocation is expressed; from the runtime's side, revoked and never-configured are the same fail-closed answer.

Identifiers are versioned names matching `^[A-Za-z0-9][A-Za-z0-9._:-]{0,126}$`. An identifier is never reused for
different key material: a new key is a new identifier, always.

Two key purposes are kept apart. `SecretKeyPurpose::Record` covers durable record envelopes;
`SecretKeyPurpose::MutationPlan` covers the five-minute opaque tokens `BusinessMutationPlanService` issues. They
derive from different labels, carry different identifiers, and are injected as different types (`SecretCipher` and
`MutationPlanCipher`), so a record-key rotation cannot invalidate a live plan token and a plan-key change cannot
touch stored records.

### Provisioning

| Setting | Meaning |
|---|---|
| `RECORD_ENCRYPTION_KEY` | Dedicated record-encryption secret, at least 32 bytes, independent of `APP_SECRET`. |
| `RECORD_ENCRYPTION_KEY_ID` | Identifier new envelopes carry; defaults to `record-encryption-v1`. Requires the key. |
| `RECORD_ENCRYPTION_PREVIOUS_KEYS` | JSON object of retired identifier to retired secret. |
| `RECORD_ENCRYPTION_LEGACY_SECRET` | The *previous* `APP_SECRET`, so `application-secret-v1` survives an application-secret rotation. |

Every one of these, and `APP_SECRET` itself, has a `_FILE` companion (`RECORD_ENCRYPTION_KEY_FILE`,
`APP_SECRET_FILE`, and so on) naming an absolute path to a readable regular file that is not a symbolic link.
Supplying both spellings of one setting is refused at boot rather than resolved by precedence. File-based
provisioning is resolved inside `ConfigurationFactory`, so a bare-metal, systemd, or Nomad deployment gets exactly
what the container entrypoint provides, with no shell wrapper of its own.

Secrets are stretched with HKDF-SHA256 under the purpose's frozen label rather than used as key bytes directly, so
a long passphrase and a base64 blob both yield a uniform key. **The labels are frozen.** Changing one makes every
envelope derived under it unreadable.

### Backward compatibility

Configuring none of these keeps the behaviour an existing installation already has: the key derived from
`APP_SECRET` as `hash_hmac('sha256', 'kumwe:business-record:encryption:v1', APP_SECRET, true)` stays active under
the identifier `application-secret-v1`, and nothing has to be re-encrypted to upgrade. Once
`RECORD_ENCRYPTION_KEY` is configured that key becomes a *retired* key in the ring instead of the active one, so
stored envelopes keep opening while `business-record-rekey` works through them. `application-secret-v1` is a
reserved identifier and cannot be claimed by configuration.

### Rotation procedure

1. Generate new key material (`openssl rand -base64 48`) and place it in `RECORD_ENCRYPTION_KEY_FILE` with a new
   `RECORD_ENCRYPTION_KEY_ID`. Move the identifier and secret it replaces, if any, into
   `RECORD_ENCRYPTION_PREVIOUS_KEYS`.
2. Restart the application. New writes immediately carry the new identifier; existing envelopes are untouched and
   still readable.
3. Re-seal stored envelopes, one bounded batch at a time, per site:

   ```bash
   until bin/kumwe business-record-rekey --site=<site> --token-file=<file> --batch-size=200; do
       [ $? -eq 2 ] || exit 1
   done
   ```

   Exit `0` means nothing is left, `2` means the pass advanced and more remains, `1` means it could not run. The
   `business.record.secret.rekey` job does the same work under the worker; enqueue or schedule it for the duration
   of the rotation and remove the schedule once a pass first reports `"complete": true`.
4. The pass is safe to interrupt and safe to run beside live traffic. Progress lives in the data — a row carrying
   the active identifier is a row that is done — so a killed pass leaves committed work committed and the next
   pass reads exactly what is left. A concurrent ordinary write wins and is counted as `rows_superseded`.
5. **Do not delete the retired key when the pass first reports complete.** Revision snapshots deliberately are not
   re-sealed: a revision row is checksummed over its snapshot and every reader re-derives that checksum, so
   rewriting one would be indistinguishable from tampering. Keep the retired key in
   `RECORD_ENCRYPTION_PREVIOUS_KEYS` until revision history that names it has passed out of retention, then remove
   it and restart. `skipped_installations` in the report names any installation the pass could not fence — a
   disabled or preserved schema — which must be re-activated and rotated before the key is dropped.

Rotating `APP_SECRET` is now independent: set `RECORD_ENCRYPTION_LEGACY_SECRET` to the outgoing value first, so
envelopes still carrying `application-secret-v1` keep opening, and drop it once re-encryption has moved them.

### KMS and HSM adapter contract

Key acquisition is the port `Kumwe\CMS\BusinessRecord\Application\SecretKeyProvider`. The shipped
`KeyRingSecretKeyProvider` is a production-capable default, not a placeholder; an external adapter replaces that
one class and must guarantee:

- **Identifier namespace.** `activeKeyId()` returns a stable versioned name in the alphabet above, never reused
  for different material. It is the only thing the database records about the key.
- **Stability within a process.** `activeKeyId()` and `activeKey()` agree and do not change during one request or
  one job. A provider that rotates underneath a running batch stamps envelopes with an identifier whose bytes no
  longer match.
- **Fail closed.** `keyFor()` raises `SecretKeyUnavailable` for an identifier it cannot produce, including a
  revoked one, and never substitutes another key.
- **No disclosure.** Key material is never logged, printed, or attached to an exception, metric, or trace.
  `SecretKeyMaterial` marks its constructor parameter sensitive and redacts itself from debug output; an adapter
  must not undo that by logging the bytes before wrapping them.
- **Caching and bounded latency.** Every record write and every re-encryption calls `activeKey()`. A remote
  provider caches within the process and bounds its network wait, because a provider that blocks makes record
  writes block. A cache entry is dropped on revocation.
- **Enumeration.** `knownKeyIds()` returns names only. A provider that cannot enumerate returns just the active
  identifier, which honestly says the active key is present rather than claiming no others exist.
- **Audit.** Key use is not audited through this port: the record trail already records the mutation, and
  `business.record.secret.rekeyed` records what a rotation re-sealed. An external provider keeps its own access
  log, and that log is where "who asked for which key, when" is answered.

### No reveal path, by design

There is no authorized decrypt or disclose surface for a stored record secret, and adding one is not an oversight
to be corrected. `core.secret` is a write-only property: values are set and replaced but never presented, queried,
exported, reported, or audited. Nothing in the record runtime decrypts one — re-encryption is the sole decrypting
caller, and it hands the plaintext to the cipher and nowhere else. Because no reveal exists, no compromise of a
session, token, delegation, or field-visibility rule can produce one.

If a future integration genuinely needs stored credentials back, the control that would have to come with it is:
a dedicated non-delegable capability separate from every `business.record.*` action; fresh step-up proof bound to
the exact record and field; an approval under separation of duties for anything beyond a single record; an audit
entry naming actor, record, field, and purpose, written in the same transaction as the disclosure and failing the
disclosure if it cannot be written; a rate limit and a disclosure budget per actor; and a stated retention and
handling policy for the revealed value. Until such a use case exists with all of that, the write-only property is
the stronger control and is what this platform claims.

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

Generated approval adapters add a shared active-surface ceiling after this scoped approval visibility check.
REST and CLI admit only canonical `business_record` action requests. Portal preserves other approval resource
families, but a business request additionally requires the active definition's exact portal approval opt-in and
the bound high-impact action's portal exposure. The predicate deliberately does not require
`business.record.action`: approvers retain maker-checker separation and receive visibility only through request,
approve, or manage authority. Portal decisions re-run the same gate in the mutation transaction before consuming
step-up evidence, and portal presentation omits internal record, actor, digest, role, and raw rule evidence.

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
