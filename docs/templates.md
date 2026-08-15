# Template development

An installable Kumwe template is an extension with `"type": "template"`. It uses the same bounded
archive, compatibility, dependency, trust, signing, provider, installation, publication, disablement,
and uninstall rules as every other extension. Installation always leaves the package disabled. Site
activation belongs to one explicit site; administrator activation is installation-wide and requires
current-password step-up. Each surface retains protected built-in views as its recovery fallback.

The current interface target is **Kumwe Interface Standard 1.0**, identified as `kis-1.0`. Read the
[normative standard](interface-standard/README.md), the
[customization boundary](interface-standard/customization.md), and the
[template-author contract](interface-standard/template-authoring.md) before generating or reviewing a
package. KIS is a semantic, responsive, accessibility, security, and conformance contract; it is not a
requirement that every template look like Kumwe's built-in theme.

Complete, installable reference packages are available at:

- [`examples/extensions/minimal-template`](../examples/extensions/minimal-template) for a complete site
  override; and
- [`examples/extensions/minimal-administrator-template`](../examples/extensions/minimal-administrator-template)
  for a KIS 1.0 administrator shell.

## Surface authority and isolation

| Surface | Template authority | Protected platform boundary |
| --- | --- | --- |
| Site | Complete `home.twig` and `page.twig` markup and package assets | Prepared content/navigation values, trusted-rich-text designation, CSP/security headers, extension lifecycle, and per-site assignment |
| Administrator | `layout.twig` shell, package assets, and approved token values | Core and extension task views, capability-filtered navigation, KIS component semantics, CSRF, warnings, action safety, fallback rendering, and recovery |
| Portal | No installable shell override in KIS 1.0 | Core guest/authenticated shell, identity, capability filtering, CSRF, and recovery; extension portal views use the contribution registry |

A site theme is never present in the administrator loader. An administrator theme is never present in
the site loader. Active extension views are isolated by surface and by an injective hexadecimal owner
namespace. A template cannot use path tricks or an unnamespaced file to shadow another extension.

The stable namespaces are:

- `@core-site` and `@core-admin` for explicit built-in references;
- `@site-theme` and `@admin-theme` for the selected theme's own entries;
- `@kis` for public KIS Twig components; and
- `@extension-<hex-encoded-identifier>` for one active extension's registered views.

Administrator theme admission is deliberately restricted to `layout.twig`. Login and task templates
continue to resolve from core or an explicitly namespaced extension. The emergency administrator
renderer loads the protected core administrator tree and KIS components, but no active theme or extension
view. A broken operator-installed package therefore cannot replace or disable recovery.

## Package layouts

A package may support one or both theme surfaces. It must provide every required entry for a surface it
will activate on.

```text
kumwe.json
src/Provider.php
templates/site/home.twig
templates/site/page.twig
templates/site/layout.twig                 # optional theme-owned partial/layout
templates/administrator/layout.twig
assets/site.css
assets/administrator.css
README.md                                  # target surface, kis-1.0, modes, recovery, checks
```

The manifest declares every published asset. Do not add a second web root or a build step required at
runtime. The provider is a normal `ExtensionServiceProvider`; a presentation-only package may implement
an empty `register()` method as the reference examples do.

## KIS 1.0 administrator shell contract

Administrator activation compiles every packaged Twig file and renders a synthetic inherited page through
the candidate `layout.twig`. Activation fails unless the rendered result proves all of these invariants:

1. an HTML doctype, a non-empty document language, UTF-8 metadata, responsive
   `width=device-width, initial-scale=1` viewport metadata, and advertised light/dark color schemes;
2. an inherited `title` block inside the document `<title>`;
3. an inherited `content` block inside a `<main>` landmark with a stable `id` and `tabindex="-1"`;
4. a skip link whose fragment resolves to that focusable main landmark;
5. a labelled navigation landmark that renders the host-supplied workspace groups and
   capability-filtered navigation items;
6. the host-supplied destination and `aria-current="page"` state for the active item; and
7. every host-supplied administrator stylesheet and JavaScript module.

These checks protect the operator's ability to orient, navigate, skip repetitive content, use KIS
interactions, and recover. They do **not** prescribe sidebar position, visual brand, DOM nesting beyond
the stated landmarks, typography, decoration, or package-scoped CSS. A theme may add contextual blocks,
identity, a responsive navigation toggle, a search trigger, mode controls, and approved presentation
variants while retaining the protected outlets.

