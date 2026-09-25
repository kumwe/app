# REST API

The versioned API is rooted at `/api/v1`. Its authoritative machine-readable contract is the current generation named by [api/openapi/generations.json](../api/openapi/generations.json), now [1.1.0](../api/openapi/generations/1.1.0/openapi.json); the retained [1.0.0 artifact](../api/openapi/kumwe-v1.json) is unchanged. Generate clients and validation fixtures from that document rather than scraping this guide.

## Authentication

Send the opaque value returned by the administrator or `token:create`:

```http
Authorization: Bearer TOKEN
Kumwe-Site: corporate
```

Every authenticated request must contain exactly one canonical `Kumwe-Site` header. The value is validated and must exactly match the site recorded on the token; Kumwe never derives this security context from `Host`, `Forwarded`, or `X-Forwarded-*`. Tokens are stored as SHA-256 digests, may expire, and carry an explicit capability set. Disabled or deleted sites fail authentication immediately. Route authorization and workflow authorization both apply. A token with `content.read` cannot publish merely because it can reach the transition endpoint.

Tokens are also bound to an optional organization/workspace and live membership version, policy generation,
subject security epoch, audience, purpose, family, and bounded delegation depth. A valid digest is insufficient if
any binding is stale or the requested route has no concrete matching scope. Issuance and rotation may only reduce
the caller's effective capabilities, scope, lifetime, audience, purpose, and delegation depth. Business security
row and field policy is applied inside the shared application service, not inferred from HTTP input.

## Retry and concurrency contract

Every mutation requires a caller-generated `Idempotency-Key`. Kumwe persists the request digest and completed response for 24 hours. An identical retry returns the stored response with `Idempotency-Replayed: true`; reuse for different request data is rejected.

Updates and deletes of versioned content, menus, and menu items require the latest `ETag` in `If-Match`. User updates carry their positive optimistic `version` in the JSON document. A stale version returns a precondition or conflict response and never overwrites the current state.

## Generated business resources

