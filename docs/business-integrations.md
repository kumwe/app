# Business integrations and extension SDK

Kumwe manifest schema 4 and contribution SPI 2 are the public contract for durable events, extension-owned
automation, projections, and reports. They extend schema 3; they do not change the meaning or serialized form of
schema 1, 2, or 3 packages. A schema-4 package is trusted in-process code, but every executable contribution still
has to match its signed, owner-scoped declaration before it can enter a runtime generation.

This guide is for extension authors and operators. It covers compatibility and failure semantics that application
code must not invent independently. The example under `examples/extensions/asset-inspection` is deliberately a
platform proof, not a production asset-management or ERP module.

## Start a complete component

The shipped scaffold creates a schema-4 component with a provider, schema and entity definitions, migrations,
policies, administrator and opt-in portal surfaces, events, jobs, a schedule, a projection, a report, tests, and
author documentation. The generated package contains working neutral behavior and no editing markers.

```bash
php bin/kumwe extension:scaffold acme/inspection-example \
  --namespace='Acme\InspectionExample' \
  --target=/absolute/work/inspection-example \
  --label='Inspection example' \
  --version=1.0.0

php bin/kumwe extension:build /absolute/work/inspection-example \
  --output=/absolute/build/inspection-example.zip
php bin/kumwe extension:inspect /absolute/build/inspection-example.zip
php bin/kumwe extension:conformance /absolute/build/inspection-example.zip
php bin/kumwe extension:sign /absolute/build/inspection-example.zip \
  --key-id=acme.release \
  --secret-key-file=/run/secrets/acme-extension-signing-key \
  --output=/absolute/build/inspection-example.signature.json
```

Build the archive twice from the same source and compare its SHA-256 digest before signing it. The detached
signature covers the exact inspected archive. The secret-key file must be a canonical owner-only regular file and
contain a raw, hexadecimal, or canonical base64 Ed25519 seed or secret key. Add only the corresponding public key
to the installation trust store.

The reusable `sdk/extension-conformance` package exposes the code-free archive checks to an extension's own CI.
Lifecycle conformance uses `LifecycleConformanceRunner` with a test-environment implementation of
`LifecycleConformanceAdapter`; an adapter must exercise every gate and must throw instead of silently skipping a
surface. Its `package_safety_and_signing` gate admits the exact base and upgrade archive bytes through the real
trust-store path, including namespace, expiry, revocation, checksum, and signature checks. After installation, the
`definitions` gate reconciles the signed manifest, provider registrations, versioned schema graph, and materialized
runtime inventory; parsing a manifest or instantiating its provider alone is not sufficient.

## Stable extension APIs

The following are versioned extension-facing contracts in Kumwe 2.x:

- manifest schemas 1 through 4 and contribution SPI revisions 1 and 2;
- `ExtensionServiceProvider`, `RuntimeExtension`, `ExtensionContributionProvider`, `ExtensionContainer`, and
  `ExtensionContributionRegistrar`;
- immutable definition and event DTOs in `BusinessIntegration\Domain` and `BusinessReporting\Domain`;
- `DomainEventHandler`, `IntegrationEventHandler`, `IntegrationEventTransport`, `JobHandler`, and
  `ProjectionBuilder`/`ProjectionWriter`;
- explicitly allowlisted application services documented in [Extensions](extensions.md), generated REST/OpenAPI,
  CLI, and MCP contracts, and extension lifecycle/migration contracts;
- the conformance SDK facades and lifecycle adapter.

The compatibility fixtures under `tests/Fixtures/ExtensionApi` pin all four manifest grammars and the declared
interface signatures. A change that removes a method, narrows an accepted manifest, changes a parameter or return
type, removes an enum case, or changes an existing serialized definition requires a new manifest/SPI revision and
an explicit compatibility path. Registry implementations, composition-root wiring, raw database tables,
controllers, Twig internals, and concrete infrastructure classes are not extension APIs.

## Manifest schema 4

