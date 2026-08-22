# Minimal administrator template example

This installable schema-1 template targets the `administrator` surface and KIS 1.0 (`kis-1.0`). It is
the smallest complete example of the protected shell contract: host assets, inherited title/content
blocks, labelled capability-filtered navigation, active-item state, focusable main/skip target,
responsive metadata, authenticated/guest states, and the command-palette outlet remain intact.

The stylesheet consumes Kumwe/KIS properties and package-scoped classes. It does not replace core or
extension task views, hide policy state, copy KIS components, or introduce authorization logic.

Build and statically inspect the package from the repository root:

```bash
php bin/kumwe extension:build "$PWD/examples/extensions/minimal-administrator-template" \
  --output=/tmp/minimal-administrator-template.zip
php bin/kumwe extension:conformance /tmp/minimal-administrator-template.zip
```

Install the archive disabled, then activate it through the administrator application with current-password
step-up. Recovery always remains available through the protected core renderer and the command documented
in [`docs/templates.md`](../../../docs/templates.md).
