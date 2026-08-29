# Asset inspection extensibility proof

This package is a neutral Kumwe 2.0 example, not an ERP module or a production asset-management product.
It exists to exercise the complete signed schema-4/SPI-2 component contract with ordinary business data.

The signed model contains five related entity types: location, asset, inspection, finding, and measurement.
Inspection has ordered finding and measurement collections, the `draft → submitted → verified → closed`
workflow, a stored `risk_score` formula, and a `restricted` `internal_note`. The portal opts in only to
inspection browse/read/report/export and is invisible until an operator grants the package's view capability.

## Runtime proof

Every inspection record mutation already emits `core.business_record.mutated@1` in the authoritative
transaction. This package consumes that real platform path:

- `inspection-mutation-validator` runs synchronously and may abort the record transaction;
- `inspection-mutation-indexer` runs through core outbox/inbox delivery, uses aggregate-version ordering,
  and has an eight-attempt poison budget;
- `review-overdue` is a schema-validated, site-scoped job materialized by the daily UTC schedule;
- `inspection-activity` is disposable derived state rebuilt from the versioned core event stream; and
- `inspection-summary` is a bounded, policy-aware administrator/portal report whose columns exclude the
  restricted note and whose CSV/export path remains core-owned.

The graphical pages expose only bounded process-local counters and non-reversible job digests. Those values
are diagnostics, not durable state. Durable evidence lives in the core outbox, inbox/checkpoint, job/schedule,
projection, report-export, audit, and trusted-generation stores, so a process restart cannot change delivery
semantics.

## Row and field policy

The signed definition gives `internal_note` `restricted` sensitivity and marks it non-searchable,
non-filterable, non-sortable, non-reportable, and non-exportable. It is also conditionally available only when
`raw_score >= 70`. Once the operator applies the signed field rules, core business-record policy omits its value
from disclosed reads and refuses it as a query/report/export input.

The manifest also signs `policies/inspection-viewer.json` as deployment evidence. The provider never parses or
applies it: business-record row and field policy is operator-owned host authority, not an author SPI. Deployment
acceptance validates the signed asset and applies it through the normal step-up protected business-security
administration surface. The extension receives only the already constrained `BusinessRecordReader` port and
cannot grant itself a core capability, widen a query, select a policy profile, or insert policy rows.

Core rejects an allow policy that could affect the calling actor. A separately scoped security operator must
review and apply the profile (or a stricter site-owned replacement); the self-escalation guard is never weakened
for this example.

Granting either contributed capability grants nobody a core business-record operation. Operators still assign
the appropriate core capabilities and apply the signed profile (or a stricter site-owned replacement) through
the normal business-security administration surface.

## Build, inspect, conform, and sign

Run these commands from the Kumwe repository root. The output directory and signing key must already be
private, canonical absolute paths; the builder never overwrites an existing artifact.

```bash
SOURCE="$(pwd)/examples/extensions/asset-inspection"
OUTPUT_ROOT="$(mktemp -d)"
PACKAGE="$OUTPUT_ROOT/asset-inspection-example.zip"
SIGNATURE="$OUTPUT_ROOT/asset-inspection-example.signature.json"

php bin/kumwe extension:build "$SOURCE" --output="$PACKAGE"
php bin/kumwe extension:inspect "$PACKAGE"
php bin/kumwe extension:conformance "$PACKAGE"
php bin/kumwe extension:sign "$PACKAGE" \
  --key-id=example-release-key \
  --secret-key-file=/absolute/private/release.seed \
  --output="$SIGNATURE"
```

`extension:build` produces byte-reproducible stored ZIP entries and re-inspects the result through the
production package safety boundary. `extension:conformance` verifies manifest references, source completeness,
strict PHP syntax, entry order, timestamps, modes, and authoring documentation without loading provider code.
`extension:sign` verifies the archive again and emits a detached public signature document; the protected seed
is never copied into the package.

## Lifecycle verification

Installation records the component disabled. Use the normal authenticated extension commands to activate it,
then verify the following inventory before exercising records:

- five owned business definitions and two owned capabilities;
- one policy-filtered custom-view contract and handler, referenced by the inspection definition for both the
  administrator and portal generated surfaces;
- two KIS 1.0 surfaces, each bound to its administrator or portal navigation, graphical route, template,
  and capability;
- one core-event listener, one durable consumer, one queue, one job, one enabled schedule, one projection, and
  one report; and
- no webhook destination, credential, secret field value, or remote schema in inventory.

Create one location, one asset, and one inspection through the generated business-record surface. Relate the
records, add findings and measurements, reorder both collections, and execute `submit`, `verify`, and `close` in
sequence. Each mutation must commit its outbox row atomically. Running workers across a restart must leave one
completed inbox identity per consumer/aggregate/version and the projection must rebuild to the same checksum.

Disable the package and materialize the next trusted runtime generation. All owned routes, navigation,
handlers, schedules, reports, and executable projections must disappear immediately while authoritative records,
outbox/inbox evidence, job history, audit records, and export artifacts remain intact. Re-enable it to reconcile
the exact signed generation. Uninstall only after taking and restoring the normal installation backup in the
deployment matrix.

## Versioning rules

The consumer accepts only `core.business_record.mutated@1`; a future version must be added explicitly alongside
version-specific handler behavior. Keep the consumer identifier stable to preserve inbox idempotency and advance
`handler_version` when behavior changes. Keep the projection rebuildable and never treat its rows as authority.
Job payload schema changes require a new `schema_version`, and existing queued payloads must retain an executable
compatible handler until their retention window closes.