Schema 4 requires `contributions.version` 2 and the closed `contributions.integration` object. Every list is
bounded, every object rejects unknown keys, and all contribution identifiers must remain in the package's
namespace. The section contains exactly these lists:

| Key | Declaration | Runtime implementation |
|---|---|---|
| `event_schemas` | Immutable event type, schema version, payload schema, sensitivity | None; data contract only |
| `domain_listeners` | Event type/versions, priority, handler revision | `DomainEventHandler` |
| `consumers` | Event type/versions, idempotency, ordering, queue, retry and sensitivity | `IntegrationEventHandler` |
| `jobs` | Job type, handler revision, payload schema, queue and retry limit | `JobHandler` |
| `queues` | Owner-scoped queue attempts, lease, concurrency, and retention bounds | Platform-enforced queue runtime |
| `schedules` | Cron/timezone, job, payload, queue and optional site | None; materialized schedule |
| `projections` | Exact event versions, sensitivity, fields, key and rebuild batch | `ProjectionBuilder` |
| `reports` | Policy-aware source, parameters, filters, output and row cap | None; safe compiled definition |
| `webhooks` | Event versions, idempotency, queue, retries and sensitivity | `IntegrationEventTransport` |

SPI 2 also refuses declaration-only relationship ordering. A reciprocal one-to-many/many-to-one pair is stored
in the many-to-one record column and therefore cannot carry collection positions. An ordered one-to-many must
omit `inverse`, which makes that side own the portable junction table and its unique source-position index.
Schema 1 through 3 retain their historical parsing contract.

The provider registers definitions and executable implementations during its one owner-bound contribution phase.
Definitions declared but not registered, implementations registered without declarations, mismatched handler
versions, foreign identifiers, missing references, and collisions fail activation. Do not retain the registrar.

The trusted publication generation is the unit of availability. Web, worker, and scheduler processes execute only
the generation they loaded and advertise. An activation, disable, trust change, or replacement creates another
generation; long-running processes stop when stale and are restarted by their supervisor. Disabling withdraws
events, jobs, schedules, reports, UI, REST, CLI, and MCP inventory together while retaining owned data. Reactivation
reconciles the package into a new generation.

Run a bounded drain in deployment and recovery probes with `php bin/kumwe integration:work --once`. Use
`--stream=outbox`, `--stream=process`, or `--stream=all` to select durable work; `--max-items`, `--max-runtime`,
`--lease-seconds`, and `--sleep-ms` bound a supervised loop. Scheduled jobs are materialized from the current
trusted generation when the scheduler reads its store. Execute them with the ordinary
`php bin/kumwe queue:work --queue=OWNER.QUEUE --once`; an integration worker does not replace the queue worker.

## Event contract and compatibility

Every `DomainEvent` and `IntegrationEvent` carries the same envelope identity:

- namespaced event type and positive schema version;
- globally stable UUID event ID and timezone-aware occurred time;
- exactly one actor or system identity;
- site and optional organization scope;
- aggregate/entity type, identity, and positive authoritative version;
- non-empty correlation and causation identifiers;
- `public`, `internal`, `restricted`, or `secret` sensitivity;
- a bounded JSON-object payload validated against the exact declared schema.

An event type describes a completed fact. Use an application service for a command. A synchronous domain listener
runs inside the authoritative transaction, in declared priority/identifier order; throwing aborts the mutation and
its outbox insert. Keep it deterministic and short. Network calls, long work, and retryable side effects belong in
a durable consumer or job.

The integration event is appended to the database outbox inside the same transaction as authoritative state. A
successful commit therefore contains both, and a rollback contains neither. Dispatch later claims the oldest due
row under an expiring lease and exact runtime generation. Settlement checks the worker, lease token, unexpired
lease, and generation. Transient failures receive bounded backoff; permanent failures and exhausted attempt budgets
become operator-visible terminal rows. Retention and explicit replay preserve the original event ID and envelope.

