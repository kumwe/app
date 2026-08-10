# Generated business surfaces

Published business definitions are executable application metadata. Once a definition has an active matching
relational installation, Kumwe derives its administrator workspace, explicitly enabled portal workspace, REST
contract, CLI operations, and bounded MCP tools without definition-specific core handlers.

## One application boundary

Every adapter uses the same delivery-neutral contracts:

| Contract | Responsibility |
|---|---|
| `BusinessSurfaceCatalog` | Resolve active definitions and omit every entity, field, view, relation, or action the current context cannot use |
| `BusinessSurfaceService` | Map generated UI and MCP operations onto `BusinessRecordService` |
| `BusinessRecordQueryFactory` | Decode the closed filter/search/sort/projection grammar into the bounded query AST |
| `BusinessFormInputMapper` | Accept only fields in the authorized form schema and bound nested values |
| `BusinessRecordProjector` | Omit withheld handles and internal keys recursively while preserving exact values |
| `BusinessOperationStatusService` | Return a result only to its exact actor, scope, policy, and definition context |
| `GeneratedBusinessActionStepUp` | Verify, elevate, and dispatch one exact-purpose high-impact browser action atomically |

`BusinessRecordService` remains the sole transaction boundary for record behavior. Delivery code does not query a
generated table, evaluate definition expressions, compile policy predicates, create audit events, or own a second
record idempotency ledger. The service performs authorization, validation, optimistic comparison-and-swap,
relations, workflow, approval consumption, revision, audit, and idempotency atomically.

## Exposure and omission

Administrator exposure controls generated administrator navigation. API, CLI, and MCP remain governed by their
exact capabilities and record policies. Portal exposure is different: `portal_exposure` must be true and
`portal_operations` must contain the exact operation. The allow-list defaults to empty and has distinct entries for
browse, read, create, update, archive, delete, restore, history, relation, reorder, action, approval, report, export,
and status. A portal view or action flag never enables another mutation.

Policy denial is omission, not a redaction marker at a delivery boundary. A denied handle is absent from values,
forms, list columns, query choices, relations, aggregates, revision changed-field lists, validation extensions,
CLI/MCP output, OpenAPI properties and required arrays, examples, errors, and counts. Missing and unauthorized
record, approval, relation target, and operation identities use the same non-enumerating result. Internal storage
keys, policy predicates/digests, actor IDs, integrity digests, and relation target keys are not published.

## Generated browser experience

The administrator mounts generated workspaces below `/administrator/business`; portal workspaces live below
`/portal/business`. Both are server-rendered Twig applications over the same controller and surface service. They
provide discovery, cursor lists, detail, create/update, relationship and ordered-line controls, actions and
approval requests, history, reports/exports, confirmations, bounded bulk work, and operation status.

Field presentation is selected by exact field type and context. Built-ins keep decimal, money, and quantity as
canonical strings; render temporal and boolean semantics; never prefill a secret; keep computed/server/read-only
values out of input; and give composites graphical controls. Extension presenters return a bounded escaped model
or allow-listed template identity, never raw HTML or a request.

Essential work uses native links and CSRF-protected forms. Lit only enhances page-local filtering, structured
collection editing, loading feedback, ordered lines, and confirmation. Ordered relationships expose labelled native
position selectors and submit the complete bounded member order; no comma-delimited identity authoring field is
required. Error summaries link to fields, focus moves to the first actionable error, controls have programmatic
labels, and layouts remain usable by keyboard, without JavaScript, and at mobile widths. Archive and delete use a
separate confirmation state and never redirect to a lifecycle-excluded detail. Consuming an approved high-impact
action also requires the original requester to enter a fresh authenticator or recovery code. The browser resolves
the exact action purpose from policy-visible metadata, then the application coordinator verifies and rotates the
surface-specific session and executes approval consumption plus the action in one transaction. Submitted fields
cannot select a proof purpose, actor, session, site, organization, or workspace. Rejected one-attempt credentials,
CSRF tokens, and confirmation flags are removed before the confirmation model is rebuilt.

## Query and request budgets

