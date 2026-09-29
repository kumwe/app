# Separate record adapter

`record-adapter.php` is a small PHP 8.5 CLI client for the published business-record REST API. Deploy it
in a separate service, with PHP/cURL and a trusted CA bundle, to report a result without loading an App
container or an extension provider. It can read or conditionally patch one record. It is not a payment
product and does not handle cardholder data.

The signed asset-inspection provider never starts or loads this script. Keeping a copy in the example
archive is authoring evidence, not automatic deployment or execution authority. Copy only this script
into the external service. Supply an explicit HTTPS origin and a short-lived, site/audience/membership-
scoped API token issued through the normal host administration contract. Grant only the required record
operations and field/row policy. The token cannot grant itself more authority. Do not give this process
App source mounts, database/Redis credentials, APP_SECRET, signing keys or a host Composer autoloader.
An operator must enforce separate identities, filesystem mounts and network egress in the deployment;
clearing environment variables alone is not isolation.

Pass one protected JSON document through stdin; credentials never appear in command arguments:

```bash
php record-adapter.php https://kumwe.example < /run/secrets/adapter-request.json
```

A read document has exactly these members:

```json
{
  "operation": "read",
  "token": "REPLACE_WITH_SCOPED_API_TOKEN",
  "site": "default",
  "definition": "kumwe.asset-inspection-example.inspection",
  "record": "0191574f-f0b8-7bf3-a9aa-91c6b8244e11"
}
```

A successful read emits `status`, `etag`, and the host's disclosure-filtered `document`. To update,
change `operation` to `update` and add `etag` (the exact strong tag from that read), `idempotency_key`
(a stable operation identity), and `values` (the permitted field patch). Use a protected file mode,
for example 0600, and let the external service's secret manager supply the token.

Persist the original operation key, ETag and values before sending. After an ambiguous transport failure,
reconcile through the host or repeat that exact operation. Do not fetch a new ETag or mint a new key to
retry a stale or uncertain write. The client performs no automatic retry. Core owns version checks,
idempotency, row/field policy, revisions, events and audit.

The input limit is 32 KiB and 16 JSON levels; updates carry at most 64 fields. Input EOF is required
within five seconds. Response bodies are limited to 64 KiB and headers to 16 KiB. Connection and total
HTTP timeouts are two/five seconds. No redirects, ambient proxy selection, destination in the request,
or arbitrary HTTP paths/methods are supported. HTTPS peer/host verification remains enabled. The explicit
`--allow-loopback-http` test flag allows only `http://127.0.0.1:PORT`; it does not permit remote cleartext.

Exit 0 means a valid 2xx response. Exit 1 emits only the host HTTP status for a refusal or redirect,
without the error body or location. Exit 2 emits one constant diagnostic for invalid input or a bounded
transport failure. Treat process output as potentially sensitive record data; never publish it blindly.

`RemoteRecordAdapterIntegrationTest` launches this client with an empty environment and PHP/cURL alone,
against the real App HTTP front controller. It proves bearer/site/audience refusal, token narrowing and
revocation, secret-field omission, an exact idempotent replay and a stale write refusal. Separate hostile
HTTP peers prove redirects and oversized response bodies/headers are refused. Those peer tests are transport
adversarial fixtures; business behavior is verified against the real database-backed host.

This client does not execute arbitrary signed extension PHP and does not satisfy GM-SUP-05's OS authority
boundary. See `docs/extension-contract/point-five-beta-qualification.md` for the exact residual.