Event schema compatibility is additive:

1. Never change the payload contract or sensitivity of an existing event type/version.
2. Add an optional bounded property under a new schema version when an old consumer can safely ignore it.
3. Add or tighten required fields, change meaning/type, or raise sensitivity only in a new version.
4. Publish old and new versions during the upgrade window. Consumers list every exact version they understand.
5. Deploy consumers that accept the new version before producers emit it. Remove an old version only after the
   outbox retention/replay window and all supported consumer releases have passed.
6. Preserve event type, ID, aggregate version, correlation, and causation across retry, routing, and replay.

There is no implicit “latest compatible” decoding. Unsupported versions and events above a consumer's sensitivity
ceiling are unavailable, not coerced.

## Inbox, retry, ordering, and idempotency

Each durable consumer and outbound adapter has an inbox identity. Receipt and the consumer's durable effect must be
composed transactionally where the effect is local. External effects use the event ID as the remote idempotency
key and record a stable receipt before acknowledging delivery. A handler must tolerate process death after its
effect but before acknowledgement.

Consumers declare one of two behaviors:

- `event_id`: the `(consumer ID, event ID)` receipt is the duplicate key. Independent aggregate versions may be
  processed in arrival order.
- `aggregate_version`: the receipt still deduplicates event IDs, and a per-consumer aggregate checkpoint admits
  version `n` only after `n - 1` completes.

| Case | Durable result | Operator/author action |
|---|---|---|
| Same consumer and event ID arrives again | `duplicate`; handler is not run again | None; this is normal at-least-once delivery |
| Aggregate version is below/equal checkpoint | `duplicate` | Confirm producer identity if it was unexpected |
| Aggregate version is above the next checkpoint | `reordered`/pending; no handler call | Let the missing version arrive or investigate a producer gap |
| Unsupported event version, sensitivity, or absent active handler | `unavailable`; envelope retained | Deploy/activate a compatible consumer, then replay within retention |
| Permanent failure or exhausted attempts | `poison`; the same signed handler revision cannot claim it again | Correct the deterministic cause and activate a different signed handler revision; its first claim resets the bounded attempt budget |
| Handler revision upgrades | Existing completed receipt remains complete; only a poison receipt is reopened | Use a new consumer ID for intentionally new effects; replay is not a migration |

Do not clear inbox rows or advance checkpoints manually. Reusing a consumer ID with new meaning would turn old
receipts into false success, so semantic changes require a new namespaced ID. A poison receipt becomes claimable
with attempts reset only when the active trusted contribution has a different signed `handler_version`; completed
receipts never reopen. Handler revisions may fix the same effect contract, but must preserve its idempotency key and
accepted schema meaning.

A consumer or outbound adapter on a declared queue receives the queue lease by default, and an explicit wider
lease is rejected before a receipt can be claimed. Its durable attempt budget is the smaller of its own signed
limit and the queue limit. A capacity-full or order-blocked receipt is normal backpressure: the enclosing outbox
lease is deferred for a bounded interval with its claim increment reversed, so sustained saturation cannot spend
the event's terminal retry budget.

## Jobs, schedules, and process managers

A contributed job uses the existing durable queue semantics: bounded payload schema, handler type, attempts, lease,
failure classification, and site or installation scope. Schema-4 job payload schemas are also the source of the
administrator form; supported scalar constraints become core widgets. There is no raw arbitrary-payload form.
Validate again in the handler because queued bytes are a persistence boundary.

The active signed queue declaration is executable policy for contributed jobs, event consumers, and outbound
adapters. Enqueue and scheduler dispatch persist an attempt budget no wider than the producer request, contributed
job limit, or queue limit; inbox receipts similarly use the smaller consumer/adapter and queue limit.
`queue:work` and delivery dispatch default to the declared lease and reject an override above it. Every declared
queue claim locks the same durable per-queue row before counting all unexpired job reservations and inbox leases,
so `maximum_in_flight` is one cross-process, cross-delivery ceiling rather than one limit per worker or subsystem.
Undeclared core queues retain the established defaults and are not forced into an extension policy.

