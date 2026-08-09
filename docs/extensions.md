# Extension development

Kumwe extensions are versioned ZIP packages with `kumwe.json` at the archive root. Installed code lives in the persistent extension volume; a compiled runtime map controls what can load. Packages are inspected before extraction and reject traversal paths, links, duplicate case-insensitive paths, expansion bombs, unsafe filenames, missing or invalid manifests, incompatible versions, untrusted signatures, and unsatisfied dependencies.

Supported types are `plugin`, `module`, `template`, `component`, `package`, and `language`.

## Shipped examples

The repository contains small, inspectable packages under [`examples/extensions`](../examples/extensions):

| Example | Demonstrates |
|---|---|
| [`announcements`](../examples/extensions/announcements) | Schema-2 shell contributions, package-owned field/entity definitions, injected service, and portable migration |
| [`audit-listener`](../examples/extensions/audit-listener) | Plugin provider and Joomla Event listener registration |
| [`minimal-template`](../examples/extensions/minimal-template) | Template override and packaged public asset |

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

Schema-2 manifests reject unknown root, requirement, autoload, dependency, and contribution keys. Every contribution identifier must begin with the extension namespace (`acme/announcements` becomes `acme.announcements`). Lists are bounded, paths cannot traverse, route methods are restricted, and navigation/routes must reference capabilities, workspaces, and views owned by the same package. `permissions`, when present, must exactly match the deterministically ordered contributed capability identifiers.

### Business-definition contributions

The optional `contributions.business` object contains strict `field_types` and `definitions` lists. A provider must register byte-equivalent typed objects through `ExtensionContributionRegistrar::fieldType()` and `businessDefinition()`; missing, additional, or changed runtime registrations reject the provider. Field-type and entity handles use the package namespace. Published field types are immutable under their identifier, and entity upgrades advance `definition_version` by one.

Package definitions are synchronized transactionally on install and upgrade, become available only while the package is active and trusted, and preserve their catalog/version history through disable, quarantine, trust revocation, and uninstall. See [Business definitions](business-definitions.md) for the complete schema, compatibility, and lifecycle contract.

Valid schema-1 manifests remain installable and retain their service registration, boot, legacy route, migration, event, asset, and permission behavior. Schema 1 cannot publish the new shell contribution surfaces. Move those packages to schema 2 and the contribution provider contract when adding workspace, navigation, guarded administrator route, or administrator view declarations.

## Provider and runtime contract

Every provider implements `Kumwe\CMS\Extension\Application\ExtensionServiceProvider`, Kumwe's Joomla DI service-provider contract. A schema-2 contributor also implements `ExtensionContributionProvider`; legacy lifecycle hooks remain on `RuntimeExtension`:

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

The announcements example is the conformance reference. Its browser fixture packages and signs the real example, installs it disabled, activates it, grants the contributed capability to only the administrator role, and proves the graphical lifecycle without a core route or navigation entry.

## Upgrade and recovery notes

The forward migration adds `extension_contribution_capabilities`, linking package-owned capability codes to installed extensions. Installation and upgrade synchronize only the current release's declared capabilities. Removing a capability on upgrade or uninstall removes dependent grants through existing foreign keys; extension-owned data tables are not dropped. Back up identity/grant data before intentionally removing a published capability.

The signed runtime publication now carries `manifest_schema` and canonical contribution declarations. Older schema-1 publications remain readable through defaults, while schema-2 publications are compared with the installed manifest before code loads. After deploying this change, run core migrations, materialize the current runtime generation, and replace long-lived processes. If materialization is stale or invalid, readiness remains closed; use the existing runtime repair operation. Recovery composition deliberately skips extension loading and exposes only core navigation/templates, so an invalid contributed page cannot block extension management recovery.

See [Architecture: extensions](architecture/extensions.md), [Templates](templates.md), and [Development](development.md).
