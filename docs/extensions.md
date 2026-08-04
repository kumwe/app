# Extension development

Kumwe extension packages are ZIP archives with `kumwe.json` at the archive root. Installed code lives in the persistent extensions volume; an atomic runtime map controls what is loaded. Package inspection rejects traversal paths, links, duplicate case-insensitive paths, oversized entries, expansion bombs, missing manifests, incompatible versions, untrusted signatures, and unsatisfied dependencies.

## Package layout

```text
kumwe.json
src/Provider.php
templates/
assets/
```

Minimal `kumwe.json`:

```json
{
  "schema": 1,
  "name": "acme/announcements",
  "type": "plugin",
  "version": "1.0.0",
  "provider": "Acme\\Announcements\\Provider",
  "autoload": {"psr-4": {"Acme\\Announcements\\": "src/"}},
  "requires": {"kumwe": "^2.0.0", "php": "^8.4.0"},
  "dependencies": []
}
```

Supported types are `plugin`, `module`, `template`, `component`, `package`, and `language`. Identifiers use `vendor/name`; versions and compatibility constraints use semantic versioning.

## Provider contract

Every provider implements `Kumwe\CMS\Extension\Application\ExtensionServiceProvider`, which is the Joomla DI service-provider contract. Extensions that need boot or routing hooks implement `Kumwe\CMS\Extension\Runtime\RuntimeExtension`:

```php
<?php

namespace Acme\Announcements;

use Joomla\DI\Container;
use Kumwe\CMS\Extension\Runtime\RuntimeExtension;
use Mezzio\Application;

final class Provider implements RuntimeExtension
{
    public function register(Container $container): void
    {
        // Register extension services and route handlers here.
    }

    public function boot(Container $container): void
    {
        // Attach typed Joomla event subscribers here.
    }

    public function registerRoutes(Application $application): void
    {
        // Register stable, namespaced routes such as /announcements.
    }
}
```

Keep domain state behind your own repository interface. Resolve database, event dispatcher, logger, clock, and other services from the container only in the provider; inject them into extension classes. Do not read process environment variables or query Kumwe tables directly from presentation code.

## Install and lifecycle commands

```bash
php bin/kumwe extension:install /absolute/acme-announcements.zip \
  --key-id=acme-release-2026 \
  --signature=BASE64_ED25519_SIGNATURE
php bin/kumwe extension:list
php bin/kumwe extension:disable acme/announcements
php bin/kumwe extension:activate acme/announcements
php bin/kumwe extension:uninstall acme/announcements
```

The signature is made over the lowercase SHA-256 package digest. Trusted Ed25519 public keys are stored in `extension_trust_keys`; provision and revoke them through an audited deployment process. Upgrades require a higher version, install to a new path, update the registry in a transaction, atomically rebuild the runtime map, and remove the superseded files after success.

Activation takes effect on the next PHP request or new worker process. Restart long-running workers after changing extensions so their in-memory container matches the active runtime map.

## Routes, events, and compatibility

- Prefix public routes with the extension or product feature name.
- Enforce Kumwe capabilities before every mutation.
- Use Joomla's dispatcher and typed event objects; never depend on listener order for correctness.
- Treat provider class names, service IDs, routes, manifests, and database migrations as versioned extension API.
- Test install, activation, disable, upgrade, and uninstall against the supported PHP/PostgreSQL matrix.

Use the [template guide](templates.md) for presentation packages.