Inspect active queue limits, live reservations, terminal rows, and retention backlog, then purge one bounded batch
after the signed retention window, with an automation-management token:

```bash
php bin/kumwe automation queues \
  --site=default \
  --token-file=/run/secrets/operator-token
php bin/kumwe automation purge-queue \
  --queue=acme.inspection-example.priority \
  --limit=100 \
  --site=default \
  --token-file=/run/secrets/operator-token
```

Retention deletes a terminal job, attached failure record, and site-ownership evidence in one locked transaction.
For completed, poison, or unavailable inbox receipts, it instead compacts the old envelope, lease, and failure
detail while retaining `(consumer ID, event ID)`, status, handler revision, attempts, queue, and aggregate identity.
That tombstone remains a duplicate fence, so retention cannot reopen an already completed external effect. The
inventory reports job and delivery pending/in-flight counts, terminal records, purgeable jobs, compactable delivery
receipts, and already compacted receipts separately. Retention never deletes pending work or a live reservation.

Schedules reference an owned declared job and either `default` or an owned queue. Cron text, timezone, payload,
site, and enabled state are signed. Activation materializes the schedule for the exact generation; disable removes
its executable occurrence without deleting the extension's domain data. Scheduler dispatch and worker execution
remain separate, so repeated scheduler ticks and worker retries must be harmless.

Use the generic process manager for multi-step work that spans transactions. A process instance has a type,
correlation, scope, state, status, and expected version. Its durable work items are typed as timer, command, or
compensation requests and carry their process version, due time, retry budget, and lease. Cancellation is an
explicit state transition with actor/note evidence. Compensation is a request for another idempotent action, not a
rollback of a remote system. Kumwe does not promise distributed ACID transactions.

## Capability and policy ownership

An extension may declare capabilities, but activation does not silently delegate them to people. A global
administrator delegation is an explicit production access operation:

```bash
php bin/kumwe access grant \
  --role=ADMINISTRATOR_ROLE_UUID \
  --capability=acme.inspection-example.view \
  --scope-type=global \
  --site=default \
  --token-file=/run/secrets/operator-token
```

The operator token must itself carry `users.manage` and `extensions.manage`. The gateway accepts only an active,
human-delegatable capability owned by the active extension; core/system ceilings and inactive or foreign
capabilities remain denied. A role grant advances the affected administrator security epoch, so reauthenticate and
replace credentials issued under the old epoch before continuing. Never seed a grant directly into the database.

Business-record row and field policies are also operator authority. Schema 4 deliberately has no contribution by
which a provider can persist a `resource_policy` or grant itself access. A package may ship a closed,
machine-readable policy profile for review, but a separate administrator with `business.security.manage` applies
each request through `BusinessSecurityAdministrationService` using a live administrator session and a fresh TOTP
or recovery proof. The self-escalation guard rejects an allow policy when that policy operator also holds the
business-record operation being granted.

The neutral asset-inspection deployment proof keeps its four signed inspection viewer policies separate from
operator-owned provisioning authority. Two distinct policy-only administrators use independent MFA enrollments to
apply five create, five relation, and four additional read policies for the five-definition acceptance graph. The
policies are site-scoped, definition- and operation-specific, expose only the fields needed by that operation, and
do not give either policy administrator any business-record capability.

Model row scope with typed predicates over declared fields and default denial. Give each usage an explicit field
allowlist, including list, detail, filter, sort, aggregate, report, export, audit, MCP, relation, include, and public
reference. A restricted field must be absent from every disallowed projection, not returned as `null`, masked text,
or an empty value. Acceptance should prove both sides of the boundary: a row below or missing the predicate is
denied, an allowed row is visible, and the restricted field stays omitted in reads, reports, and exports.

## Policy-safe reports and projections