Published, actively installed business definitions use one stable generic route family. Discovery and every result
are filtered by the same row/field/action plan used by administrator, portal, CLI, and MCP. Definition or field
denial is omission; missing and denied record, relation target, approval, and operation IDs are indistinguishable.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/v1/business/definitions` | Discover visible definitions, fields, views, actions, and relations |
| `GET` | `/api/v1/business/definitions/{definition}` | Inspect one visible generated schema |
| `GET`, `POST` | `/api/v1/business/records/{definition}` | Browse or create records |
| `POST` | `/api/v1/business/records/{definition}/search` | Execute the full bounded read-only query document |
| `POST` | `/api/v1/business/views/{definition}/{view}` | Run a collection-scoped typed custom view |
| `POST` | `/api/v1/business/views/{definition}/{record}/{view}` | Run a record-scoped typed custom view |
| `GET`, `PATCH`, `DELETE` | `/api/v1/business/records/{definition}/{record}` | Read, update, or delete one record |
| `POST` | `.../{record}/archive`, `.../{record}/restore` | Change lifecycle state |
| `GET` | `.../{record}/history` | Read a bounded revision window |
| `POST` | `.../{record}/actions/{action}` | Execute a published policy-visible action |
| `POST` | `.../{record}/actions/{action}/approval` | Request approval for the exact action binding |
| `GET`, `POST` | `.../{record}/relations/{relation}` | Browse or add target relations |
| `DELETE` | `.../{record}/relations/{relation}/{target}` | Remove an exact target relation |
| `PUT` | `.../{record}/relations/{relation}/order` | Replace the ordered target sequence |
| `GET` | `/api/v1/business/approvals`, `/api/v1/business/approvals/{approval}` | List/read scoped redacted approval projections |
| `GET` | `/api/v1/business/operations/{operation}` | Read the caller-bound completed mutation result |
| `GET` | `/api/v1/openapi.json` | Read the deterministic current OpenAPI 3.1 contract |

Browse query parameters cover the small graphical case. `POST .../search` accepts the closed
`GeneratedBusinessQuery` schema for recursive filters, search, sorts, opaque cursor, page size, projections,
includes, lifecycle flags, and aggregates; it is read-only and does not consume an idempotency key. Unknown keys,
floats for exact values, more than five sorts, 64 filter operations, eight levels, two relation hops, 64 fields,
four includes, 16 aggregates, or 200 rows are rejected before repository access. An include is additionally capped
at 1,000 hydrated rows and never performs one query per source record.

Create accepts `{"values": {...}, "record_id": null}`; update accepts `{"values": {...}}`. Exact decimals are JSON
strings. Money and quantity are closed `{amount, currency}` and `{amount, unit}` objects. Retryable mutations require
`Idempotency-Key`; all existing-record mutations require exactly one strong `If-Match: "vN"`. Reads and mutation
results return `ETag`. Identical application replay returns `Idempotency-Replayed: true`; key reuse with changed
input, authority, scope, or definition is refused.

Approval inbox/detail is business-only: unrelated approval resource families and malformed or stale business
bindings are omitted, and exact detail uses the same not-found result. A request remains visible only while its
active definition still declares the bound high-impact action for the API surface; this exposure check does not
require a checker to inherit the maker's action-execution grant. Output omits requester, approver, role, policy,
payload, and binding digest identities. Bearer REST cannot manufacture the fresh session-bound step-up proof
required to approve, reject, or revoke; use the
administrator or portal decision flow. A bearer-originated request cannot later be consumed by REST either: the
canonical binding includes the authenticated surface and proof consumption accepts only administrator or portal
sessions. Supplying an approved request ID therefore re-proves the binding and fails closed; complete high-impact
execution through one browser surface. A typed custom action still carries the same contract-validated `input`
object into approval and attempted execution; changing it invalidates the binding, and reusing the operation key
for another input is rejected. Ordinary actions remain fully available through REST.

The generated contract is canonical OpenAPI 3.1, cached outside the public tree by trusted runtime, definition,
and authorization generation, checksum verified on read, and served with a strong ETag/304. Deterministic component
claims fail a site publication or extension activation before it commits. Caller-specific contracts compile only
pre-admitted declarative metadata on an exact-generation cache miss; no extension handler runs. Corrupt entries are
rebuilt for that exact generation and are never replaced with stale output. An unexpected current-contract
invariant failure returns a no-store 503 with `Retry-After: 30`. See
[Generated business surfaces](architecture/generated-business-surfaces.md) for limits, failure types, cache
recovery, and custom-handler rules.

## Content

| Method | Path | Route capability | Purpose |
|---|---|---|---|
| `GET` | `/api/v1/content` | `content.read` | List content |
| `POST` | `/api/v1/content` | `content.create` | Create a draft |
| `GET` | `/api/v1/content/{id}` | `content.read` | Read one record and ETag |
| `PATCH` | `/api/v1/content/{id}` | `content.update` | Update supplied fields |
| `DELETE` | `/api/v1/content/{id}` | `content.delete` | Move to trash |
| `POST` | `/api/v1/content/{id}/transition` | Action-specific | Submit, review, publish, unpublish, or archive |
| `POST` | `/api/v1/content/{id}/restore` | `content.restore` | Restore archived or trashed content |

Transition authorization follows the same capability map as the administrator. See [Administrator](administration.md#content-and-publishing).

### Content types and workflows

Content model definitions are site-scoped and versioned. Reads require `content.read`; publishing a new definition version requires `content.update`. Definition updates require the current `ETag` in `If-Match`, and all create/update requests require `Idempotency-Key`.

| Method | Path | Purpose |
|---|---|---|
| `GET`, `POST` | `/api/v1/content-types` | List or create content types |
| `GET`, `PATCH` | `/api/v1/content-types/{id}` | Read or publish a new schema version |
| `GET`, `POST` | `/api/v1/workflows` | List or create workflows |
| `GET`, `PATCH` | `/api/v1/workflows/{id}` | Read or publish a new workflow version |

A content-type schema uses the supported JSON Schema subset in the OpenAPI contract. A workflow contains named states, exactly one non-public initial state, public-state flags, and directed transitions with a required capability. Entering a public state always requires `content.publish`; leaving one always requires `content.unpublish`. Existing content remains pinned to its original definition versions. Breaking changes are rejected unless the caller explicitly supplies `allow_breaking: true`; that override publishes a new version and never mutates historical versions.

```bash
curl --request POST https://cms.example.org/api/v1/content \
  --header "Authorization: Bearer $KUMWE_TOKEN" \
  --header 'Kumwe-Site: corporate' \
  --header 'Content-Type: application/json' \
  --header 'Idempotency-Key: page-create-20260804-001' \
  --data '{"content_type":"page","title":"About us","slug":"about-us","data":{"body":"<p>About our team.</p>"}}'
