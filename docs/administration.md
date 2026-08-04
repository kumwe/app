# Administrator and publishing

The administrator is available at `/administrator`. Sessions use secure, HTTP-only, same-site cookies in production; every browser mutation requires a CSRF token.

## Site settings

Use **Settings** to change the public site name and homepage slug. The homepage must be a published, non-trashed page inside its publication window. Until it is available, Kumwe renders a setup-safe placeholder.

## Content workflow

Each edit creates an immutable revision and an audit event. The supported workflow is:

```text
Draft → Review → Published → Archived
```

The editor displays the actions currently available for the page. A page URL is `/pages/{slug}`. Slugs are unique and contain lowercase letters, numbers, and hyphens.

Use **Trash** for removal. Trashed content is hidden from the public site and ordinary API reads, but remains restorable from the administrator. Updates and state changes use optimistic versions so a stale editor cannot overwrite newer work.

## Content data

Pages have a title, slug, workflow status, publication window, and structured JSON `data` object. A basic page commonly uses:

```json
{
  "body": "<p>Welcome to our website.</p>",
  "summary": "Homepage introduction"
}
```

Templates decide how those fields render. Treat stored HTML as trusted editor content and apply an organization-approved sanitization policy to rich-text inputs supplied by custom editors.

## Extensions and templates

Open **Extensions** to upload a package, view installed versions, activate or disable an extension, or uninstall it. Production accepts packages signed by an enabled trusted key. Development installations may enable unsigned local packages with `EXTENSIONS_ALLOW_UNSIGNED_LOCAL=true`.

Activating a template disables any other active template, rebuilds the runtime map, and makes the template available on the next request. See [extension development](extensions.md) and [template development](templates.md).

## API access

Create a token for an existing administrator from the application container:

```bash
php bin/kumwe token:create \
  --email=owner@example.com \
  --name=deployment-integration \
  --capabilities=content.read,content.create,content.update,content.publish,content.delete \
  --expires-at=2027-01-01T00:00:00Z
```

The token is displayed once and stored only as a digest. Put it directly into the target secret manager. See the [REST API guide](rest-api.md).