| Input or result | Bound |
|---|---:|
| JSON body | Route limit, measured from actual stream bytes even without `Content-Length` |
| Page size / sorts | 200 / 5 |
| Filter depth / compiled operations / relation hops | 8 / 64 / 2 |
| Search fields / term | 16 / 256 characters |
| Projected fields / includes / aggregates | 64 / 4 / 16 |
| Set-filter members | 100 |
| Included relation rows | 1,000 per include query, with an overflow sentinel |
| History page / bulk selection | 200 / 50 |
| Protected CLI JSON file | 2 MiB, owner-only, regular, non-symlinked, inode checked |
| OpenAPI definitions / encoded definition metadata | 256 / 4,000,000 bytes |
| Canonical OpenAPI contract | 8,000,000 bytes |

All query keys are allow-listed and field/relation handles resolve through the published definition and current
field-use plan before SQL. Values are bound with their installed DBAL type. Cursors are opaque signed strings bound
to definition, query, scope, and access-plan digest. Includes are batch-loaded once per handle, never per row, and
excessive fan-out is refused instead of silently truncated.

The ordinary browser detail performs no relationship include query. Every disclosed relationship has a fixed
`/{definition}/{record}/relationships/{relationship}` route that hydrates exactly that one handle, so a fifth or
later relationship remains readable, removable, reorderable, and extendable without widening the four-include
query ceiling or making detail work depend on definition width.
Ordinary form and detail requests do not preload one reference catalogue or owned-line form per declaration.
Search runs only on fixed bounded choice routes, and an owned-line editor is built only for the focused relationship.
Bulk-enabled, non-high-impact custom actions reuse the same recursive native command-schema controls as individual
actions and bind the one validated input identically to every atomic child mutation.

Generated discovery also avoids a definition-by-definition lookup: one bounded catalog read is followed by one
installation batch and one exact-version batch for up to 200 definitions, with driver-safe chunks above that size.
The catalog itself fails closed above 4096 heads. A screen's closed available-operation map reuses that one active
definition snapshot and batches every distinct authorized capability into one membership check, policy-generation
lock, and policy-row statement. Query-budget integration tests hold discovery, operation gating, and included record
pages to a statement count that is independent of the tested result count.

## REST and OpenAPI

The generic REST family is rooted at `/api/v1/business`. It exposes definition discovery, browse/search, record
CRUD and lifecycle, actions and approval requests, relations/reorder, history, and operation status. Every request
uses bearer authentication plus exactly one matching `Kumwe-Site`. Retryable writes require an 8–128 character
`Idempotency-Key`; existing-record writes also require one canonical `If-Match: "vN"`. The application ledger owns
record replay, so these routes do not add the generic HTTP ledger. A replay carries the original result, ETag, and
`Idempotency-Replayed: true`.

OpenAPI 3.1 is assembled from the checked-in core contract and the same policy-filtered catalog. Canonical JSON
sorts object keys and set-like declarations, so insertion order and database platform do not change bytes. The
generation binds trusted runtime publication, definition checksums, and authorization fingerprint. Verified
contracts live outside the public tree in checksum-protected, atomically renamed cache envelopes and use strong
ETags. A corrupt or mismatched envelope is never served.

The contract route requires a normal `kumwe-http` API bearer token but has no blanket browse capability. The
contract service filters each emitted operation against the caller's exact policy, so read-only and create-only
actors receive their usable subset without gaining metadata for another operation.

Deterministic component claims are admitted over the complete post-change set before either a site definition
publication or an extension activation commits. Both paths first lock the site's stable authority row, so
definition publication and package lifecycle changes cannot race the normalized namespace under ordinary
transaction isolation. Compilation rejects malformed or over-wide definition lists,
duplicate path/method pairs or operation IDs, incompatible component collisions, unresolved references, invalid
security, owner-prefix escape, core shadowing, unbounded schemas, unsupported formats, oversized canonical bytes,
and metadata the owner does not expose. Admission failure rolls back the candidate and leaves the previous runtime
generation authoritative. OpenAPI reads never execute an extension handler.

Contracts are caller-specific, so a finite activation-time prewarm cannot cover every authorization fingerprint.
On an exact-generation cache miss the request compiler therefore consumes only declarative metadata that has
already crossed publication/activation admission, while retaining its full validation as defense against restored
legacy data or an internal invariant failure. A corrupt envelope is an exact-generation miss and is rebuilt; no
older generation is selected. If current compilation cannot be verified, the endpoint returns a stable no-store
503 with `Retry-After: 30` and no contract bytes. Failure to write a disposable cache entry is logged but does not
discard the already verified current contract returned to that request.