```

Keep the returned `ETag` and use it for the next versioned mutation:

```bash
curl --request POST https://cms.example.org/api/v1/content/CONTENT_ID/transition \
  --header "Authorization: Bearer $KUMWE_TOKEN" \
  --header 'Kumwe-Site: corporate' \
  --header 'Content-Type: application/json' \
  --header 'Idempotency-Key: page-review-20260804-001' \
  --header 'If-Match: "v1"' \
  --data '{"status":"review"}'
```

## Menus and navigation

All navigation routes require `navigation.manage`.

| Method | Path | Purpose |
|---|---|---|
| `GET`, `POST` | `/api/v1/menus` | List or create menus |
| `GET`, `PATCH`, `DELETE` | `/api/v1/menus/{id}` | Read, update, or delete a menu |
| `GET`, `POST` | `/api/v1/menus/{menuId}/items` | List or create items in a menu |
| `GET`, `PATCH`, `DELETE` | `/api/v1/menu-items/{id}` | Read, move/update, or delete an item |

Creating or moving an item validates its parent, rejects cycles and reserved system prefixes, recalculates descendant paths, and applies the change in one transaction. `target_type=content` references a same-site content UUID; `anchor` uses a safe `#fragment`; and `url` accepts a safe root-relative, HTTP(S), or mail destination. A content item's complete menu path is the public canonical URL. Use the ETag returned by resource reads for updates and deletes.

## Users, groups, grants, and tokens

All identity administration routes require `users.manage`.

| Method | Path | Purpose |
|---|---|---|
| `GET`, `POST` | `/api/v1/users` | List or create users |
| `PATCH` | `/api/v1/users/{id}` | Update a user and status |
| `GET`, `POST` | `/api/v1/roles` | List groups/capabilities or create a group |
| `PUT`, `DELETE` | `/api/v1/users/{id}/roles/{roleId}` | Assign or revoke group membership |
| `POST` | `/api/v1/roles/{id}/grants` | Add a scoped capability grant |
| `DELETE` | `/api/v1/grants/{grantId}` | Revoke a grant |
| `GET`, `POST` | `/api/v1/tokens` | List token metadata or issue a capability-scoped token |
| `DELETE` | `/api/v1/tokens/{tokenId}` | Revoke a token immediately |

The token creation response contains the plaintext token once. Lists never contain token material. Do not log or
retry the creation response through an intermediary that records bodies.

