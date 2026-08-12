# Extension development

Kumwe extensions are versioned ZIP packages with `kumwe.json` at the archive root. Installed code lives in the persistent extension volume; a compiled runtime map controls what can load. Packages are inspected before extraction and reject traversal paths, links, duplicate case-insensitive paths, expansion bombs, unsafe filenames, missing or invalid manifests, incompatible versions, untrusted signatures, and unsatisfied dependencies.

Supported types are `plugin`, `module`, `template`, `component`, `package`, and `language`.

## Shipped examples

The repository contains small, inspectable packages under [`examples/extensions`](../examples/extensions):

| Example | Demonstrates |
|---|---|
| [`announcements`](../examples/extensions/announcements) | Schema-3 shell, entity and safe field-presenter contributions, injected service, and portable migration |
| [`asset-inspection`](../examples/extensions/asset-inspection) | Schema-4 neutral proof with related entities, workflow, policies, durable events/jobs, projection/report, administrator UI, and opt-in portal |
| [`horizon-theme`](../examples/extensions/horizon-theme) | Branded schema-1 site theme proving the complete template override boundary with its own palette, typography, and assets |
| [`audit-listener`](../examples/extensions/audit-listener) | Plugin provider and Joomla Event listener registration |
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

Schema-2, schema-3, and schema-4 manifests reject unknown root, requirement, autoload, dependency, and contribution keys.
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
adapters. See [Business integrations and extension SDK](business-integrations.md).

### Business-definition contributions

The optional `contributions.business` object contains strict `field_types` and `definitions` lists. A schema-3
manifest may additionally declare `field_presentations`, `view_handlers`, and `action_handlers`. A provider must
register byte-equivalent typed objects through `ExtensionContributionRegistrar::fieldType()`,
`fieldPresentation()`, `businessDefinition()`, `customBusinessViewHandler()`, and
`customBusinessActionHandler()`; missing, additional, or changed runtime registrations reject the provider.
Field-type, entity, handler, and schema references use the package namespace. Published field types are
immutable under their identifier, and entity upgrades advance `definition_version` by one.

Each field-presentation declaration names one package-owned field type and a non-empty, duplicate-free subset of
the closed presentation contexts. The provider supplies a `FieldPresenter` for exactly that signed declaration,
after registering the field type. Presenters receive only `FieldPresentationRequest` and return a bounded semantic
model: no HTML, Twig path, request, container, repository, connection, or SQL is admitted. Core types use this same
registrar path. Activation, disable, quarantine, trust revocation, replacement, and uninstall inventory or remove
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
typed handler and validates its bounded result afterwards. Handlers receive `CustomBusinessViewQuery` or
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

Valid schema-1 manifests remain installable and retain their service registration, boot, legacy route, migration, event, asset, and permission behavior. Schema 1 cannot publish the new shell contribution surfaces. Move those packages to schema 2 and the contribution provider contract when adding workspace, navigation, guarded administrator route, or administrator view declarations.

## Provider and runtime contract

Every provider implements `Kumwe\CMS\Extension\Application\ExtensionServiceProvider`, Kumwe's Joomla DI
service-provider contract. A schema-2-or-newer contributor also implements `ExtensionContributionProvider`;
legacy lifecycle hooks remain on `RuntimeExtension`:

```php
<?php

declare(strict_types=1);

namespace Acme\Announcements;

use Kumwe\CMS\Extension\Contribution\AdministratorNavigationDefinition;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinition;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionProvider;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrar;
use Kumwe\CMS\Extension\Runtime\ExtensionContainer;
use Kumwe\CMS\Extension\Runtime\ExtensionRouteRegistrar;
use Kumwe\CMS\Extension\Runtime\RuntimeExtension;

final class Provider implements RuntimeExtension, ExtensionContributionProvider
{
    public function register(ExtensionContainer $container): void
    {
        // Compose application services and typed handler factories.
    }

    public function contribute(
        ExtensionContributionRegistrar $contributions,
        ExtensionContainer $container,
    ): void {
        $contributions->capability(new CapabilityDefinition(
            'acme.announcements.manage',
            'Manage announcements',
            'Open and manage the announcements workspace.',
        ));
        // Register every manifest-declared workspace, navigation item, view, and route exactly once.
        // Register each field presenter and custom business handler with its exact signed declaration as well.
    }

    public function boot(ExtensionContainer $container): void
    {
        // Attach typed Joomla Event listeners.
    }

    public function registerRoutes(ExtensionRouteRegistrar $routes): void
    {
        // Schema-1 compatibility routes only; use typed contributions for administrator pages.
    }
}
```

