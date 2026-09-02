# Extension development

Kumwe extensions are versioned ZIP packages with `kumwe.json` at the archive root. Installed code lives in the persistent extension volume; a compiled runtime map controls what can load. Packages are inspected before extraction and reject traversal paths, links, duplicate case-insensitive paths, expansion bombs, unsafe filenames, missing or invalid manifests, incompatible versions, untrusted signatures, and unsatisfied dependencies.

Supported types are `plugin`, `module`, `template`, `component`, `package`, and `language`.

## What you may build against

Read [the extension contract](extension-contract/README.md) before you design a package. It settles, as
data rather than prose, which types are public and which are internal, what each supported manifest and
contribution-SPI generation promises, and how to target one. Every generation still promised ships a
signed compatibility package that is driven through install, activate, upgrade, disable, reactivate and
uninstall on every build, and `composer extension:contract` fails when the frozen surface moves without a
deliberate generation change.

## Trust posture: read this before you publish

An installed extension is **trusted in-process extension code**. Its PHP runs inside the request, worker
and scheduler processes with the full ambient authority of the runtime user, and installing it means the
operator has trusted you with that process.

Your provider is typed against the SDK contract `Kumwe\Extension\Spi\Runtime\ExtensionContainer` —
`get()` and `share()` — and never against a host class. The App's `RestrictedExtensionContainer`
(`Kumwe\App\Extension\Runtime`) is the host authority that implements that contract: the runtime loader
builds one per active extension and hands it to your provider, it decides which host services you may
resolve, and it confines what you share to your own `extension.<vendor>.<name>.` prefix. It is an **API
compatibility boundary and not a sandbox**. Deciding which services resolve is what stops you depending on
internals that move under you, and stops two extensions colliding — and it constrains nothing about what
your code does once it is running. Do not design against it as though it were a
security boundary, and do not describe it to your users as one.

Two consequences for an author:

- **Your package's security posture is your publisher reputation.** The signature, the trust store and the
  revocation feed prove the package came from you and is still vouched for. They prove nothing about its
  behaviour. Write it as code that will hold database credentials, because it will.
- **Third-party logic you do not control belongs out of process.** Untrusted and marketplace PHP is not a
  supported tier and stays unsupported until an isolated runtime exists. Reach for the authenticated
  outbound-adapter and webhook contracts in [Business integrations](business-integrations.md) instead, so
  that code runs in its own process and reaches Kumwe through an authenticated contract.