Resetting another account's password, retiring its second factors and ending its sessions are not available over
REST. The administrator access screen demands a payload-bound human step-up proof for each, and no API token can
carry one; `AccessControlService` refuses them to any human context without a consumed proof. Use the access screen,
or the host-level `bin/kumwe user:recover-credentials` break-glass, and revoke a peer's tokens with
`DELETE /api/v1/users/{id}/tokens`. The access screen also steps up before the operations listed above; that the
v1 REST contract does not is recorded as an assurance gap in the
[parity inventory](machine-contract/README.md#browser-to-machine-parity-inventory).

## Plan previews

`POST /api/v1/plans` validates a non-executable change plan for approved review workflows. It requires `content.read` and an idempotency key. Applying state changes still uses the corresponding content, navigation, or identity endpoint.

## Automation

Routes under `/api/v1/schedules` and `/api/v1/jobs` require `automation.manage`. Integrations can list/create/read/enable/disable/delete schedules, list recent jobs, retry dead jobs, and cancel pending jobs. Schedule updates and deletes use `If-Match`; all mutations use `Idempotency-Key`. See [Workers and scheduler](automation.md#rest-integration) for the route table.

## Site settings

Both settings routes require `settings.manage`.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/v1/settings` | Read browser-managed site settings |
| `PUT` | `/api/v1/settings` | Validate and replace site settings |

The `PUT` request requires `Idempotency-Key` and identifies the homepage with `homepage_content_id`, a stable Page UUID rather than a slug. Its `presentation` object manages the global logo and footer, primary menu handle, active reusable color scheme, complete validated scheme list, button treatment and shape, and header treatment. The OpenAPI `SitePresentation`, `PresentationScheme`, and `PresentationColors` schemas are the authoritative wire contract. Infrastructure variables and secrets are deliberately absent from this resource. Presentation and search-indexing changes take effect without restarting containers.

## Extensions

All extension routes require `extensions.manage`.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/v1/extensions` | List installed extensions and lifecycle state |
| `POST` | `/api/v1/extensions` | Install one package, disabled, from its ZIP archive |
| `POST` | `/api/v1/extensions/{vendor}/{name}/activate` | Activate an installed extension |
| `POST` | `/api/v1/extensions/{vendor}/{name}/disable` | Disable an active extension |
| `DELETE` | `/api/v1/extensions/{vendor}/{name}` | Uninstall an extension and run its lifecycle cleanup |

Every extension mutation requires `Idempotency-Key`. An install sends the package ZIP itself as the
`application/octet-stream` body, never a server path, with the optional `key_id` and `signature` query pair the
extensions screen's form carries; both or neither must be present. The archive is staged privately and removed
whichever way the install goes, and `ExtensionManager::install()` applies the same trust rules the screen and
`bin/kumwe extension:install` apply. The response is `201` with the registry row as the collection lists it. An
unreadable, invalid or untrusted package is a `422`, as the screen reports it. The install changes the extension
runtime, so the serving process drains and answers `503` with `Retry-After` until it has reloaded. MCP offers no
install tool: adding executable code stays outside its authority boundary.

## Studio authoring

Studio's contextual Content authoring operations are available to bearer tokens holding `content.read`; the
application additionally requires `studio.mode.hybrid` and Content create or update authority, exactly as the
administrator editor does. See [machine contracts](machine-contract/README.md#studio-authoring-for-machine-callers)
for the authority model.

| Method | Path | Purpose | `Idempotency-Key` |
|---|---|---|---|
| `POST` | `/api/v1/studio/authoring/sessions` | Open a credential-bound session for `{"intent":"create"\|"edit", "content_id", "content_type_id", "content_type_version"}` | — |
| `POST` | `/api/v1/studio/authoring/resolve-target` | Resolve the declared target and start sources | — |
| `POST` | `/api/v1/studio/authoring/list-types` | Page reusable Content types | — |
| `POST` | `/api/v1/studio/authoring/start` | Start from `existing`, `from-type` or `blank` | required |
| `POST` | `/api/v1/studio/authoring/plan-save` | Plan one save outcome against the expected coordinates | — |
| `POST` | `/api/v1/studio/authoring/save-item` | Commit the item an accepted plan authorizes | required |
| `POST` | `/api/v1/studio/authoring/save-as-new-type` | Create a reusable type from the design | required |
| `POST` | `/api/v1/studio/authoring/save-new-type-version` | Publish a successor type version | required |

An operation body is `{"session": "...", "session_generation": "...", "argument": {...}}` with an optional
`locale`. `argument` is exactly the pinned Studio argument for that operation; the result is
`{"operation", "replayed", "value"}` where `value` is the canonical Studio result. The expected revision is the
`expected` coordinates a save intent carries and the plan identity a save references; stale coordinates are refused
as `studio-authoring-conflict` (409) with `studio.authoring/expected-mismatch`. The key becomes the Studio host's
replay key: an identical retry returns the stored result with `Idempotency-Replayed: true`, and a reused key with
other input is `studio-authoring-idempotency-key-reused` (422). Refusals use the `studio-authoring-*` problem types
and carry `studio_category`, `studio_diagnostics` and, for conflicts, `studio_revision`.

These operations are described by REST generation 1.1.0 (`api/openapi/generations/1.1.0/openapi.json`), which the
runtime serves as the current contract; the retained 1.0.0 artifact is unchanged.

## Studio compositions

A Content type version's Blueprint composition is read and provisioned exactly as the administrator composition
screen does it. Both routes require `content.read` and `studio.mode.blueprint`. See
[machine contracts](machine-contract/README.md#blueprint-composition-for-machine-callers).

| Method | Path | Purpose | `Idempotency-Key` |
|---|---|---|---|
| `GET` | `/api/v1/content-types/{id}/versions/{version}/composition` | Read the model, binding and Blueprint head | — |
| `POST` | `/api/v1/content-types/{id}/versions/{version}/composition` | Provision the empty Blueprint draft, or answer the bound one | required |
| `POST` | `/api/v1/studio/composition/sessions` | Open a Blueprint session for `{"content_type_id", "content_type_version", "mode"}` | — |
| `POST` | `/api/v1/studio/composition/load` | Load the Blueprint, or a historical revision | — |
| `POST` | `/api/v1/studio/composition/dependencies` | List the dependencies the revision locks | — |
| `POST` | `/api/v1/studio/composition/save` | Save one draft revision | required |
| `POST` | `/api/v1/studio/composition/publish` | Publish the draft (`content.publish`) | required |
| `POST` | `/api/v1/studio/composition/unpublish` | Return it to draft (`content.unpublish`) | required |

A composition that was never provisioned, or a model the caller cannot read, is `studio-composition-not-found`
(404). A Blueprint locked to another published theme is `studio-composition-theme-mismatch` (409). An artifact
operation body is `{"session", "session_generation", "argument", "expected_revision"?, "locale"?}`. `argument` is the
artifact reference `{"id", "version"}`, or for a save the complete Blueprint document, and each mutation names the
`expected_revision` it replaces. The result is `{"operation", "replayed", "value", "revision"}`. Refusals use the
`studio-authoring-*` problem types, and a stale revision is `studio-authoring-conflict` (409).

## Business schema recovery evidence

`POST /api/v1/business-schema-plans/{planId}/recovery-evidence` (`business.schema.recover`, `Idempotency-Key`) files
a restore drill as the evidence a destructive plan's approval cites. The body carries the confirmed `proofs`
(`clean_target_restore`, `blueprint_checksum_verified`, `typed_command_verified`,
`record_revision_audit_checksums_verified`), `backup_manifest_checksum`, `backup_created_at`, `verified_at`,
`drill_reference`, `client_version`, `restore_target_reference` and `current_password`. The application binds the
evidence to the plan's source schema and stamps the live database driver, server version and release, exactly as the
schema screen does. The response is `201` with the stored evidence, whose `id` an approval sends as
`recovery_evidence_id`. The purge plan and a high-impact approval re-prove `current_password` the same way.

## Browser-to-machine parity

Every administrator and portal browser operation has a REST equivalent, or is recorded with a reason in
`docs/machine-contract/browser-machine-parity.json`. `composer machine:parity` fails the quality lane when a browser
route has no entry or an entry names an operation id this contract does not declare. See
[the parity inventory](machine-contract/README.md#browser-to-machine-parity-inventory).

## Errors and observability

Failures use `application/problem+json` and include the request correlation identifier. Common responses are `400` for malformed input, `401` for authentication failure, `403` for a missing capability, `404` for an absent resource, `409` for in-progress/retry conflicts, `412` for stale ETags, `413` for an oversized request, and `422` for validation failure.

Clients should treat undocumented response fields as unstable, honor `Retry-After` when present, and never retry non-idempotent requests without the same idempotency key and body.
