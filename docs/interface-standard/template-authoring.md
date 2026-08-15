# Template authoring against KIS 1.0

An installable Kumwe template is a signed extension package with `type: template`. It uses the existing
extension lifecycle, trust, immutable runtime publication, activation, disable, recovery, and asset rules.
KIS adds a stable interface target so a human or AI can generate a compatible package without copying
private core markup.

## Surface contracts

| Surface | Template authority | Protected contract |
| --- | --- | --- |
| Site | Complete `home.twig` and `page.twig`, plus package assets | Presentation-ready content, navigation, safe tokens, CSP, accessibility, and public security boundaries |
| Administrator | `layout.twig` shell and approved KIS token styling | Capability-filtered navigation, KIS component semantics, main/skip targets, recovery shell, forms, warnings, and action safety |
| Portal | Core portal shell; extension-owned views use the portal contribution registry | Portal identity, capability/navigation filtering, guest/authenticated separation, CSRF, and KIS semantics |

An administrator theme is intentionally not allowed to shadow arbitrary controller templates. This keeps
security and recovery behavior stable while still allowing a fully branded shell and token-driven visual
system. A new reusable markup need is proposed to KIS rather than solved by copying a core page.

## Required package declaration

A template manifest carries a closed, machine-validated compatibility envelope. The component and token
ranges are inclusive semantic-version bounds; activation rejects a missing, malformed, reversed, unknown,
or host-incompatible declaration before compiling any Twig:

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

The `template` object is required exactly when the extension manifest has `type: template`. Contract 1
admits only the four keys shown above. A previously valid schema-1 template that omits the object is read
with a conservative exact `kis-1.0`/`1.0.0` compatibility range so an upgrade does not strand an installed
site; it still passes the same activation and rendered-shell checks. Repackage that legacy template with
the explicit declaration before its next release. Manifest schemas 2 through 4 have no implicit fallback
and fail closed when the declaration is absent. A new template package additionally documents:

- package owner, version, signing identity, and supported Kumwe range;
- target surface and `kis-1.0` compatibility;
- supplied Twig entries and immutable asset paths;
- token values and supported modes/densities;
- fonts and external-resource policy;
- responsive, keyboard, reduced-motion, high-contrast, print, and localization behavior;
- upgrade compatibility and reset/recovery behavior;
- deterministic screenshots and conformance command used before packaging.

No template executes database-authored code, reads application tables, makes authorization decisions, or
introduces a client application. It receives escaped presentation data and uses `raw` only for values
explicitly guaranteed by a trusted rich-text presenter.

## Stable Twig consumption

Core and extension views import KIS components from the `@kis` namespace. A template styles components
through documented custom properties and modes; it does not depend on generated bundle class names or
private TypeScript modules. Essential links and forms remain ordinary server-rendered HTML.

Administrator `layout.twig` must retain:

- title and content blocks;
- a main landmark and stable skip target;
- the capability-filtered navigation and current item;
- the asset lists supplied by `AdministratorRenderer`;
- mobile open/close semantics and focus restoration;
- one command/search trigger when the shell exposes that feature;
- theme-independent recovery rendering on failure.

Site entries must retain a doctype and language, encoding and responsive viewport metadata, document title,
host stylesheet/module outlets, a focusable first main landmark containing visible presentation-ready
content, a matching skip target, and labelled visible server-rendered navigation with its current state.
Portal extension views extend the core portal layout and cannot shadow it.

Dashboard task views also remain core-owned. Their shared `@kis/dashboard-icon.twig`,
`@kis/dashboard-widget.twig` and `@kis/dashboard-preferences.twig` components consume the bounded semantic
`dashboard` context documented in
[Template development](../templates.md#dashboard-semantic-context). An administrator theme may style those
components through public KIS classes and tokens, but cannot replace their forms, inject widget markup or
destinations, or derive a second dashboard catalogue. Extension navigation that survives the ordinary owner,
trust, lifecycle, capability and area filters is projected into workflow widgets automatically. Dashboard
icons render from the protected inline component rather than a theme sprite; unknown semantic names use the
generic dashboard glyph, so an extension or installed shell cannot create an unresolved icon reference.

## AI-ready design brief

When asking an AI to create a template, provide this complete brief:

1. target Kumwe and KIS versions;
2. site or administrator surface;
3. brand identity, approved palette, logo, type roles, density, and modes;
4. required content and navigation states;
5. empty, sparse, representative, dense, long-label, validation, and permission-reduced fixtures;
6. supported viewport matrix and keyboard journeys;
7. template override contract from this document;
8. package signing/build/conformance commands;
9. explicit prohibition on policy logic, arbitrary runtime code, remote unpinned assets, and private core
   selectors;
10. expected screenshots and deterministic test evidence.

The generated package is incomplete until it installs disabled, passes static and rendered conformance,
activates on its declared surface, survives restart, can be reset/disabled, and leaves the protected core
recovery surface usable.

## Verification

Static conformance and activation compile every Twig file, validate regular non-symlinked entry files,
reject unsafe paths and assets, render both site entries against protected synthetic data, and prove the
site and administrator shell landmarks, navigation, presented content, and host asset outlets. The rendered
qualification gate then exercises representative KIS fixtures in light/dark,
compact/comfortable, desktop/mobile, keyboard, reduced-motion, high-contrast, long-label, and error states,
including computed contrast and visible focus. Administrator activation remains a step-up protected
operation. A failed themed render falls back to the immutable core administrator environment.