REST failures use `application/problem+json`: malformed transport is 400/422; absent and denied are 404; stale
`If-Match` is 412; uniqueness, reference, idempotency, and action conflicts are 409/422; temporary unavailability
is 503 with `Retry-After`. Details never echo SQL, physical identifiers, policy expressions, secrets, or denied
handles.

## CLI and MCP

`business-record` is the stable JSON CLI. Discovery, schema, list/get, lifecycle, actions, relations/reorder,
history, approvals, reports/exports, and status use the same catalog, query factory, projector, and record service.
Typed values and query/action payloads come from protected files rather than process arguments. Output is always a
stable success or redacted error envelope, with distinct non-zero exit codes for usage, malformed input,
absent/denied, conflict, temporary failure, authorization, and configuration.

REST, CLI, and the generic portal approval handler also share `BusinessApprovalSurfaceService`. It composes the
canonical scoped `ApprovalQueryService` with an exposure-only catalog port: machine business endpoints omit every
non-business or stale binding, while portal retains non-business approvals and requires exact definition-level
`approval` plus action-level portal exposure for business records. The catalog predicate reads one active
definition snapshot without checking `business.record.action`, so checker-only roles remain distinct from makers.
Portal decisions re-fetch through this boundary in the step-up/mutation transaction, and only a redacted portal
model reaches Twig.

MCP publishes a bounded static tool vocabulary, not one tool per definition. Discovery/inspection return filtered
metadata; search/read/history use the shared query/projector and history is capped at 200 revisions per positive
version cursor; writes require a 16–128 character operation ID, the signed
plan token for those exact arguments, and an expected version for existing records. A mutation plan binds
actor/scope, authorization context and approval-request identity, policy and runtime/definition
generations, record version, operation, and canonical redacted payload digest. Execution locks and re-resolves the
bindings. The MCP mutation guard adds credential-bound transport replay; it does not replace record idempotency.
MCP accepts no password and cannot synthesize step-up proof, so approval voting stays on a real step-up surface.
The Session-4 approval fingerprint also includes the authenticated surface and its proof consumer admits only
administrator or portal sessions. Consequently API, CLI, and MCP can create and inspect an exact request but cannot
consume a high-impact binding; those surfaces fail closed and the browser owns the complete approve-and-execute
path. Relaxing that rule would weaken the predecessor replay and session boundary and is deliberately not done here.

## Extension-specific views and actions

An extension may declare an owner-namespaced custom view/action contract in its signed manifest and register the
matching owner-bound handler. A handler receives only a validated immutable query or command plus
`ExecutionContext`, and returns a bounded typed result. It cannot receive a PSR request, CLI/MCP protocol object,
container, DBAL connection, repository, or arbitrary Twig path. Duplicate identifiers, provider/declaration
mismatch, owner escape, unsafe schema, and lifecycle drift fail registration or activation. Disabling the owner
removes handlers before dependent metadata. Custom actions execute inside the canonical record transaction and
exclusive definition fence. Their exact actor, scope, authority, policy, runtime, definition, handler/schema,
record version, approval, and input bindings share the record idempotency ledger; a tagged bounded result is
replayed without re-entering extension code and is returned by operation status only while every binding remains
current.

## Recovery and troubleshooting

- A 404 for a known ID can mean absent, out of scope, disabled, unexposed, or denied. Inspect grants through an
  authorized security surface rather than changing the response.
- A 412 means re-read and retry at the returned version. Reuse an operation ID only for identical intent/input.
- An in-progress 409 means wait or inspect operation status; do not create another ID for the same write.
- A 422 query error means correct or reduce the closed query. Unknown keys, deep filters, broad includes, floats for
  exact values, and inaccessible handles are refused.
- A 503 contract response means the exact current declarative contract could not be verified. Repair the invalid
  definition/runtime invariant and republish; never copy a stale cache into place.
- If extension admission fails, fix the collision/schema diagnostic, re-sign, and activate again. The previous
  trusted generation remains authoritative.

Architecture tests keep adapters out of persistence, require the shared metadata/services, resolve every OpenAPI
reference and operation ID, and police custom-handler boundaries. Functional/browser fixtures exercise identical
exact values, relations, versions, workflow, approvals, history, policy omission, replay, keyboard/mobile/WCAG, and
JavaScript-disabled lifecycle across surfaces.
