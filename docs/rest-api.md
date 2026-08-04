# REST API

The v1 API is rooted at `/api/v1`. Its complete machine-readable contract is [api/openapi/kumwe-v1.json](../api/openapi/kumwe-v1.json).

## Authentication and capabilities

Send the opaque token returned by `token:create`:

```http
Authorization: Bearer TOKEN
```

Routes independently require `content.read`, `content.create`, `content.update`, `content.publish`, or `content.delete`. Tokens are stored as SHA-256 digests, can expire, and can be revoked in PostgreSQL.

## Content endpoints

| Method | Path | Capability | Purpose |
|---|---|---|---|
| `GET` | `/api/v1/content` | `content.read` | List content |
| `POST` | `/api/v1/content` | `content.create` | Create a draft |
| `GET` | `/api/v1/content/{id}` | `content.read` | Read one record and ETag |
| `PATCH` | `/api/v1/content/{id}` | `content.update` | Update supplied fields |
| `DELETE` | `/api/v1/content/{id}` | `content.delete` | Move to trash |
| `POST` | `/api/v1/content/{id}/transition` | `content.publish` | Change workflow state |
| `POST` | `/api/v1/content/{id}/restore` | `content.delete` | Restore from trash |

Every mutation requires a unique `Idempotency-Key`. Kumwe stores the response for 24 hours: an identical retry receives that response with `Idempotency-Replayed: true`; reuse for different request data is rejected. Updates, transitions, trash, and restore also require the latest `ETag` in `If-Match`.

```bash
curl --request POST https://cms.example.org/api/v1/content \
  --header "Authorization: Bearer $KUMWE_TOKEN" \
  --header 'Content-Type: application/json' \
  --header 'Idempotency-Key: page-create-20260804-001' \
  --data '{"title":"About us","slug":"about-us","data":{"body":"<p>About our team.</p>"}}'
```

Store the returned `ETag`, then publish through valid workflow transitions:

```bash
curl --request POST https://cms.example.org/api/v1/content/CONTENT_ID/transition \
  --header "Authorization: Bearer $KUMWE_TOKEN" \
  --header 'Content-Type: application/json' \
  --header 'Idempotency-Key: page-review-20260804-001' \
  --header 'If-Match: "v1"' \
  --data '{"status":"review"}'
```

Problem responses use `application/problem+json`. Expect `401` for invalid authentication, `403` for missing capability, `404` for missing content, `409` for an operation already in progress, `412` for a stale ETag, and `422` for validation or retry-key reuse.
