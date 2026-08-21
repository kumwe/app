# Horizon site theme example

This installable schema-1 template targets the `site` surface and KIS 1.0 (`kis-1.0`) with a complete
branded look: a warm sunset palette, serif typography, and gradient identity distinct from the default
corporate presentation. It demonstrates that a theme package owns every visual decision — templates and
stylesheet — while the host keeps supplying presentation-ready page, site, navigation, canonical URL,
and asset data.

The demonstration installs this theme by default through `demo:install-examples` but never activates it
onto the site surface: switching the public site to Horizon stays an operator decision, made in the
administrator with `extension:activate --surface=site` or the extensions screen, and is reversible the
same way (`extension:disable` from the same site context restores the built-in presentation).

Navigation renders the host-prepared menu tree through the shared `@core-site/_navigation.twig` macro,
so nested submenu items, canonical hrefs, and `aria-current` state stay in lockstep with the platform.
The theme styles that tree itself: hover- and focus-revealed submenu panels on wide viewports, and a
collapsible stacked menu behind the host's `data-site-navigation-toggle` enhancement on small ones.
Because the host stylesheet outlet always loads alongside the package stylesheet, every shell class the
theme owns carries a `horizon-` prefix — a theme must not reuse host-owned class names such as
`site-header` or `site-footer`, or the host's rules will restyle its markup.

Build and statically inspect the package from the repository root:

```bash
php bin/kumwe extension:build "$PWD/examples/extensions/horizon-theme" --output=/tmp/horizon-theme.zip
php bin/kumwe extension:conformance /tmp/horizon-theme.zip
```