A `ProjectionDefinition` names exact source event versions and sensitivity, typed fields, stable key fields,
handler revision, and a bounded rebuild batch. Projection data is derived and disposable. A rebuild reads an
authoritative, sequence-ordered source, writes a replacement generation, and publishes it only after the complete
replay succeeds. Do not read clocks, random values, networks, or mutable unrelated tables in a builder; the same
input sequence must produce the same rows and checksum.

Use the audited operator surface to publish a first generation or reproduce one after recovery:

```bash
php bin/kumwe integration:manage projection-rebuild \
  --projection=acme.inspection-example.activity \
  --site=default \
  --token-file=/run/secrets/operator-token
php bin/kumwe integration:manage projections \
  --site=default \
  --token-file=/run/secrets/operator-token
```

The rebuild result binds the active generation ID, terminal source sequence, event count, rolling source checksum,
and canonical row checksum. Inventory also reports whether the persisted generation still matches the active
definition. Capture those values before a process restart and compare them after restart and clean restore; do not
declare recovery successful merely because a projection table contains rows.

A `ReportDefinition` names one owner-scoped source entity, an explicit capability, administrator/portal visibility,
typed parameters, filters, columns, groups, aggregates, formulas, sorts, drill-downs, and a synchronous row cap.
Design it under these rules:

- select only declared fields and traverse at most one declared relationship; never accept SQL, table names, join
  text, expressions, or extension table identifiers from a request;
- make every source field readable under the report capability and the ordinary record/field policy. A capability
  is necessary but never overrides row scope, organization/site scope, field disclosure, or secret handling;
- apply query-policy predicates before relation expansion, counts, grouping, aggregates, formulas, or export;
- reject a row when a group, aggregate, formula dependency, sort, or drill-down field was withheld. Do not turn a
  hidden field into null/zero or a conditional row because that can reveal it by inference;
- keep parameters typed and bounded. A parameter may choose a declared predicate value, not query structure;
- use cursor/detail drill-down through the generated business read service so authorization is re-evaluated;
- keep synchronous row caps small. Submit larger work to the export queue.

Formula definitions use the closed expression model, never PHP or SQL. CSV output encodes every scalar canonically,
quotes according to RFC 4180 behavior, and neutralizes leading spreadsheet formula characters. HTML adapters
auto-escape the already disclosed values.

Queued exports bind report checksum, canonical parameters, owner, site/organization, requester, capability/policy
snapshot, authority approval fingerprint, creation/expiry, and version. Generation reconstructs a fresh execution
context and rechecks live authority plus the immutable snapshot before querying. Artifact content and metadata are
private, append-only, checksummed, audited, and immutable after readiness. Status and download require the original
authorized owner/context and reject expiry or checksum mismatch. Do not email or publish an artifact path.

Run a supervised worker for the built-in export queue in every deployment that enables report exports:

```bash
php bin/kumwe queue:work --queue=exports --sleep-ms=1000
```

The ordinary `default` worker does not consume this queue. Scale and alert on it independently so large report work
cannot starve installation maintenance or extension-owned queues.

Administrator and portal pages, REST, `business-report`, and MCP all call the same report/export application
services. Report discovery, execution, and export authorize the exact report identifier as a `business_report`
item through that shared path. A report denied for execution is omitted from discovery instead of leaking its
identifier, label, source, parameters, or columns. Do not publish an owner-wide report catalog and defer the
item-policy decision until execution. Portal visibility is opt-in in the signed report and still requires portal
membership and field policy.

## Upgrade and lifecycle design

Treat schema, event, consumer, job, projection, and report changes as one ordered compatibility plan:

1. Add tables/columns/indexes with portable Doctrine schema operations and bounded backfill jobs.
2. Add new event versions and dual-compatible consumers/builders before changing the producer.
3. Publish a new definition/report/projection version; never rewrite a stored version's canonical bytes.
4. Keep migrations forward-only and restart-safe on MariaDB, MySQL, and PostgreSQL. DDL must not assume one
   engine's implicit transaction behavior.