The authenticated shell receives these platform-owned values:

| Value | Contract |
| --- | --- |
| `administrator_navigation` | Already capability-filtered items; render `href`, `label`, workspace membership, and current state without adding hidden destinations |
| `administrator_workspaces` | Visible workspace groups for the filtered items; empty groups are already removed |
| `active_navigation` | Stable ID of the current item, or an empty string when none applies |
| `administrator_assets.stylesheets` | Ordered host styles required by core and KIS task views |
| `administrator_assets.modules` | Ordered host modules required by focused KIS enhancements |
| `administrator_commands_json` | Safely encoded command-palette data derived from the same visible navigation |
| `csrf` | Present for authenticated task views; its absence identifies a guest/login render |

Do not recompute permissions, inject unfiltered links, decode or rewrite command data, or hide required
warnings and action boundaries. The shell may branch on `csrf is defined` to produce intentional guest
and authenticated compositions. Essential reading, navigation, form submission, validation, and
recovery must remain available without JavaScript.

## Dashboard semantic context

The administrator dashboard and authenticated portal home consume one protected `dashboard` context. It is
the graphical projection of the existing KIS surface, navigation and presentation-preference contracts, not
a theme-owned page builder. Core handlers compose it only after navigation has been filtered for actor
capability, extension trust, contribution lifecycle, owner and delivery area. Stored preferences select
canonical identifiers from that live result; they never supply data or destinations.

| Value | Stable semantic contract |
| --- | --- |
| `dashboard.widgets` | Ordered selected widget documents safe to render now |
| `dashboard.available_widgets` | Bounded request-local widget catalogue evidence: effective live selections first, followed by core widgets and the current workflow page |
| `dashboard.shortcuts` | Ordered selected workflow widget documents rendered as quick links |
| `dashboard.available_shortcuts` | Bounded request-local shortcut catalogue evidence: effective live selections first, followed by the current workflow page |
| `dashboard.selected_widget_ids`, `dashboard.selected_shortcut_ids` | Effective canonical identifiers, in render order |
| `dashboard.diagnostics` | Bounded non-sensitive compatibility and fallback codes; never exception text or hidden identifiers |
| `dashboard.customized`, `dashboard.source`, `dashboard.version` | Read-only resolution evidence for the two preference slots |
| `dashboard.preference_forms` | Protected mutation metadata for the authenticated actor followed by at most one canonical access group admitted by the installation-global management decision and current bounded browser page |
| `dashboard.access_group_browser` | Explicit no-JavaScript role browser with `available`, `active`, normalized `search`, one-based `page`, `result_count`, previous/next and numeric-bound flags, and core-owned same-area action, clear, previous and next URLs |
| `dashboard.workflow_browser` | Explicit no-JavaScript workflow-choice browser with the same closed state and URL fields, an independent one-based page and normalized search over the complete current filtered navigation catalogue |
| `dashboard.preference_diagnostics` | Bounded non-sensitive preference-query diagnostics; never role names, identifiers, exception text or authorization reasons |
| `dashboard.preference_action` | Core-owned same-area POST endpoint; templates render it unchanged with the supplied `csrf` value |
| `dashboard.preference_saved`, `dashboard.preference_error`, `dashboard.preference_open` | Closed redirect-result state; errors are catalogue identifiers, never exception messages |

Each preference-form document provides `scope`, `scope_id`, `scope_label`, `label`, `message_ids`, `help`,
the optional canonical `group_code`, form-specific `available_widgets` and `available_shortcuts`,
`selected_widget_ids`, `widget_order`, `widget_version`, `selected_shortcut_ids`, `shortcut_order` and
`shortcut_version`. The shared component posts only those
server-prepared identities plus the live item identifiers, selected flags and bounded order numbers. Its
action vocabulary is `dashboard-cards.save`,
`dashboard-cards.reset`,
`navigation-shortcuts.save` and `navigation-shortcuts.reset`.

Workflow discovery renders 32 permitted navigation candidates on each of the first 100 numeric pages. A
normalized search of at most 191 characters scans the complete already-filtered current catalogue, so an exact
identifier or visible label can reach a candidate beyond the 3,200-item numeric window. Widget and shortcut
choices share that workflow query, while each personal or access-group form carries its own live selected
off-page entries first, then core widgets where applicable and the current page without duplicates. Stale or
disabled identifiers remain absent. All workflow and role continuation URLs are fixed to the current area and
preserve the other browser's validated state; templates must render them unchanged.

