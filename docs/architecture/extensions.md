# Extension and event architecture

## Package boundary

An extension is a versioned, checksummed package with a `kumwe.json` manifest. The manifest declares its identifier, type, compatibility, service provider, dependencies, routes, events, configuration, assets, and migrations. Packages are staged outside the public web root, inspected, signature-checked according to policy, migrated, and activated through a database-authoritative runtime publication.

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

Providers register services through a restricted Joomla DI adapter. After every active provider has registered services, the runtime runs a separate typed contribution phase with a registrar bound to the signed publication's extension identifier. The registrar reconciles concrete capability, administrator workspace/navigation, route, and view definitions against the inspected schema-2 manifest before boot or route compilation. It closes after that phase; delivery handlers and templates cannot mutate registries.

ContainerFactory remains the composition root. It creates one registry family, sends core navigation through the same permission-aware path, and supplies only explicitly allowed application services to extension containers. Registries use typed definition objects and independent register/remove/inventory behavior, so another registry family can be introduced without a central callback switch.

Contributed routes are compiled under `/administrator/extensions/{vendor}/{name}`, receive the normal administrator session/capability pipeline, add CSRF enforcement for mutations, and wrap execution in live trust enforcement. Views are resolved only through the contributor's registered name and isolated Twig namespace. Duplicate identifiers, route method/path collisions, missing owned references, and provider/manifest drift fail closed.

Runtime extensions attach typed Joomla Event listeners during boot and may retain legacy namespaced routes during route compilation for schema-1 compatibility. Events describe completed facts or explicit lifecycle decisions; they are not an invisible replacement for application-service calls.

Event names and payload objects are public extension API. Document whether listeners may stop propagation, whether failure aborts the transaction, and whether delivery occurs before or after commit. Side effects that may be slow or retried should enqueue a versioned job or consume an outbox event rather than execute during the web transaction.

## Persistence

Extensions use Doctrine DBAL or an ORM contained behind their own repository interfaces. They must use the configured database connection and pass MariaDB, MySQL, and PostgreSQL compatibility tests. Core tables are not an extension API. An extension owns its tables, migration history, cleanup policy, and downgrade compatibility.

## Compatibility promise

Kumwe treats extension manifests, the contribution SPI version, typed definition and provider interfaces, typed events, service IDs explicitly documented for extensions, capability names, API schemas, and migration contracts as versioned interfaces. Schema-1 packages continue to load but cannot opt into typed shell contributions without a schema-2 manifest. Internal controller classes, registry implementations, template implementation details, and raw database tables are not stable APIs.

Recovery construction uses the same core contribution path but never evaluates the signed extension publication, instantiates providers, or adds extension template namespaces. Runtime generations that are stale, altered, disabled, uninstalled, quarantined, or no longer trusted cannot expose executable contributions.

Security and portal definitions use the same owner-aware contribution lifecycle as every other extension
surface. The live capability/resource-policy registries are shared by core and extensions; persisted declarations
are diagnostic and lifecycle evidence, not a second enforcement catalog. Portal contribution registries retain
owner and trust metadata and recheck it when a handler renders or executes, so a long-running process cannot keep
serving a revoked owner.

See [Extension development](../extensions.md) for package construction and lifecycle commands.
