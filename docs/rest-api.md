# REST API

The versioned API is rooted at `/api/v1`. Its authoritative machine-readable contract is [api/openapi/kumwe-v1.json](../api/openapi/kumwe-v1.json). Generate clients and validation fixtures from that document rather than scraping this guide.

## Authentication

Send the opaque value returned by the administrator or `token:create`:

```http
Authorization: Bearer TOKEN
```

Tokens are stored as SHA-256 digests, may expire, and carry an explicit capability set. Route authorization and workflow authorization both apply. A token with `content.read` cannot publish merely because it can reach the transition endpoint.

## Retry and concurrency contract

Every mutation requires a caller-generated `Idempotency-Key`. Kumwe persists the request digest and completed response for 24 hours. An identical retry returns the stored response with `Idempotency-Replayed: true`; reuse for different request data is rejected.

Updates and deletes of versioned content, menus, and menu items require the latest `ETag` in `If-Match`. User updates carry their positive optimistic `version` in the JSON document. A stale version returns a precondition or conflict response and never overwrites the current state.

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

```bash
curl --request POST https://cms.example.org/api/v1/content \
  --header "Authorization: Bearer $KUMWE_TOKEN" \
  --header 'Content-Type: application/json' \
  --header 'Idempotency-Key: page-create-20260804-001' \
  --data '{"title":"About us","slug":"about-us","data":{"body":"<p>About our team.</p>"}}'
```

Keep the returned `ETag` and use it for the next versioned mutation:

```bash
curl --request POST https://cms.example.org/api/v1/content/CONTENT_ID/transition \
  --header "Authorization: Bearer $KUMWE_TOKEN" \
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

Creating or moving an item validates its parent, rejects cycles, recalculates paths, and applies the change in one transaction. Use the ETag returned by resource reads for updates and deletes.

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

The `PUT` request requires `Idempotency-Key`. Infrastructure variables and secrets are deliberately absent from this resource. Search-indexing changes take effect on public page headers and the dynamic `/robots.txt` response without restarting containers.

## Extensions

All extension routes require `extensions.manage`.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/v1/extensions` | List installed extensions and lifecycle state |
| `POST` | `/api/v1/extensions/{vendor}/{name}/activate` | Activate an installed extension |
| `POST` | `/api/v1/extensions/{vendor}/{name}/disable` | Disable an active extension |
| `DELETE` | `/api/v1/extensions/{vendor}/{name}` | Uninstall an extension and run its lifecycle cleanup |

Every extension mutation requires `Idempotency-Key`. Package installation is intentionally not accepted from a server path supplied over REST: upload a package through the authenticated administrator, or install a trusted local artifact with the host-level CLI. This keeps archive and signature handling on an explicit trusted boundary.

## Errors and observability

Failures use `application/problem+json` and include the request correlation identifier. Common responses are `400` for malformed input, `401` for authentication failure, `403` for a missing capability, `404` for an absent resource, `409` for in-progress/retry conflicts, `412` for stale ETags, `413` for an oversized request, and `422` for validation failure.

Clients should treat undocumented response fields as unstable, honor `Retry-After` when present, and never retry non-idempotent requests without the same idempotency key and body.
