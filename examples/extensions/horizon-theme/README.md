# Horizon site theme example

This installable schema-1 template targets the `site` surface and KIS 1.0 (`kis-1.0`) with a complete
branded look: a warm sunset palette, serif typography, and gradient identity distinct from the default
corporate presentation. It demonstrates that a theme package owns every visual decision — templates and
stylesheet — while the host keeps supplying presentation-ready page, site, navigation, canonical URL,
and asset data.

The demonstration installs this theme by default through `demo:install-examples` but never activates it
onto the site surface: switching the public site to Horizon stays an operator decision, made in the
administrator with `extension:activate --surface=site` or the extensions screen, and is reversible the
same way.

Build and statically inspect the package from the repository root:

```bash
php bin/kumwe extension:build examples/extensions/horizon-theme --output=/tmp/horizon-theme.zip
php bin/kumwe extension:conformance /tmp/horizon-theme.zip
```
