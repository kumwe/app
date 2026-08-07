# Template development

A template is an extension with `"type": "template"`. It uses the same compatibility, dependency, signing, installation, and provider rules as other extensions. Installation always leaves the package disabled. Public theme activation belongs to an explicit site; administrator activation is installation-wide. Each surface retains its built-in views as a safe fallback.

## Package layout

```text
kumwe.json
src/Provider.php
templates/site/home.twig
templates/site/page.twig
templates/administrator/layout.twig
templates/views/site/widget.twig
templates/views/administrator/widget.twig
assets/
```

Only a theme selected for a surface is searched before that surface's built-in templates. A site theme is never present in the administrator Twig loader, and an administrator theme is never present in the site loader. Administrator activation, disablement, and uninstall require the operator's current password. An active theme must be disabled on every surface before its package can be upgraded.

Site activation requires compile-valid `home.twig` and `page.twig` entries. Administrator activation requires a compile-valid `layout.twig`. That layout is the complete administrator-theme override contract: login views and controller-specific pages always resolve from core or an explicitly namespaced extension, while built-in pages extend the selected layout. The emergency renderer never loads the active administrator theme and always uses the protected built-in layout.

Theme mutations require `themes.site.manage` or `themes.administrator.manage` for every affected site or surface. REST, CLI, and MCP bind public activation to the authenticated site. A theme assigned to other sites must be managed from each of those site contexts before disablement or uninstall. Administrator activation, disablement, and uninstall also accept the operator's current password for step-up. Idempotency records retain only whether step-up was supplied, never the password itself.

Stable namespaces are `@core-site`, `@core-admin`, `@site-theme`, and `@admin-theme`. Active extension views are isolated by both surface and an injective hexadecimal identifier namespace; `acme/tools` resolves as `@extension-61636d652f746f6f6c73`. Extension view files belong under `templates/views/site` or `templates/views/administrator` and cannot shadow unnamed core views.

The built-in administrator uses Twig-rendered semantic HTML as its no-JavaScript baseline. Vite compiles TypeScript and Lit interactive islands into committed, immutable assets, so production never runs Node or `npm install`. First-party custom elements use the `kumwe-*` prefix. Trusted runtime extensions may obtain `AdministratorNavigationRegistry` from their restricted container and register an `AdministratorNavigationItem` that points to an extension-owned route. Every item declares a capability and is removed from the rendered navigation and command palette when the signed-in user lacks it. Extensions must not patch the core layout, assume a single-page application, or depend on private bundle modules.

`site/page.twig` receives the public content record, including:

- `site_name` from browser-managed settings;
- `site_logo` and the validated `presentation` view model, including the active design tokens and interaction treatments;
- `entry.id`, `entry.title`, `entry.slug`, and workflow status;
- structured `entry.data` fields;
- publication, version, and timestamp values.
- the recursively prepared `navigation` tree and `current_path` used by the built-in public shell.

Example:

```twig
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ entry.title }} · {{ site_name|default('Kumwe') }}</title>
</head>
<body>
  <main>
    <h1>{{ entry.title }}</h1>
    <div class="content">{{ entry.data.body|default('')|raw }}</div>
  </main>
</body>
</html>
```

Twig auto-escapes normal output. Use `|raw` only for fields guaranteed to pass the site's trusted rich-text policy. Never render request values, extension configuration, or unsanitized editor HTML as raw markup.

## Assets and presentation data

Use the versioned `/assets/extensions/{vendor}/{name}/{version}/...` path emitted for the installed package. Requests are authorized against the current extension release and signing-key state and use `no-store`, so a disabled, uninstalled, quarantined, or revoked release cannot leave publicly reachable bytes. Render navigation or extension-provided blocks through an injected service or prepared view model rather than reading application tables.

Do not embed deployment URLs, database queries, secrets, permission decisions, or business rules in Twig. Put reusable behavior in an injected extension service and give the template a presentation-ready result.

The built-in layout emits the fixed `presentation.css_variables` property map as element-level CSS custom properties and exposes the selected scheme, button, and header treatments as data attributes. Custom templates may use these values or replace the visual system, but must never render editor-supplied property names or raw CSS. Global identity belongs to `presentation.logo`; page-specific artwork remains structured content.

## Install and verify

```bash
php bin/kumwe extension:install /absolute/acme-site-template.zip
php bin/kumwe extension:activate acme/site-template --surface=site \
  --token-file=/run/secrets/kumwe-extension-token
```

Production installation also supplies an enabled signing-key identifier and detached signature. Activate administrator themes in the administrator application so current-password step-up authentication can be enforced. If a broken administrator theme cannot render, every failed themed render falls back to the non-overridable core environment, and an operator can atomically restore it with:

```bash
php bin/kumwe theme:administrator:recover --confirm=restore-core-administrator
```

Activation commits the selected surface and an immutable, signed runtime publication in one database transaction. Replace application replicas after lifecycle changes: each entrypoint materializes and verifies the database generation once before the process starts, and workers/schedulers drain if their loaded generation becomes stale. A failed local write leaves the durable publication pending for the next startup reconciliation.

Verify the homepage, a direct page URL, menus, empty/missing optional fields, error pages, keyboard navigation, contrast, responsive layouts, CSP/security headers, and asset caching. Test on PHP 8.5 with MariaDB, MySQL, and PostgreSQL when the provider has persistence behavior.
