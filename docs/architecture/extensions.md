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

Providers register services through Joomla DI. Runtime extensions attach typed Joomla Event listeners during boot and register namespaced Mezzio routes during route compilation. Events describe completed facts or explicit lifecycle decisions; they are not an invisible replacement for application-service calls.

Event names and payload objects are public extension API. Document whether listeners may stop propagation, whether failure aborts the transaction, and whether delivery occurs before or after commit. Side effects that may be slow or retried should enqueue a versioned job or consume an outbox event rather than execute during the web transaction.

## Persistence

Extensions use Doctrine DBAL or an ORM contained behind their own repository interfaces. They must use the configured database connection and pass MariaDB, MySQL, and PostgreSQL compatibility tests. Core tables are not an extension API. An extension owns its tables, migration history, cleanup policy, and downgrade compatibility.

## Compatibility promise

Kumwe treats extension manifests, provider interfaces, typed events, service IDs explicitly documented for extensions, capability names, API schemas, and migration contracts as versioned interfaces. Internal controller classes, template implementation details, and raw database tables are not stable APIs.

See [Extension development](../extensions.md) for package construction and lifecycle commands.