The runtime order is service registration for every active provider, one owner-bound contribution phase, boot, and route compilation. A provider cannot retain or obtain a global registry. Its registrar closes after reconciliation and rejects duplicate, undeclared, omitted, foreign-owned, or changed definitions. Resolve infrastructure dependencies only while composing services. Inject dependencies into ordinary classes. Domain code must not read environment variables, obtain a container, or query Kumwe tables.

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

Attach typed listeners to Joomla Event's dispatcher in `boot()`. Event objects and names are versioned extension API. A listener must document whether it can stop propagation and whether throwing aborts the current transaction.

Keep synchronous listeners fast and deterministic. For email, indexing, webhooks, or remote calls, enqueue a namespaced job or consume a committed outbox event. Never depend on listener registration order for correctness and never use an event to bypass an application service's authorization or audit behavior.

Declare consumed and emitted events in the manifest so compatibility tooling can inspect them. The provider remains responsible for attaching concrete listener classes.

The extension lifecycle emits paired Joomla events:

| Operation | Before | After |
|---|---|---|
| Install | `onKumweExtensionBeforeInstall` | `onKumweExtensionAfterInstall` |
| Activate | `onKumweExtensionBeforeActivate` | `onKumweExtensionAfterActivate` |
| Disable | `onKumweExtensionBeforeDisable` | `onKumweExtensionAfterDisable` |
| Uninstall | `onKumweExtensionBeforeUninstall` | `onKumweExtensionAfterUninstall` |

Lifecycle event arguments include `identifier`, `version`, `actor_id`, and an operation `result` when available. Before-event failure aborts the operation. After events run only after the corresponding state change succeeds; listeners that need durable remote work should enqueue it.

## Database migrations

Migration classes implement `Kumwe\CMS\Extension\Application\Migration\ExtensionMigration`:

```php
<?php

declare(strict_types=1);

namespace Acme\Announcements\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Extension\Application\Migration\ExtensionMigration;
use Kumwe\CMS\Extension\Application\Migration\ExtensionTableNames;

final class Version202608040001CreateAnnouncements implements ExtensionMigration
{
    public function id(): string
    {
        return '20260804000100_create_announcements';
    }

    public function up(Connection $database, ExtensionTableNames $tables): void
    {
        $table = new Table($tables->raw('announcements'));
        $table->addColumn('id', Types::GUID);
        $table->addColumn('title', Types::STRING, ['length' => 255]);
        $table->setPrimaryKey(['id']);
        $database->createSchemaManager()->createTable($table);
    }

    public function down(Connection $database, ExtensionTableNames $tables): void
    {
        $database->createSchemaManager()->dropTable($tables->raw('announcements'));
    }
}
```

Kumwe assigns extension tables a safe prefix derived from the installation prefix and extension identifier. Migrations are applied in manifest order and recorded per extension with a SHA-256 digest of the migration implementation. A later package cannot reuse an applied migration ID with different executable bytes. Newly applied migrations are compensated in reverse order if installation fails. They must be portable across MariaDB, MySQL, and PostgreSQL and must not alter core tables.

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

The signature covers the lowercase SHA-256 package digest. Production requires an enabled Ed25519 trust key; development may explicitly allow unsigned local packages. Installation first snapshots caller-owned bytes into private staging, then verifies the immutable snapshot before migration, extraction, or public asset publication. It checks compatibility and dependencies, applies migrations under the lifecycle fence, persists the release, and stages an immutable signed runtime publication in the same registry operation. A failed local publication write cannot roll back or outrun committed registry state: startup reconciliation rematerializes the database generation, and readiness stays unhealthy until the process has loaded that exact trusted generation. A pre-commit failure compensates newly applied migrations and removes staging without replacing the active version; interrupted install records are reconciled to committed or rolled-back state on startup.

Active plugin and module upgrades keep the old version root until the replacement generation has converged and the retention lease expires. Disable and uninstall follow the same retained-root rule, so old replicas never point at prematurely deleted code. Restart workers and schedulers after installing, activating, disabling, or removing extension code.

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
