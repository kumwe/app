# Template development

A template is an extension with `"type": "template"`. It uses the same compatibility, dependency, signing, installation, and provider rules as other extensions. Kumwe activates one public template at a time and keeps the built-in views as a safe fallback.

## Package layout

```text
kumwe.json
src/Provider.php
templates/site/home.twig
templates/site/page.twig
assets/
```

Active template paths are searched before Kumwe's built-in templates. Override only the named views the template owns. Keep administrator overrides separate from public views and declare their compatibility explicitly.

`site/page.twig` receives the public content record, including:

- `site_name` from browser-managed settings;
- `entry.id`, `entry.title`, `entry.slug`, and workflow status;
- structured `entry.data` fields;
- publication, version, and timestamp values.

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

Use content-addressed filenames or an extension-owned asset route so browser caches change with the package version. Render navigation or extension-provided blocks through an injected service or prepared view model rather than reading application tables.

Do not embed deployment URLs, database queries, secrets, permission decisions, or business rules in Twig. Put reusable behavior in an injected extension service and give the template a presentation-ready result.

## Install and verify

```bash
php bin/kumwe extension:install /absolute/acme-site-template.zip
php bin/kumwe extension:activate acme/site-template
```

Production installation also supplies an enabled signing-key identifier and detached signature. Activation switches the compiled template path for the next request; restart workers if the template provider also registers services or jobs.

Verify the homepage, a direct page URL, menus, empty/missing optional fields, error pages, keyboard navigation, contrast, responsive layouts, CSP/security headers, and asset caching. Test on PHP 8.5 with MariaDB, MySQL, and PostgreSQL when the provider has persistence behavior.
