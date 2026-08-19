# Principles and ownership

## Product boundary

Kumwe is a content-management application and extension host. Its core owns content, publishing workflow, navigation, identity and authorization, site settings, extension lifecycle, presentation, audit records, and durable automation. Delivery adapters expose those capabilities through the administrator, CLI, REST, and MCP.

The browser, CLI, REST handlers, MCP handlers, workers, and extension routes must call the same application services. A delivery adapter may validate and translate input, authenticate a caller, and format a result; it must not duplicate business rules or write application tables directly.

## Dependency direction

Dependencies point inward:

1. Domain code defines state, invariants, and value objects.
2. Application code coordinates use cases, authorization, transactions, and audit records.
3. Infrastructure implements persistence, rendering, packages, clocks, logs, and protocol adapters.
4. Delivery code receives HTTP, CLI, worker, or MCP input.

Domain and application code must not depend on HTTP requests, templates, Doctrine connections, environment variables, or process globals. Infrastructure implementations depend on application-owned interfaces, not the reverse.

This direction is enforced rather than described. [`layers.json`](layers.json) is the machine-readable form of the table above — which namespace belongs to which layer, and which layers each one may depend on — and `composer architecture:policy` resolves every symbol each file under `src/` references and fails on any edge the table forbids. Edges that already pointed the wrong way are recorded in [`dependency-baseline.json`](dependency-baseline.json), each with an owner, the finding that removes it and an expiry; the baseline only ever shrinks, and phase 3 empties it.

## Composition and framework use

`Kumwe\App\Kernel\ContainerFactory` is the single composition root. Joomla DI owns the service container and Joomla Event owns in-process event dispatch. Mezzio supplies the PSR-15 HTTP pipeline, Laminas Diactoros supplies PSR-7 messages, and Twig renders HTML. Framework objects remain at the composition, infrastructure, and delivery boundaries.

Do not add a second service locator, static container, or parallel application pipeline. Constructor injection is the default. An extension provider may resolve dependencies while registering services, but ordinary extension classes should receive their dependencies explicitly.

## Cross-cutting invariants

- Every state mutation is authenticated, authorized, CSRF-protected in the browser, and audited.
- REST mutations are retry-safe through idempotency keys; updates use optimistic versions and ETags.
- Database and filesystem activation steps either complete atomically or leave the previous active state intact.
- User-visible configuration is stored through the settings service. Deployment credentials, database connections, and cryptographic secrets remain outside the database.
- The public web root is `public/`; source, vendor code, extensions, storage, and secrets are never served directly.
- Long-running processes are restartable. Job handlers are safe to retry and carry versioned payloads.
- A released artifact is identified by its source revision, locked dependencies, image digest, checksums, provenance, and SBOM.

## How to review a change

Before accepting a change, identify its domain owner, application use case, authorization capability, audit event, persistence boundary, delivery surfaces, and failure behavior. Then add tests at the narrowest useful level and at least one integration or deployment test for each new cross-boundary behavior. See [Development and testing](../development.md).