Every widget has `id`, `kind`, `title`, `description`, `icon`, `group`, `size`, `data`, `message_ids` and
`href`. `kind` is one of `summary`, `activity`, `context` or `workflow`; `size` is one of `small`, `medium`,
`large` or `wide`. When `message_ids` is true, the title, description and the documented static labels in
`data` are catalogue identifiers and the shared component translates them. When it is false, title,
description and group are already prepared display text from visible navigation. Only a `workflow` widget
may have a non-null root-relative `href`, and that value comes from filtered navigation. Summary, activity
and context widgets cannot carry a destination. Quick links use the same widget document and its `title`;
there is no parallel `label` field.

The protected `@kis/dashboard-icon.twig` component resolves the semantic `icon` hint without relying on an
administrator-theme or portal-shell sprite. Its closed built-in glyphs cover the core navigation vocabulary;
every other grammatically valid contributed name renders the generic dashboard glyph and records that fallback
in the component's semantic data attributes. Activity rows retain a machine `status` for tone semantics while
`status_label` and optional `status_parameters` carry translated presentation. A timestamp uses its RFC 3339
`detail` in the `<time datetime>` attribute and an ICU `detail_label` plus `detail_parameters` for display.

An extension grows the dashboard by contributing its owned KIS surface and navigation item through the
ordinary contribution registry. Once that item is active, trusted, area-matched and permitted, it becomes
an available workflow widget and quick link automatically. There is deliberately no second dashboard
registry, extension-authored dashboard markup, arbitrary widget payload, or separate extension-supplied
dashboard URL channel. Rich summary, activity and context widgets remain typed core projections until a
future KIS version defines an equally bounded contribution contract.

`dashboard-cards` and `navigation-shortcuts` retain their existing KIS preference hierarchy. A user list
replaces the access-group result; multiple direct `role:<uuid>` access-group lists form one deterministic
union below the user layer. Dashboard-card values use the same bounded dotted identifier grammar as KIS
surfaces and navigation contributions, so every already-valid owned navigation identifier remains
selectable. The protected form posts semantic identifiers and numeric order only, uses
optimistic versions and CSRF, and retains reset and no-JavaScript submission. A template may style the
documented KIS dashboard classes and tokens, but it must not change scope identities, add destinations,
perform capability decisions, or turn diagnostic codes into technical disclosures.

## Complete site override contract

Site activation requires regular, non-symlinked, compile-valid `home.twig` and `page.twig` files. Unlike
the administrator surface, Kumwe does not impose administrator-shell structure on their rendered HTML.
The public views are a complete theme override and may use a shared package-owned layout, different
navigation composition, or a purpose-built document structure.

That authority does not move product policy into Twig. Site entries consume prepared values such as:

- `site_name`, optional `site_logo`, and the validated `presentation` model;
- `entry.id`, `entry.title`, `entry.slug`, `entry.data`, publication/version values, and the explicitly
  trusted `entry.body_html` projection when available;
- recursively prepared `navigation` and `current_path` values;
- `canonical_url`; and
- ordered `site_assets.stylesheets` and `site_assets.modules`.

Use `|default` for optional values because Twig strict variables are enabled. Normal output is
auto-escaped. Use `|raw` only for a projection whose presenter explicitly guarantees trusted sanitized
rich text, such as `entry.body_html`; never apply it to request input, arbitrary extension configuration,
or unsanitized `entry.data` values.

## Tokens, components, and safe customization

KIS has four implementation layers: semantic interaction declarations, pattern selection, shared
components/tokens, and conformance/governance. A template participates at the presentation boundary; it
does not replace the first two layers or weaken the fourth.

An administrator template may:

- set documented brand, type-role, density, spacing, surface, and mode properties within their bounds;
- style KIS component classes and `data-kis-component` hooks through documented public properties;
- compose the protected shell differently while preserving its validated outlets;
- add package-scoped, deterministic assets; and
- support light, dark, high-contrast, reduced-motion, compact, comfortable, responsive, and print modes.

It must not:

- copy and fork core task templates or KIS components into the theme;
- replace extension-view namespaces, route handlers, CSRF fields, validation, review, or step-up behavior;
- add arbitrary stored CSS/JavaScript/Twig/HTML injection;
- depend on private hashed bundle classes or private JavaScript modules;
- remove focus indicators, warnings, conflict state, audit meaning, recovery consequences, or mandatory
  columns; or