5. Build twice, inspect, run static and lifecycle conformance, sign the exact archive, install disabled, review its
   schema plan, and activate it in a staged deployment.
6. Restart web, worker, and scheduler processes so each materializes the new trusted generation. Verify generation
   freshness before sending work.
7. Disable and reactivate in staging, then perform a clean-target backup/restore and compare data, audit, event,
   inbox, process, projection, export metadata, runtime, and package checksums.

Uninstall behavior is an explicit package policy. Disable first for incident containment. Never delete extension
files or tables from a running process to simulate lifecycle changes.

## Monitoring and failure recovery

Alert on the following application-level signals, separated by site/owner/type where cardinality is bounded:

- outbox pending age/count, dispatch latency, retries, poison/failed totals, expired leases, replay rate, and
  retention backlog;
- inbox unavailable/reordered/poison totals, oldest pending receipt, checkpoint gaps, retries, and expired leases;
- queue age/depth, scheduled-dispatch lag, failed contributed jobs, and runtime-generation stale exits;
- process instances by status/age, overdue timers/commands/compensations, version conflicts, and cancellation rate;
- projection source lag, last successful rebuild/checksum, failed replacement generation, and active version;
- report denials/row-cap rejections, export queue age, generation failures, expiry backlog, download denials, and
  checksum failures;
- extension activation/reconciliation failures and web/worker/scheduler generation mismatch.

Use correlation ID and event/process/artifact ID in logs, never the event payload, parameters, artifact contents,
tokens, secrets, sensitive fields, or high-cardinality labels. Durable database state and audit are authoritative;
Redis is coordination only.

When delivery is stuck:

1. Stop the affected worker/scheduler pool without deleting leases or receipts. Record release, generation,
   database engine, correlation/event IDs, status, attempts, and safe failure classification.
2. Confirm database/readiness and trusted runtime state. Restart stale processes on the currently published
   generation; do not make an old generation current by editing its cache.
3. For `unavailable`, deploy or reactivate an exact-version/sensitivity-compatible owner. For `reordered`, locate
   the missing aggregate version. For `poison`, fix the deterministic cause and publish a compatible/new handler
   revision.
4. Use only an audited application replay/control path. Preserve the original envelope and event ID. Never update
   status, attempts, checkpoints, policy snapshots, or checksums with SQL.
5. If an external side effect may have happened, verify it by the event idempotency key before replay.
6. If policy or authority changed, expect export/report recovery to fail closed; request a new export under current
   authority rather than weakening its snapshot.
7. Back up before invasive recovery. Restore into a clean installation, verify checksums and audit evidence, then
   resume workers gradually while watching age and duplicate metrics.

See [Monitoring and health](operations/monitoring.md), [Backup and restore](operations/backup-restore.md), and
[Incident response](operations/incident-response.md) for deployment-wide controls.

## Required conformance evidence

An extension release is not conformant because its manifest parses. Its CI must prove package safety/signing,
definition and schema planning, install/upgrade/disable/reactivate/uninstall, authorization and field policies,
guarded routes, REST/OpenAPI, CLI, MCP, jobs, events, reports, administrator/portal UI, backup/restore, and the full
MariaDB/MySQL/PostgreSQL matrix. Browser evidence includes responsive rendering, WCAG 2.2 AA checks, and stable
screenshots. The administrator/portal gate therefore uses a real browser and accessibility assertions, not only an
HTTP status. The jobs/events/reports gate must stop and restart controlled worker and scheduler processes while
durable work is pending and prove that they reload the exact trusted generation without duplicating effects.

Kumwe's deployment acceptance builds and signs the neutral asset-inspection proof, installs and activates it,
creates and relates records through independent adapters, processes its event/job/report/export work, restarts web,
worker, and scheduler processes, disables/reactivates the package, and verifies a clean restore with data, checksum,
and audit evidence on every supported database. A scaffolded component must pass the same kit without hand edits.
