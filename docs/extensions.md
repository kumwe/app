# Extension development

Kumwe extensions are versioned ZIP packages with `kumwe.json` at the archive root. Installed code lives in the persistent extension volume; a compiled runtime map controls what can load. Packages are inspected before extraction and reject traversal paths, links, duplicate case-insensitive paths, expansion bombs, unsafe filenames, missing or invalid manifests, incompatible versions, untrusted signatures, and unsatisfied dependencies.

Supported types are `plugin`, `module`, `template`, `component`, `package`, and `language`.

## Shipped examples

The repository contains small, inspectable packages under [`examples/extensions`](../examples/extensions):

| Example | Demonstrates |
|---|---|
| [`announcements`](../examples/extensions/announcements) | Component provider, namespaced route, permission, configuration schema, and portable Doctrine migration |
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

Minimal manifest:

```json
{
  "schema": 1,
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
  "assets": []
}
```

Identifiers use `vendor/name`; compatibility and dependency constraints use semantic versions. The manifest is installation input and part of the extension's compatibility contract. Do not infer registration by scanning PHP files.

## Provider and runtime contract

Every provider implements `Kumwe\CMS\Extension\Application\ExtensionServiceProvider`, Kumwe's Joomla DI service-provider contract. Providers needing lifecycle or routes implement `Kumwe\CMS\Extension\Runtime\RuntimeExtension`:

```php
<?php

declare(strict_types=1);

namespace Acme\Announcements;

use Joomla\DI\Container;
use Kumwe\CMS\Extension\Runtime\RuntimeExtension;
use Mezzio\Application;

final class Provider implements RuntimeExtension
{
    public function register(Container $container): void
    {
        // Register repositories, application services, handlers, and jobs.
    }

    public function boot(Container $container): void
    {
        // Attach typed Joomla Event listeners.
    }

    public function registerRoutes(Application $application): void
    {
        // Register stable namespaced routes with capability middleware.
    }
}
```

Resolve infrastructure dependencies only while composing services. Inject dependencies into ordinary classes. Domain code must not read environment variables, obtain a container, or query Kumwe tables.

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

Kumwe assigns extension tables a safe prefix derived from the installation prefix and extension identifier. Migrations are applied in manifest order, recorded per extension, and compensated in reverse order if installation fails. They must be portable across MariaDB, MySQL, and PostgreSQL and must not alter core tables.

Migrations are forward-moving application assets. `down()` exists to compensate the migrations applied by a failed installation attempt; uninstall and ordinary upgrades do not silently destroy site data. Provide an explicit, separately confirmed purge operation when an extension truly needs destructive cleanup.

## Routes, permissions, settings, and assets

- Prefix public routes with the extension or feature name and resolve handlers from DI.
- Declare every new capability in `permissions` and enforce it at route and application-service boundaries.
- Describe browser-managed settings in `configuration`, including type, validation, default, capability, and whether a value is secret.
- List packaged public assets by safe relative path and serve them through an extension-owned asset manifest or route.
- Namespace jobs, events, service IDs, setting keys, and database tables to the extension identifier.

Never expose deployment secrets in extension settings. An extension that needs an API credential should integrate with the site's protected secret provider and show only connection status in the administrator.

## Install and lifecycle

```bash
php bin/kumwe extension:install /absolute/acme-announcements.zip \
  --key-id=acme-release-2026 \
  --signature=BASE64_ED25519_SIGNATURE
php bin/kumwe extension:list
php bin/kumwe extension:disable acme/announcements
php bin/kumwe extension:activate acme/announcements
php bin/kumwe extension:uninstall acme/announcements
```

The signature covers the lowercase SHA-256 package digest. Production requires an enabled Ed25519 trust key; development may explicitly allow unsigned local packages. Installation stages files outside the public root, checks compatibility and dependencies, applies migrations, persists the release, and atomically rebuilds the runtime map. A failure compensates newly applied migrations and removes staging without replacing the active version.

Activation affects new HTTP requests. Restart workers and schedulers after installing, activating, disabling, or removing extension code.

## Test an extension

Test at least:

- manifest parsing, compatibility, dependency resolution, and signature policy;
- archive traversal, links, duplicates, size and expansion limits;
- clean install, upgrade, disable, reactivation, and uninstall;
- migration success plus mid-sequence compensation on every database engine;
- route authentication, capability denial, CSRF for browser forms, idempotency and ETags for API writes;
- event failure semantics and retry-safe queued side effects;
- runtime-map rebuild and worker restart;
- template and asset output under the production security headers.

See [Architecture: extensions](architecture/extensions.md), [Templates](templates.md), and [Development](development.md).