The full inventory of the ambient authority an admitted extension inherits, and the deployment controls
that bound it, is in [Architecture: extensions](architecture/extensions.md#trust-posture).

## Shipped examples

The repository contains small, inspectable packages under [`examples/extensions`](../examples/extensions):

| Example | Demonstrates |
|---|---|
| [`announcements`](../examples/extensions/announcements) | Schema-3 shell, entity and safe field-presenter contributions, injected service, and portable migration |
| [`asset-inspection`](../examples/extensions/asset-inspection) | Schema-4 neutral proof with related entities, workflow, policies, durable events/jobs, projection/report, administrator UI, and opt-in portal |
| [`horizon-theme`](../examples/extensions/horizon-theme) | Branded schema-1 site theme proving the complete template override boundary with its own palette, typography, and assets |
| [`audit-listener`](../examples/extensions/audit-listener) | Smallest schema-4 component: one manifest-declared listener on the platform's `core.business_record.mutated` event, bound to an executable handler through the canonical registrar |
| [`minimal-template`](../examples/extensions/minimal-template) | Complete site-template override and packaged public asset |
| [`minimal-administrator-template`](../examples/extensions/minimal-administrator-template) | Installable KIS 1.0 administrator-shell contract and token-safe styling |

Build a ZIP with `kumwe.json` at its root, not with the example directory as an extra outer path. Treat examples as a starting contract, rename their namespace and identifier, and add behavior-specific tests before production use.

## Package layout

```text
kumwe.json
src/Provider.php
src/Migration/Version202608040001CreateAnnouncements.php
templates/
assets/
```

Schema 2 is required for application-shell contributions. A minimal graphical component declaration is:

```json
{
  "schema": 2,
  "name": "acme/announcements",
  "type": "component",
  "version": "1.0.0",
  "provider": "Acme\\Announcements\\Provider",
  "autoload": {
    "psr-4": {
      "Acme\\Announcements\\": "src/"
    }
  },
  "requires": {
    "kumwe": "^2.0.0",
    "php": "^8.5.0"
  },
  "dependencies": [],
  "migrations": [
    "Acme\\Announcements\\Migration\\Version202608040001CreateAnnouncements"
  ],
  "permissions": ["acme.announcements.manage"],
  "configuration": {
    "fields": {}
  },
  "routes": [],
  "events": [],
  "assets": [],
  "contributions": {
    "version": 1,
    "capabilities": [{
      "id": "acme.announcements.manage",
      "label": "Manage announcements",
      "description": "Open and manage the announcements workspace."
    }],
    "administrator": {
      "workspaces": [{
        "id": "acme.announcements.workspace",
        "label": "Announcements",
        "description": "Announcement publishing work.",
        "priority": 150
      }],
      "navigation": [{
        "id": "acme.announcements.navigation",
        "workspace": "acme.announcements.workspace",
        "label": "Announcements",
        "description": "Open announcements",
        "path": "/",
        "icon": "extensions",
        "capability": "acme.announcements.manage",
        "priority": 10,
        "keywords": "announcements"
      }],
      "routes": [{
        "name": "acme.announcements.index",
        "path": "/",
        "methods": ["GET"],
        "capability": "acme.announcements.manage",
        "view": "acme.announcements.index"
      }],
      "views": [{"name": "acme.announcements.index", "template": "index.twig"}]
    }
  }
}
```

Identifiers use `vendor/name`; compatibility and dependency constraints use semantic versions. The manifest is installation input and part of the extension's compatibility contract. Do not infer registration by scanning PHP files.

Schema-2, schema-3, schema-4, schema-5, and schema-6 manifests reject unknown root, requirement, autoload,
dependency, and contribution keys. A schema-6 package declares its composition surface as canonical Studio
documents (`contributions.composition.documents`, each the exact canonical JSON string of one pinned
`@kumwe/studio-protocol` document) with separate bounded `host_bindings`; canonical identities follow the
Studio `namespace/name` grammar inside the documents themselves.
Every contribution identifier must begin with the extension namespace (`acme/announcements` becomes
`acme.announcements`). Graphical workspaces, navigation entries, routes, views, templates, and KIS surfaces
share an additive 191-character lowercase grammar that starts and ends alphanumerically and otherwise admits
dots, underscores, and hyphens. Internal repeated dots remain representable for package identifiers already
admitted by the canonical extension grammar. The active contribution registry refuses distinct owners whose
legacy dotted namespaces are equal or prefix-overlapping, and exact owner-prefix plus safe-suffix checks remain
mandatory. Lists are bounded, paths cannot traverse, route methods are restricted, and
navigation/routes must reference capabilities, workspaces, and views owned by the same package. `permissions`,
when present, must exactly match the deterministically ordered contributed capability identifiers. Schema 2 keeps
its original business grammar (`field_types` and `definitions`); use schema 3 to declare safe field presentations
and custom business handlers. Schema 4 retains those shapes and requires contribution SPI 2 for the closed
`integration` section: event schemas/listeners/consumers, jobs/queues/schedules, projections/reports, and outbound
adapters. See [Business integrations and extension SDK](business-integrations.md). Schema 4 also admits the
optional `content` section, whose `translation_groups` list records an admission-time language inventory and
fallback claim. It does not yet associate contributed runtime content items with those declarations; see
[Content translation](content-translation.md). Schema 5 retains everything schema 4 accepts, requires contribution
SPI 3, and admits the closed `composition` section — `blocks`, `patterns`, `field_controls`, `inspectors`,
`design_vocabularies`, and `migrations` — the declarative Gate A half of the visual composition contract. Those
declarations are validated at admission and at install against the published composition schema profile and are
inert until the Gate B composition surface consumes them.

### Business-definition contributions

The optional `contributions.business` object contains strict `field_types` and `definitions` lists. A schema-3
manifest may additionally declare `field_presentations`, `view_handlers`, and `action_handlers`. The host admits
the canonical SDK manifest graph directly. Provider code binds only executable field presenters and custom
handlers to their exact signed identifiers through `ExtensionBindingRegistrar`; it cannot add or reconstruct
declarations. Missing, foreign, wrong-kind, duplicate, or changed executable bindings reject activation.
Field-type, entity, handler, and schema references use the package namespace. Published field types are
immutable under their identifier, and entity upgrades advance `definition_version` by one.

Each field-presentation declaration names one package-owned field type and a non-empty, duplicate-free subset of
the closed presentation contexts. The provider binds a canonical SDK `FieldPresenter` to exactly that signed
identifier. Presenters receive only `FieldPresentationInput` and return a bounded `FieldPresentationModel`: no
HTML, Twig path, request, container, repository, connection, or SQL is admitted. Activation, disable,
quarantine, trust revocation, replacement, and uninstall inventory or remove
presenters with their owner before the underlying field type is withdrawn.

For example, a schema-3 component that owns `acme.announcements.priority` can sign the exact contexts its provider
implements:

```json
{
  "business": {
    "field_presentations": [{
      "field_type": "acme.announcements.priority",
      "contexts": ["create", "detail", "list", "relation", "update"]
    }]
  }
}
```

Call `fieldType()` before `fieldPresentation()` during contribution. The registry rejects undeclared contexts,
partial registration after a collision, mismatched field/type metadata, changed labels or validation errors, and
any presenter that widens the server's read-only, computed, server-only, immutable, conditional, or policy decision.
Non-editable presentations must use the core output widget; editor widgets and retained values remain bounded and
are rendered only by core-owned, auto-escaped templates.

Manifest admission also derives presentation coverage from every published definition before provider code runs.
A package-owned custom field that may be read must sign `list`, `detail`, and `relation`; writable fields additionally
sign `create` and/or `update` according to their declared visibility, read-only, server-only, and immutable flags.
Consequently, a schema-2 package may keep using the unchanged shell and declaration grammar, but it must move to
schema 3 before publishing an exposed definition that uses its own custom field type.
After every provider has contributed, activation repeats the coverage check against the assembled owner-aware
registry, so a definition using another extension's custom type also fails closed when that owner's presenter is
missing or incomplete.

A custom handler declaration pairs separate `handler` and `schema` references with closed query/command and
result JSON schemas. The schema subset rejects references, floats, open objects, unknown keywords, unsafe formats,
and unbounded arrays. A definition view or action opts in by naming both references; definitions that name neither
retain the generated behavior and their legacy canonical bytes. The runtime validates input before invoking the
typed handler and validates its bounded result afterwards. Handlers implement the canonical
`Kumwe\Extension\Spi\BusinessSurface\Application\Custom` interfaces and receive `CustomBusinessViewQuery` or
`CustomBusinessActionCommand`, including `ExecutionContext`; they never receive PSR requests, a container, a DBAL
connection, or a repository for core tables. Action commands also carry expected version, idempotency key,
organization scope, and approval identity.

`CustomBusinessSurfaceDispatcher` is the runtime bridge from a policy-visible installed definition to executable
code. It resolves the exact definition owner, handler, and schema tuple, treats every missing or inactive tuple as
the same unavailable definition, and asserts an action's declared capability before dispatch. The generated-surface
facade invokes it for custom views and custom actions; generated actions without a handler continue through
`BusinessRecordService`. A custom action handler must compose the same authorized, audited, transactional,
concurrency-safe, approval-aware, and idempotent application services for its domain mutation. Schema validation is
an input/output integrity boundary, not an authorization substitute. Trusted providers may resolve
`BusinessRecordService` from their restricted extension container for this purpose. The host container, DBAL
connection, and core repositories remain unavailable.

Package definitions are synchronized transactionally on install and upgrade, become available only while the package is active and trusted, and preserve their catalog/version history through disable, quarantine, trust revocation, and uninstall. See [Business definitions](business-definitions.md) for the complete schema, compatibility, and lifecycle contract.

Valid schema-1 manifests remain installable for their declared service, migration, asset, and permission behavior.
They do not open a second code-side route or event-registration path. Move packages to the current manifest
schema when adding shell, business, integration, or other declarative surfaces.

## Provider and runtime contract

Every provider implements the package-owned
`Kumwe\Extension\Spi\Application\ExtensionServiceProvider` contract for the restricted extension
container. A package with executable behavior implements `ExtensionBindingProvider` and attaches code
only to identifiers in its signed manifest. `BootableExtension` is an optional behavior-only phase.

```php
<?php

declare(strict_types=1);

namespace Acme\Announcements;

use Kumwe\Extension\Spi\Application\ExtensionServiceProvider;
use Kumwe\Extension\Spi\Binding\ExtensionBindingProvider;
use Kumwe\Extension\Spi\Binding\ExtensionBindingRegistrar;
use Kumwe\Extension\Spi\Runtime\BootableExtension;
use Kumwe\Extension\Spi\Runtime\ExtensionContainer;

final class Provider implements ExtensionServiceProvider, ExtensionBindingProvider, BootableExtension
{
    public function register(ExtensionContainer $container): void
    {
        // Compose application services and typed handler factories.
    }

    public function bind(
        ExtensionBindingRegistrar $bindings,
        ExtensionContainer $container,
    ): void {
        // Attach each executable implementation to its exact signed manifest identifier.
        // Declarative capabilities, policies, views, and routes are activated from the manifest itself.
    }

    public function boot(ExtensionContainer $container): void
    {
        // Start behavior only after every provider has registered services and bindings are complete.
    }
}
```

The runtime order is service registration for every active provider, manifest declaration activation,
owner-bound executable binding, completeness reconciliation, boot, and route compilation. A provider cannot
retain or obtain a global registry. Its binding registrar closes after reconciliation and rejects duplicate,
undeclared, omitted, foreign-owned, wrong-kind, or changed bindings. Resolve infrastructure dependencies only
while composing services. Inject dependencies into ordinary classes. Domain code must not read environment
variables, obtain a container, or query Kumwe tables.

## Custom generated-business views and actions

A signed component may extend a published entity with owner-namespaced custom view and action declarations. The
schema-3 manifest is the admission contract: it names the handler identity and closed input/output JSON Schemas,
while the
provider must register exactly the corresponding owner-bound typed handler. Declaration without registration,
registration without declaration, duplicate identifiers, owner escape, unsupported formats, unbounded strings or
collections, arbitrary additional properties, and references to fields/actions/relations the owner does not expose
all fail reconciliation or activation.

Custom application code implements the interfaces under `BusinessSurface/Application/Custom`. A view receives an
immutable validated query and `ExecutionContext`; an action receives an immutable validated command and context.
Results are bounded typed values. Handlers obtain records only from policy-filtered application services and return
only caller-visible values; the signed result schema validates structure but cannot grant field disclosure.
These handlers never receive a PSR request, form, console options, MCP object, service container, DBAL connection,
repository, SQL fragment, or arbitrary Twig path. They therefore remain reusable from administrator, portal, REST,
CLI, and MCP without embedding a delivery rule.

Provider registration is owner-scoped and collision-safe. Disable, uninstall, replacement, trust revocation, or a
failed activation withdraws custom handlers before their dependent definition metadata. Generated REST paths and
operation IDs are fixed core routes rather than extension declarations. Activation validates signed schemas and
the complete deterministic component-name family across the post-change active set, refusing normalization
collisions, core shadowing, owner-prefix escape, and unsafe/unbounded schemas before the new runtime publication
becomes visible. The previous publication and contract cache remain authoritative when admission rolls back. See
[Generated business surfaces](architecture/generated-business-surfaces.md).

## Administrator contribution contract

- Core and extension navigation use the same workspace/navigation registries and capability filter.
- Extension route names become `administrator.extension.{declared-name}`. Paths are rooted at `/administrator/extensions/{vendor}/{name}`; the declared `/` maps to that exact root.
- Every route references a capability and view owned by the contributor. Missing references, duplicate names, duplicate method/path pairs, mixed safe/mutating methods, unsafe paths, and collisions fail application bootstrap.
- Mutating routes receive administrator CSRF middleware automatically. The normal administrator session middleware and the route's declared capability policy always apply.
- Route handlers are constructed by an `AdministratorRouteHandlerFactory`. Factories receive the administrator renderer, not the application container. The example handler calls an injected application service and passes a presentation model to its declared Twig view.
- Administrator views live under `templates/views/administrator`. Rendering is allowed only through the owning view declaration and its injective Twig namespace.
- Live trust is checked when navigation is presented and when a contributed route executes. Disable, uninstall, quarantine, key revocation, or another trust failure therefore removes navigation and makes a previously compiled route unavailable.

Contribution capability definitions enter the normal capability catalog but are not automatically granted. Assign them explicitly to a role. Package ownership is diagnostic and lifecycle metadata, never a browser editor for executable declarations.

## Events

Listeners are declared, not attached. The SDK publishes no code-side event registrar — the App registrar that
packages once attached listeners through in `boot()` is retired — so the signed manifest is the only place a
package says which events it observes. Declare each listener under `contributions.integration.domain_listeners`
(schema 4 and later; see [Business integrations](business-integrations.md)) with its `listener_id`, the
`event_type` it observes, the `schema_versions` it accepts, a `handler_version`, a `priority`, and a
`sensitivity_ceiling`. The event type is either an event the package declares itself under `event_schemas` or a
platform event in the host's `core.` namespace, whose schema the host owns, versions, and enforces at
activation; today the App publishes `core.business_record.mutated`, declared in
`src/Extension/Contribution/CoreExtensionContributions.php`. A manifest can never bind to another extension's
event.

The provider then binds exactly one executable per declaration in `bind()`, through
`Kumwe\Extension\Spi\Binding\ExtensionBindingRegistrar::domainListener()`, to a
`Kumwe\Extension\Spi\BusinessIntegration\Application\DomainEventHandler`. The handler receives the host-validated
`DomainListenerDefinition` and the `DomainEvent`. It runs synchronously inside the authoritative mutation
transaction — `BusinessRecordMutationEventPublisher` dispatches it, only while the package is active and trusted,
before the same event identity is appended to the durable outbox — and throwing aborts the mutation. Keep it fast
and deterministic: validate, record, return. Anything that must leave the process — email, indexing, webhooks,
remote calls — belongs in a durable counterpart declared in the same section: a `consumers` entry over the
committed outbox, a `jobs` entry, a `projections` entry, or a `webhooks` adapter. Never depend on listener order
for correctness and never use a listener to bypass an application service's authorization or audit behavior.

The shipped [`audit-listener`](../examples/extensions/audit-listener) example is the canonical minimal
illustration: `kumwe.json` declares one listener on `core.business_record.mutated`, `src/Provider.php` binds it
with `domainListener()`, and `src/Integration/MutationAuditListener.php` refuses a foreign declaration or an event
outside the declared contract before it records anything.

The extension lifecycle still raises paired `onKumweExtensionBefore*` and `onKumweExtensionAfter*` events around
install, activate, disable, and uninstall, but they are a private host dispatch — `DoctrineExtensionManager` on
the Laminas event manager — that reaches host-internal listeners only. A package cannot subscribe to them under
the SDK contract, and a manifest has no place to declare them. React to lifecycle through the surfaces the SDK
does publish instead: migrations for schema, `register()`, `bind()`, and `boot()` for composition, and durable
consumers or jobs for work that must follow a committed state change.

## Database migrations

Migration classes implement the SDK contract `Kumwe\Extension\Spi\Migration\ExtensionMigration` and receive a
`Kumwe\Extension\Spi\Migration\ExtensionTableNames` allocator that resolves a logical table name to the physical,
owner-prefixed one — `raw()` for schema-manager calls, `quoted()` for SQL text. The shipped
[`announcements`](../examples/extensions/announcements/src/Migration/CreateAnnouncements.php) migration is the
current shape:

```php
<?php

declare(strict_types=1);

namespace Acme\Announcements\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\Extension\Spi\Migration\ExtensionMigration;
use Kumwe\Extension\Spi\Migration\ExtensionTableNames;

final class CreateAnnouncements implements ExtensionMigration
{
    public function id(): string
    {
        return '20260804000000_create_announcements';
    }

    public function up(Connection $database, ExtensionTableNames $tables): void
    {
        $table = new Table($tables->raw('announcements'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('message', Types::TEXT);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $database->createSchemaManager()->createTable($table);
    }

    public function down(Connection $database, ExtensionTableNames $tables): void
    {
        $database->createSchemaManager()->dropTable($tables->raw('announcements'));
    }
}
```

List each class in the manifest's `migrations` array. The host's `ExtensionMigrationRunner` loads them through
a PSR-4 autoloader pinned to the deployed package directory, applies them in manifest order, and records each one
per extension with a SHA-256 digest over the class name, the migration ID, and the bytes of its source file. A
later package cannot reuse an applied migration ID with different executable bytes; drift aborts the install
rather than skipping the step. Newly applied migrations are compensated in reverse order if installation fails.
They must be portable across MariaDB, MySQL, and PostgreSQL and must not alter core tables.

Migrations are forward-moving application assets. `down()` exists to compensate the migrations applied by a failed installation attempt; uninstall and ordinary upgrades do not silently destroy site data. Provide an explicit, separately confirmed purge operation when an extension truly needs destructive cleanup.

## Routes, permissions, settings, and assets

- Prefix public routes with the extension or feature name and resolve handlers from DI.
- Declare every new capability in `permissions` and enforce it at route and application-service boundaries.
- Describe browser-managed settings in `configuration`, including type, validation, default, capability, and whether a value is secret.
- List packaged public assets by safe relative path. Kumwe serves `/assets/extensions/{vendor}/{name}/{version}/...` through a live registry/trust check, so disabling, uninstalling, or revoking the release immediately makes its files unavailable.
- Namespace jobs, events, service IDs, setting keys, and database tables to the extension identifier.

Never expose deployment secrets in extension settings. An extension that needs an API credential should integrate with the site's protected secret provider and show only connection status in the administrator.

### Business security and portal contributions

Declare capabilities and resource policies in the signed manifest before registering a business or portal
surface that references them. Each declaration has an owner-bound canonical checksum, target resource types,
allowed scopes, delegation/system flags, impact classification, and lifecycle. Kumwe rejects missing references,
duplicate identifiers, cross-owner replacement, stale checksums, and untrusted owners. Core uses the same
contribution path as extensions; providers must not construct a second capability or policy registry.

Portal providers receive an owner-restricted registrar for workspaces, templates, navigation, and routes. Routes
are confined to `/portal/extensions/{vendor}/{name}`, declare closed HTTP methods and required capabilities, and
receive CSRF middleware automatically for mutations. Templates must resolve inside the trusted active extension
runtime root. Providers receive a constrained contribution renderer, never the container, database connection,
identity stores, or arbitrary Twig loader.

Registration order is capability/resource policy, workspace/template, then navigation/route. Disable, uninstall,
replacement, and trust revocation remove in reverse order and are enforced on every request. See
[Ordinary-user portal](portal.md) and [Business security](business-security.md).

## Install and lifecycle

```bash
php bin/kumwe extension:install /absolute/acme-announcements.zip \
  --key-id=acme-release-2026 \
  --signature=BASE64_ED25519_SIGNATURE
php bin/kumwe extension:list
php bin/kumwe extension:disable acme/announcements \
  --token-file=/run/secrets/kumwe-extension-token
php bin/kumwe extension:activate acme/announcements \
  --token-file=/run/secrets/kumwe-extension-token
php bin/kumwe extension:uninstall acme/announcements \
  --token-file=/run/secrets/kumwe-extension-token
```

The signature covers the SDK's domain-separated package-signature message, which binds the lowercase SHA-256
package digest without treating that bare digest as a reusable signing payload. Production requires an enabled
Ed25519 trust key; development may explicitly allow unsigned local packages. That is now enforced rather than
documented: a process started with `APP_ENV=production` and `EXTENSIONS_ALLOW_UNSIGNED_LOCAL=true` refuses to
boot, and says which command to use to register a key instead. Use `APP_ENV=development` or `APP_ENV=testing`
for the unsigned local workflow. Installation first snapshots caller-owned bytes into private staging, then
verifies the immutable snapshot before migration, extraction, or public asset publication. It checks
compatibility and dependencies, applies migrations under the lifecycle fence, persists the release, and stages
an immutable signed runtime publication in the same registry operation. A failed local publication write cannot
roll back or outrun committed registry state: startup reconciliation rematerializes the database generation,
and readiness stays unhealthy until the process has loaded that exact trusted generation. A pre-commit failure
compensates newly applied migrations and removes staging without replacing the active version; interrupted
install records are reconciled to committed or rolled-back state on startup.

Active plugin and module upgrades keep the old version root until the replacement generation has converged and the retention lease expires. Disable and uninstall follow the same retained-root rule, so old replicas never point at prematurely deleted code. Restart workers and schedulers after installing, activating, disabling, or removing extension code.

## What a package says about itself

`extension:build` writes two documents into every package it produces. They are ordinary archive
entries, so the package digest covers them and the domain-separated detached signature therefore vouches for them; there
is no second signature format and no extra sidecar to move alongside the ZIP.

`kumwe.sbom.json` is a **CycloneDX 1.6** bill of materials listing every other packaged file with its
SHA-256. CycloneDX rather than SPDX because the release pipeline already emits CycloneDX for the core
images and source tree, its JSON is small enough to embed in every package and store on a release row,
and its `file` component type with a hash list is the honest unit of inventory for an extension — the
builder refuses a packaged `vendor/` or `node_modules/` tree, so bundled third-party code arrives as
vendored source files. Declared Kumwe dependencies appear in the `dependencies` graph carrying their
version *constraint*, never an invented resolved version.

`kumwe.provenance.json` is a `kumwe-extension-provenance-v1` statement: build type, builder identity,
the subject it describes, and the digest of the bill of materials beside it. The field set mirrors the
SLSA provenance predicate without the in-toto envelope, because that envelope exists to carry a
signature from a build service in a different trust domain than the publisher, and here they are the
same party running the same SDK. The claim it supports is therefore "the publisher asserts this", not
"an independent builder observed this", and Kumwe records it as such.

Neither document carries a timestamp, so the builder's byte-reproducibility contract is unchanged: the
same source tree still builds to the same bytes. Neither lists itself or the other, because a document
cannot carry its own digest — and installation verifies that exclusion rather than trusting it.

## Install-time admission

Installation creates one immutable package snapshot before it unpacks any bytes. The checksum, archive
entry table, manifest, evidence scan, trust decision and bounded extraction all consume that same snapshot.

**Attestations fail closed, in every mode.** Every packaged file must appear in the bill of materials
with a matching digest, no listed component may be missing, and the provenance statement must name this
manifest and this bill of materials. A package carrying neither document installs and is recorded as
`absent`; a document that is present and does not reconcile refuses the install outright.

**Mandatory package evidence always fails closed.** Unsafe archive facts, malformed manifests, PHP that
does not parse, a manifest naming a missing class, asset or template, and invalid or uninspectable
attestations refuse installation in every mode. Unknown SDK finding codes also block until App policy
classifies them explicitly.

**`EXTENSIONS_CONFORMANCE_ADMISSION` controls only authoring observations.** `scan` adds strict-types,
unfinished-marker, text-encoding and README checks; their findings are advisory because another vendor's
house style is not an installation-integrity boundary. `off` skips those authoring checks but still runs
every mandatory package check, and production refuses `off` so published installations retain the full
evidence record. The `enforce` and `warn` spellings the variable accepted before this model, both of
which ran the scan, are still read and select `scan`, so a `.env` written from an earlier example keeps
booting.

Both results are stored on the release and shown on the Extensions screen, per extension: bill of
materials state, provenance state, scan outcome, inventory size, asserted builder, and the findings. A
release installed before this shipped reads `unscanned` rather than borrowing the wording of one that
passed. The `extension.install` audit record carries the same summary.

## Upstream revocation feed

Local revocation has always worked, and an operator can still revoke a key from the Extensions screen or
`extension:trust`. What the feed adds is the upstream half: a signed list a vendor or distributor
publishes, which an installation consumes without waiting for its own operator to notice.

Point `EXTENSIONS_REVOCATION_FEED_URL` at an `https://` URL or at an absolute path to a local mirror,
and pin the issuer's Ed25519 public key in `EXTENSIONS_REVOCATION_FEED_KEY`. The key is pinned in
configuration and never read from the trust store, because the store is what the feed revokes; a feed
key living inside it would be revocable by the very compromise the feed exists to announce. Schedule the
`extensions.trust.revocations.synchronize` job to consume it.

The wire format is an envelope carrying the statement as an opaque string plus a detached signature over
exactly those bytes, which keeps canonicalization out of the trust decision:

```json
{
  "format": "kumwe-extension-revocation-envelope-v1",
  "algorithm": "ed25519",
  "key_id": "acme-security-2026",
  "document": "{\"format\":\"kumwe-extension-revocation-v1\",\"issuer\":\"acme-security\",\"sequence\":7,\"issued_at\":\"2026-08-01T00:00:00+00:00\",\"valid_until\":\"2026-08-31T00:00:00+00:00\",\"revoked_keys\":[{\"key_id\":\"acme.release.2026\",\"reason\":\"The publisher reported the key lost.\"}]}",
  "signature": "BASE64_ED25519_SIGNATURE"
}
```

`sequence` is monotonic and is the rollback defence: a list at or below the sequence already applied is
refused, so serving stale bytes cannot un-revoke a key. `valid_until` bounds how long a list may be
believed even inside its sequence. Each key the list withdraws that this installation still trusts is
passed to the same emergency revocation an operator would run, which quarantines the extensions
depending on it.

**When the feed is unreachable, the last applied list stays in force.** Kumwe does not stop serving
because an upstream origin is down. Fail-closed here would mean any vendor outage, network partition or
dropped packet could take unrelated installations offline, handing a remote kill switch to whoever can
interrupt a connection — a strictly worse exposure than the one the feed mitigates, given the local
trust store is already authoritative and already fails closed on what it can prove. The price is that
silence has to be loud: the failure is recorded with its reason, logged on every run, and once
`EXTENSIONS_REVOCATION_FEED_MAX_STALE_SECONDS` has passed since the last verified fetch the Extensions
screen shows the feed as stale. Treat that banner as an incident, not as background noise.

**A document that is served and does not verify is refused outright.** A bad signature, an unsupported
format, an expired `valid_until` and a rolled-back sequence are all recorded, audited, and raised as a
permanent job failure so the occurrence is visible rather than retried. Integrity fails closed;
availability does not.

## Test an extension

Test at least:

- manifest parsing, compatibility, dependency resolution, and signature policy;
- archive traversal, links, duplicates, size and expansion limits;
- clean install, upgrade, disable, reactivation, and uninstall;
- migration success plus mid-sequence compensation on every database engine;
- route authentication, capability denial, CSRF for browser forms, idempotency and ETags for API writes;
- event failure semantics and retry-safe queued side effects;
- runtime-map rebuild and worker restart;
- declared/provider contribution reconciliation, collision and ownership failure;
- authorized and unauthorized navigation plus direct-route denial;
- disable, reactivation, uninstall, trust revocation, and recovery-container absence;
- desktop/mobile graphical output, keyboard use, WCAG 2.2 AA checks, and stable screenshots;
- template and asset output under the production security headers.

The asset-inspection example is the full integration conformance reference; announcements remains the compact
schema-3 presentation fixture. Deployment acceptance builds and signs the asset example, installs it disabled,
activates it, exercises durable work and graphical/machine surfaces, restarts processes, disables/reactivates it,
and verifies backup/restore on every supported database engine.

## Upgrade and recovery notes

The forward migration adds `extension_contribution_capabilities`, linking package-owned capability codes to installed extensions. Installation and upgrade synchronize only the current release's declared capabilities. Removing a capability on upgrade or uninstall removes dependent grants through existing foreign keys; extension-owned data tables are not dropped. Back up identity/grant data before intentionally removing a published capability.

The signed runtime publication now carries `manifest_schema` and canonical contribution declarations. Older
schema-1 publications remain readable through defaults, while schema-2, schema-3, and schema-4 publications are compared with
the installed manifest before code loads. After deploying this change, run core migrations, materialize the current
runtime generation, and replace long-lived processes. If materialization is stale or invalid, readiness remains
closed; use the existing runtime repair operation. Recovery composition deliberately skips extension loading and
exposes only core navigation/templates, so an invalid contributed page cannot block extension management recovery.

See [Architecture: extensions](architecture/extensions.md), [Templates](templates.md), and [Development](development.md).
