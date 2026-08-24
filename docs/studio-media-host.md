# Studio media host adapter

Kumwe implements Studio's optional media port over the existing, site-scoped App Media module. The JSON
host route exposes all seven canonical operations; uploaded bytes use a separate short-lived same-origin
HTTPS `PUT` grant and never cross the JSON envelope.

## Operations and ownership

| Operation | App behavior |
|---|---|
| `authorize-upload` | Validates the exact `{request}` wrapper, filename, type and inclusive size policy; persists an authorized upload and returns a five-minute grant whose plaintext token is never stored. |
| direct upload `PUT` | Requires authenticated administrator identity plus upload token, resource-context key and session generation; re-resolves AP-3 live authority and upload permission before streaming into private staging under the declared limit. |
| `complete-upload` | Claims completion optimistically, verifies size, SRI digest when supplied, detected type and magic bytes, then admits the file through `MediaService::upload()` so App authorization, storage checks and auditing remain authoritative. |
| `abort-upload` | Cancels only active staging; it never deletes an accepted asset. |
| `get` / `list` | Reuses authorized App media reads and returns immutable Studio projections. List cursors are HMAC-authenticated and bound to site and normalized filters. |
| `upload-status` | Projects an accepted App asset's current small identity; an unknown identity is a safe not-found error. |
| `import-external` | Applies the lexical policy, DNS and pinned-fetch controls below before admission through `MediaService::upload()`. |

Studio does not acquire media storage, policy, authorization or publication ownership. The adapter is a
protocol projection over App behavior. It imports no BusinessRecord implementation and does not create a
parallel media store.

All successful lifecycle effects are appended through the platform `AuditRecorder` in the same transaction:
authorization, byte transfer, completion, cancellation and external import. Completion/import retain the
existing `media.upload` asset-admission event as well. Get, list, status and grant replay remain reads. Studio
audit metadata contains only media type, byte counts, lifecycle state, accepted asset IDs and hashed context
coordinates—never a candidate URL/address, filename, private path or grant token.

## External-source security boundary

External candidates are privileged server-side fetch inputs. The adapter therefore applies these stages
in order:

1. Validate the raw candidate with the independent PHP implementation of Studio's default lexical URL
   policy. HTTPS is mandatory; credentials, special-use names, private/link-local/loopback addresses and
   alternate numeric IPv4 spellings are refused before DNS.
2. Resolve A and AAAA records once for the hop. Every answer must be globally routable and the transport
   connects directly to one of those classified addresses while TLS SNI, certificate validation and the
   HTTP Host header remain bound to the normalized hostname. The transport never resolves for itself.
3. Disable automatic redirects. Each of at most three redirect targets repeats lexical validation, DNS
   classification and address pinning.
4. Apply a whole-import time budget, a 32 KiB header budget and the App body-size limit while streaming.
   Ambiguous lengths, unsupported transfer encodings and compressed response bodies fail closed.
5. Require HTTP 200, an allowed declared `Content-Type`, matching fileinfo detection and a supported magic
   signature before a private body can reach the App Media module.

Errors carry only stable categories and diagnostic codes. Candidate URLs, resolved addresses, grant tokens
and private paths are never retained in the application exception or host-error body.

## Deployment contract

- `APP_BASE_URL` must identify the public TLS endpoint Studio can reach. Grant URLs always use HTTPS, even
  when a local development origin is configured without TLS; test direct transfer through the real TLS
  ingress used by the administrator application.
- The normal `APP_MAX_BODY_BYTES` value is both the App Media ceiling and the Studio policy/import quota.
- Writable private roots are `storage/studio-media/uploads` and `storage/studio-media/external`. They are
  created mode 0700; staged files are mode 0600 and are not publicly served.
- Accepted types are `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/avif`, and
  `application/pdf`, matching `FilesystemMediaStorage`.
- The application secret is HKDF-separated into independent Studio cursor and upload-grant keys. Rotating it
  deliberately invalidates grants that have not yet expired; their stored digest prevents re-derivation under
  a mismatched key from producing an apparently usable capability.

## Qualification

PHP tests read and replay all 11 vendored `vectors/media` documents and all 10 `vectors/host/media.*`
documents. The host replay explicitly adapts raw vector arguments to the exact wrappers emitted by
Studio's `http-host-adapter.ts`: `{request}`, `{uploadId}`, `{assetId}`, `{url}`, and `{query}`. Additional
tests cover alternate-address lexical forms, mixed public/private DNS answers, private redirect targets,
response-type disagreement, signature verification, state transitions and relational persistence.

[`docs/qualification/studio-media-security-evidence.json`](qualification/studio-media-security-evidence.json)
registers the lexical SSRF, DNS-pinning/rebinding, redirect and response-verification controls in the same
verification-entry shape used by the qualification programme. Its entries deliberately remain `not_run`, its
candidate is null and its assessment is `not_evaluated`: the future central P7-C runner must execute every
entry against the build-once release candidate, attach immutable run identifiers and include the completed
report in the signed qualification manifest.

The implementation is not Gate B evidence until the ordinary CI matrix, security lane and deployed
artifact checks pass on an immutable commit. Programme status therefore keeps `S-E` and `V2-STU-005` open
while that evidence is pending.
