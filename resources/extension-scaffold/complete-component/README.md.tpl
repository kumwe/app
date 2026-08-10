# @@LABEL@@

`@@EXTENSION_IDENTIFIER@@` is a complete Kumwe 2 component package. It contributes a capability-gated
administrator page, portal page, relational business definition, and crash-resumable extension-owned migration.

## Verify and package

Install development dependencies and run the package test, then create the installable archive. Dependency directories
are omitted from the ZIP while credentials and private-key formats fail the build closed.

```console
composer install
composer test
php /absolute/path/to/kumwe/bin/kumwe extension:build "$PWD" \
  --output=/absolute/path/@@EXTENSION_DOTTED@@-@@VERSION@@.zip
php /absolute/path/to/kumwe/bin/kumwe extension:conformance \
  /absolute/path/@@EXTENSION_DOTTED@@-@@VERSION@@.zip
```

Run these commands through a configured Kumwe installation; its `bin/kumwe` resolves that installation's bootstrap
and production safety policy. Signing keys must be stored in canonical absolute owner-only files. Sign the verified
archive with `extension:sign`, install it with `extension:install`, and activate it only after reviewing the inspection
report.

The manifest is the signed declaration of every runtime contribution. Keep `Provider::contribute()` exactly aligned
with it and introduce schema upgrades explicitly when adopting newer platform contribution surfaces.
