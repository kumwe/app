# Template development

A template is an extension with `"type": "template"`. It uses the same manifest, compatibility, dependency, signing, installation, and provider rules as other extensions. Only one template is active at a time.

## Files

```text
kumwe.json
src/Provider.php
templates/site/page.twig
templates/site/home.twig
```

Active template paths are searched before Kumwe's built-in templates. Override only the files you need. `site/page.twig` receives:

- `site_name`: configured site name when rendered at `/`;
- `entry.id`, `entry.title`, `entry.slug`, and `entry.status`;
- `entry.data`: the page's structured fields;
- publication, version, and timestamp fields from the content record.

Example page template:

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

Twig auto-escapes output. Use `|raw` only for content that has passed the site's trusted rich-text policy. Keep template assets content-addressed or embedded until an extension asset route is registered by the template provider.

Install a local template in development:

```bash
php bin/kumwe extension:install /absolute/acme-site-template.zip
php bin/kumwe extension:activate acme/site-template
```

Production installations require an enabled signing key and signature. After activation, request the homepage and a page URL, check responsive rendering and accessibility, then restart long-running workers.