- expose a resource, field, action, label, count, or destination filtered by application policy.

A change to a public KIS token updates every consumer of that token. A change to a shared `@kis`
component updates core and extension views without changing the theme. A new interaction need belongs in
the KIS proposal process, not in a theme-specific copy of application markup.

## Extension view consumption

Schema-2-or-newer extensions register administrator or portal views through their typed contribution
provider. They extend the ordinary surface layout and consume public components through `@kis`; they do
not patch a selected theme or assume its private classes. For example, an administrator extension view
may extend `layout.twig` and embed `@kis/page-header.twig` or `@kis/validation-summary.twig` with escaped,
presentation-ready values.

The selected administrator theme supplies the shell around that view. The host supplies KIS component
markup and behavior. The extension supplies its owned task data and typed actions. Keeping those three
owners separate means changing a theme does not break extension behavior and changing a KIS component
does not require copying fixes into every package.

Every contributed navigation item declares an owned capability and disappears from the navigation and
command palette when its extension is inactive, untrusted, or unauthorized. Once a package declares
`interface.surfaces`, every graphical GET route must have an area-matched surface with the same stable ID,
and every navigation item must name that admitted surface. Route path and capability must agree with the
navigation declaration. A template renders the list it receives; it never constructs a parallel registry.

The manifest declaration and provider reconciliation are deliberately explicit:

```json
{
  "administrator": {
    "navigation": [{
      "id": "acme.inspections.navigation",
      "surface": "acme.inspections.administrator.catalog",
      "path": "/inspections/",
      "capability": "acme.inspections.view"
    }],
    "routes": [{
      "name": "acme.inspections.administrator.catalog",
      "path": "/inspections/",
      "methods": ["GET"],
      "capability": "acme.inspections.view",
      "view": "acme.inspections.administrator.catalog"
    }]
  },
  "interface": {
    "surfaces": [{
      "surface": "acme.inspections.administrator.catalog",
      "standard": "kis-1.0"
    }]
  }
}
```

The abbreviated surface above must contain every required field from
[`surface-declaration.schema.json`](interface-standard/schemas/surface-declaration.schema.json). Provider
code continues to accept the frozen `ExtensionContributionRegistrar`, then requires the additive feature
only when its signed manifest publishes KIS surfaces:

```php
if (!$contributions instanceof InterfaceSurfaceRegistrar) {
    throw new LogicException('The KIS surface registrar is unavailable.');
}
foreach ($declarations->interfaceSurfaces() as $surface) {
    $contributions->interfaceSurface($surface);
}
```

An installable package with `type: template` also declares the KIS contract it consumes in a closed
top-level `template` object:

```json
{
  "template": {
    "contract": 1,
    "standard": "kis-1.0",
    "components": {"minimum": "1.0.0", "maximum": "1.0.0"},
    "tokens": {"minimum": "1.0.0", "maximum": "1.0.0"}
  }
}
```

The bounds are inclusive semantic-version ranges. Missing, unknown, malformed, reversed, or unsupported
standard/component/token declarations fail activation before Twig compilation, leaving the core recovery
renderer available. The compatibility transition preserves an existing schema-1 template that has no
declaration by assigning the exact host baseline (`kis-1.0`, component `1.0.0`, token `1.0.0`) while still
running all activation checks; its next package release must publish the explicit object. Schemas 2 through
4 never infer compatibility. See
[Template authoring against KIS 1.0](interface-standard/template-authoring.md).

## Assets and presentation data

Use the immutable package URL:

```text
/assets/extensions/{vendor}/{name}/{version}/{manifest-declared-path}
```

Asset requests are authorized against the installed release and signing-key state and use `no-store`.
A disabled, removed, quarantined, or revoked release therefore cannot leave publicly reachable bytes.
Keep fonts and other dependencies inside the signed package unless deployment policy explicitly permits
an external origin. Do not embed credentials, environment-specific origins, database access, permission
decisions, or business rules in Twig, CSS, or JavaScript.

The built-in administrator is server rendered. Vite compiles the host's focused TypeScript/Lit
enhancements into committed immutable assets; production does not run Node or `npm install`. Template
packages consume the host asset outlets and may add their own bounded assets, but do not create a second
client application.

## Deterministic build and static conformance

Build from a clean source directory, never from an installed runtime tree. The same inputs must produce
byte-identical archives:

