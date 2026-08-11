# Minimal site template example

This installable schema-1 template targets the `site` surface and KIS 1.0 (`kis-1.0`). It demonstrates
the complete public override boundary: `home.twig` and `page.twig` are package-owned entries, while the
host supplies presentation-ready page, site, navigation, canonical URL, and asset data.

Build and statically inspect the package from the repository root:

```bash
php bin/kumwe extension:build examples/extensions/minimal-template --output=/tmp/minimal-site-template.zip
php bin/kumwe extension:conformance /tmp/minimal-site-template.zip
```

Install it disabled and activate it for an explicit site with the commands in
[`docs/templates.md`](../../../docs/templates.md). The package intentionally contains no authorization,
data access, policy logic, remote asset, or client-application code.
