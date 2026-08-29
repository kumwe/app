# Extension and event architecture

## Trust posture

**Installing an extension means trusting its publisher with the application process.** Everything below
describes controls on *what gets admitted* and *what stays admitted*. None of them is a runtime boundary
around code that has already been admitted, and none of them is described as one anywhere in this
repository. The supported tier has one name and every surface uses it: **trusted in-process extension
code**.

Say it precisely, because the difference matters when an operator decides whether to install something:

| | |
|---|---|
| **What the boundary is** | A **canonical package boundary**. An extension imports released SDK and library contracts, never `Kumwe\App\` classes. The SDK `ExtensionContainer` exposes only owner-scoped package services and neutral host ports. Signed manifest declarations and their exact executable bindings keep extensions from replacing host services or colliding with another owner's identifiers. |
| **What the boundary is not** | A **sandbox**. Admitted PHP runs inside the request, worker and scheduler processes with the full ambient authority of the runtime user. Curating service resolution constrains what an extension is *handed*; it constrains nothing about what that code can *do* once it is running. |

Signature verification, the trust store, the revocation feed and install-time admission answer **who
published this package and is it still vouched for**. They cannot answer **what may this code do once it
runs**, and no combination of them adds up to that answer. That is why the operator's install decision is
the security decision, and why it is made deliberate — bound to a publisher, a key and a package digest —
rather than incidental.

**Untrusted and marketplace PHP is not supported**, and stays unsupported until an isolated runtime
exists. Third-party logic that has not earned process-level trust belongs out of process, behind the
authenticated outbound-adapter and webhook contracts in
[Business integrations](../business-integrations.md), where it runs in its publisher's own process and
reaches Kumwe only through an authenticated contract.

### Ambient authority an admitted extension inherits

This is the inventory, stated so that an operator can bound it deliberately rather than discover it. Each
row is authority the PHP process already holds, which admitted extension code therefore also holds.

| Authority | What admitted code can reach | Deployment control that bounds it |
|---|---|---|
| Filesystem | Everything the runtime user can read or write: `storage/`, the deployed extension trees, mounted secret files, the application source itself. | Run the application as a dedicated unprivileged user; mount the source read-only; keep secrets out of the container filesystem where the platform offers an alternative; give `storage/` the narrowest ownership that still works. |
| Network | Outbound connections through any PHP stream wrapper or socket, to any host the container can route to. | Default-deny egress at the network layer, with an allowlist of the destinations the installation genuinely needs. |
| Environment | Every variable in the process environment, including database and Redis credentials. | Scope credentials to the least privilege the application needs; rotate on a schedule; prefer a secret store the process reads once at boot over long-lived variables. |
| Database | A second DBAL or PDO connection built from those variables, outside every authorization decision the application makes. | Grant the application account only the rights it uses; keep DDL, backup and administrative rights on separate accounts, as [Operations](../operations/README.md) already requires for the audit-trail triggers. |
| Process | `proc_open`, `exec` and `shell_exec` where the SAPI permits them. | Disable the process functions in `php.ini` for the application SAPI where the deployment does not need them; run without a shell in the image where that is practical. |

None of these is enforced by Kumwe, and this table does not claim otherwise. They are deployment
controls, they belong to the operator, and they are the only things that actually bound an admitted
extension.

## Package boundary

An extension is a versioned, checksummed package with a `kumwe.json` manifest. The manifest declares its identity,
package requirements, provider, declarative contributions, executable binding identifiers, configuration, assets,
and migrations. Packages are staged outside the public web root, inspected, signature-checked according to policy,
migrated, and activated through a database-authoritative runtime publication.

The runtime never scans arbitrary extension directories on each request. Each registry mutation commits an immutable generation, state checksum, publication checksum, versioned signing-key ID, signed payload, executable-tree/asset digests, and retirement intent in the same transaction. The container entrypoint materializes once before the long-lived application, worker, or scheduler starts; request handling never rewrites runtime state. The loader consumes that already verified immutable document without rereading the map. Readiness compares the generation actually loaded by the stable deployment/replica/process identity with the local artifact and current database state; a stale, missing, byte-modified, or untrusted runtime is never ready.

Old extension and asset roots are retired only after a minimum retention interval and after no live process lease reports an older generation. Workers and schedulers heartbeat the generation they actually loaded and exit when it becomes stale; a stale but still-heartbeating process keeps its roots fenced from deletion while the deployment drains it. Interrupted publication and conservatively retained ambiguous-install orphans are reconciled at process startup. Stale acknowledgements, superseded publications, and completed retirement records are garbage-collected in bounded batches.

Public assets are not a static bypass around lifecycle state. Requests resolve a versioned extension asset only while the corresponding release remains active and verified and its signing key remains enabled and unrevoked. Filesystem traversal and symlink components fail closed. Site themes are compiled into the signed publication as explicit site assignments; administrator theme selection remains a separate installation-wide surface.

Runtime publication keys are independent from the application/session secret. A publication names its signing key, and deployments may supply an explicit previous-key set during rotation. Unknown or invalid signatures fail closed and are never papered over by signing a replacement publication. Extension lifecycle operations use a renewable Redis lease with a monotonically increasing database fence so an expired holder cannot commit after a newer operation. Lifecycle event payloads carry that fence as `registry_fence`; durable listeners must persist and compare it before applying side effects from a holder that may have expired.

## Extension types

- **Plugin:** subscribes to typed events or decorates application behavior.
- **Module:** provides a reusable rendered block or position assignment.
- **Template:** supplies public or administrator presentation overrides and assets.
- **Component:** contributes a cohesive capability with services, routes, permissions, and persistence.
- **Package:** installs a compatible set of extensions atomically.
- **Language:** provides locale catalogs and metadata.

## Services and events

The SDK `ExtensionServiceProvider` registers only extension-owned services in its owner-scoped
`ExtensionContainer`. Neutral capabilities supplied by the host are SDK ports, not App classes or service IDs.
Constructor-injected extension code can therefore be developed, conformance-tested, and installed without an App
namespace dependency.

The signed manifest is the sole declaration authority. It carries immutable declarations for administrator and
portal surfaces, business definitions, field presentation, conversions, domain and integration events, jobs,
projections, webhooks, composition, and Studio preview. After service registration, an optional SDK
`ExtensionBindingProvider` receives an owner-scoped `ExtensionBindingRegistrar`. It can attach an executable
implementation only to an exact identifier and kind already present in that manifest. It cannot add, rewrite, or
alias a declaration, and activation fails unless every required executable has exactly one binding.

Bindings use canonical SDK or extracted-library types: field presenters, conversion providers, custom business
handlers, route handler factories, event handlers, job handlers, projection builders, transports, and preview
renderers. Handler callbacks receive typed immutable declaration views and neutral execution context; they do not
repeat signed identifier/type metadata as a second source of truth. Purely declarative contributions need no PHP
binding and are interpreted directly by the host.

App registries remain owner-aware host implementation details. They validate callback inputs and bounded results,
apply authentication, capability, disclosure, transaction, audit, concurrency, and idempotency policy, and remove
declarations and implementations together when an owner leaves the active publication. Contract schemas constrain
data shape; they never replace those application guarantees or make core tables an extension API.

Contributed routes are compiled under `/administrator/extensions/{vendor}/{name}`, receive the normal administrator session/capability pipeline, add CSRF enforcement for mutations, and wrap execution in live trust enforcement. Views are resolved only through the contributor's registered name and isolated Twig namespace. Duplicate identifiers, route method/path collisions, missing owned references, and provider/manifest drift fail closed.

Extensions declare lifecycle, event, job, projection, and route contributions in the signed manifest. After
admission, an owner-scoped SDK binding provider may bind executable implementations only to those exact
declaration identifiers. Event envelopes describe completed facts or explicit lifecycle decisions; they are
not an invisible replacement for application-service calls.

Signed event declaration identifiers and canonical SDK envelope schemas are public package contract. Their delivery
phase, transaction boundary, failure, and retry semantics must be explicit. Side effects that may be slow or
retried should enqueue a versioned job or consume an outbox event rather than execute during the web transaction.

Synchronous domain listeners run inside the authoritative transaction and abort it on failure. The same
transaction appends the versioned integration envelope to the database outbox. Later delivery is leased and
at-least-once; every consumer/outbound adapter therefore owns an inbox identity and declares event-ID or
aggregate-version idempotency. Trusted runtime generation fences claims and settlement, so a stale worker cannot
complete work through an implementation that is no longer published. See
[Business integrations and extension SDK](../business-integrations.md).

## Persistence

Extensions use Doctrine DBAL or an ORM contained behind their own repository interfaces. They must use the configured database connection and pass MariaDB, MySQL, and PostgreSQL compatibility tests. Core tables are not an extension API. An extension owns its tables, migration history, cleanup policy, and downgrade compatibility.

## Canonical author contract

Author packages depend on released `kumwe/extension-sdk`, `kumwe/conversion`, and other explicit library packages,
and use the manifest grammar distributed by the SDK release. Those package namespaces, signed declaration schemas,
neutral ports, capabilities, and migration contracts are the author surface. A contract change ships as a new
library release and manifest grammar; the App does not preserve it with aliases, namespace remapping, or duplicate
types. `Kumwe\App\` classes, App service IDs, internal controllers, registries, templates, and raw database tables
are not author API.

Recovery construction uses the same core contribution path but never evaluates the signed extension publication, instantiates providers, or adds extension template namespaces. Runtime generations that are stale, altered, disabled, uninstalled, quarantined, or no longer trusted cannot expose executable contributions.

Security and portal definitions use the same owner-aware contribution lifecycle as every other extension
surface. The live capability/resource-policy registries are shared by core and extensions; persisted declarations
are diagnostic and lifecycle evidence, not a second enforcement catalog. Portal contribution registries retain
owner and trust metadata and recheck it when a handler renders or executes, so a long-running process cannot keep
serving a revoked owner.

See [Extension development](../extensions.md) for package construction and lifecycle commands.