```bash
mkdir -p /tmp/kumwe-template-proof
php bin/kumwe extension:build /absolute/path/to/template \
  --output=/tmp/kumwe-template-proof/template-a.zip
php bin/kumwe extension:build /absolute/path/to/template \
  --output=/tmp/kumwe-template-proof/template-b.zip
cmp /tmp/kumwe-template-proof/template-a.zip /tmp/kumwe-template-proof/template-b.zip
sha256sum /tmp/kumwe-template-proof/template-a.zip /tmp/kumwe-template-proof/template-b.zip
php bin/kumwe extension:conformance /tmp/kumwe-template-proof/template-a.zip
```

Static conformance verifies the strict manifest and provider contract, bounded archive paths, declared
assets, compatibility, and package lifecycle rules. Theme activation performs the surface-specific Twig
and KIS shell validation described above. Neither command replaces rendered responsive, accessibility,
keyboard, security, and visual qualification.

Production packages also require an enabled signing-key identifier and detached signature. Follow
[`extensions.md`](extensions.md) for trust, signing, installation, upgrade, and lifecycle requirements.

## Install, activate, and recover

Install the package disabled:

```bash
php bin/kumwe extension:install /absolute/path/to/template.zip
```

Activate a site theme for the authenticated site context:

```bash
php bin/kumwe extension:activate vendor/site-template --surface=site \
  --token-file=/run/secrets/kumwe-extension-token
```

To swap the public site back to the built-in presentation, disable the theme from the same site
context; disabling a site template releases that site's binding and the next request renders the
protected core templates again. Interim example, returning a site from the Horizon example to the
default theme (the same two steps work from the administrator Extensions screen as **Disable**):

```bash
php bin/kumwe extension:disable kumwe/horizon-theme-example --site=default \
  --token-file=/run/secrets/kumwe-extension-token
php bin/kumwe extension:runtime:watch --once   # or wait for the running runtime watcher to converge
```

Re-activating the theme later is the same `extension:activate --surface=site` call; no reinstall is
needed.

Activate an administrator theme through the administrator application so current-password step-up is
enforced. The selection and immutable signed runtime publication commit in one database transaction. A
compile or KIS contract failure aborts before the selection changes. Replace long-running application
replicas after lifecycle changes so each process materializes the new verified generation.

If a later filesystem or runtime failure prevents the selected administrator theme from rendering, the
request falls back to the protected core environment. An operator can atomically restore the core theme:

```bash
php bin/kumwe theme:administrator:recover --confirm=restore-core-administrator
```

An active theme must be disabled on every assigned surface before upgrade or uninstall. Site assignments
must be managed from each affected site context. Administrator activation, disablement, and uninstall
remain step-up protected. Idempotency evidence records only whether step-up was supplied, never the
password.

## Rendered qualification matrix

Before release, exercise the package with empty, sparse, representative, dense, long-label, validation,
error, and permission-reduced fixtures. At minimum verify:

- desktop, tablet, and mobile widths with no unintended document overflow, clipping, overlap, or
  unlabelled table overflow;
- light/dark, compact/comfortable, high-contrast, reduced-motion, print, and localization states;
- visible focus, skip link, landmark and heading structure, keyboard navigation, drawer/dialog focus
  return, and JavaScript-disabled completion of essential tasks;
- homepage, direct page, navigation, missing optional data, login, logout, extension views, command
  palette, error fallback, activation, restart, disablement, reactivation, and administrator recovery;
- WCAG 2.2 A/AA checks, CSP/security headers, no console/request/asset failures, and deterministic
  screenshots; and
- PHP 8.5 plus MariaDB, MySQL, and PostgreSQL when the provider adds persistence behavior.

The Playwright diagnostic evidence and completion policy are normative in
[`interface-standard/conformance.md`](interface-standard/conformance.md). A package is complete only when
it installs disabled, passes static and rendered conformance, activates on its declared surface, survives
restart, resets/disables safely, and leaves the protected administrator recovery path usable.

## Per-menu presentation binding

A published page's layout resolves from its content type, and the whole site shares one active colour
scheme. A menu item may override either decision for the page it links: bind a template and that page
renders through it; bind a colour scheme and that page renders in it, while every other page keeps the
site's choice. Both selects live on the item forms of the administrator navigation screen, blank means
"no override", and an override naming a layout or scheme that no longer exists degrades to the type or
site default instead of failing the page. This is how one navigation tree can present a documentation
section, a landing campaign, and an article stream each in its own dress without forking the content
model.
