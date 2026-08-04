# Extension and event architecture

## Package boundary

An extension is a versioned, checksummed package with a `kumwe.json` manifest. The manifest declares its identifier, type, compatibility, service provider, dependencies, routes, events, configuration, assets, and migrations. Packages are staged outside the public web root, inspected, signature-checked according to policy, migrated, and atomically activated through a compiled runtime map.

The runtime never scans arbitrary extension directories on each request. Activation updates the registry and runtime generation; new requests load the compiled active set. Long-running workers must restart after activation or removal.

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
