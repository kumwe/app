# Interface translation

Kumwe presents its interface in the language a request resolves to. This document is the working
contract for three audiences: an **operator** changing the wording their people read, an **extension
author** shipping their own messages, and a **contributor** adding a user-facing string to core.

The architecture is settled and recorded in
[ADR 0002](roadmap/decisions/0002-interface-translation-architecture.md). This page describes what is
built and how to use it; the decision record says why it is shaped this way and what was rejected.

---

## The short version

| | |
|---|---|
| **Authored format** | XLIFF 2.0, under `resources/localization/messages/` |
| **Runtime format** | Plain PHP arrays, under `resources/localization/compiled/`, generated |
| **Formatting** | ICU MessageFormat through `ext-intl` |
| **Lookup key** | A stable, namespaced, dotted identifier — never the English text |
| **Resolution** | Core → extension → site → organization, most specific wins, per identifier |
| **Language set** | Nine complete catalogues: `en-GB` (source), `en-US`, `af`, `de`, `he`, `ar`, `es`, `pt-BR`, `zh-Hans` |
| **Enforcement** | `composer translation:check`, `composer translation:strings`, `composer translation:quality`, `composer assets:direction` |

Nothing on the request path parses XML, and nothing anywhere calls `setlocale()`. The locale is an
argument to a call, which is what lets one long-lived worker process one job in Arabic and the next
in German without the first leaking into the second.

**`ext-intl` is a hard requirement**, declared in `composer.json`, installed in both shipped images
and enabled in every continuous-integration job. Without it the message formatter refuses to be
constructed and says so: there is no silent fall-back to a substituting formatter, because a
substituting formatter is wrong rather than approximate — plural and ordinal category selection
cannot be expressed without ICU, and getting Arabic counts quietly wrong on every page is worse than
failing to boot.

---

## For a contributor: adding a user-facing string

1. **Choose an identifier.** It is namespaced by owner, lowercase, dotted, and at least three
   segments: `core.administrator.settings.save_action`.
2. **Add the unit to `resources/localization/messages/en-GB.xlf`.** `en-GB` is the source language;
   every other catalogue is authored against it.
3. **Add the same unit, with a real `<target>`, to the eight other catalogues in the same change.** All
   nine must stay complete: `composer translation:compile` refuses a catalogue that is missing an
   identifier the source declares or leaves a target empty, and `composer translation:quality` refuses
   a non-English target left identical to prose English unless its register says why that is correct.
4. **Run `composer translation:compile`.** The compiled catalogue is generated output and is read
   before it is merged, like every other generated artifact in this repository.
5. **Look it up in the template** with `t('core.administrator.settings.save_action')`. A heading or a
   label handed to a component is looked up the same way — `eyebrow: t('…')`, never `eyebrow: 'Publishing'`.
6. **Run `composer translation:strings` and `composer translation:quality`.** The first fails if any
   enforced template still carries the words inline, if the template references an identifier the
   catalogue does not carry, or if the catalogue carries an identifier nothing references. The second
   formats your message in all nine languages.

### The message-identifier grammar

The identifier is the one part of this contract that cannot be corrected later. Once eight languages
carry a translation filed under it, renaming it discards that work — which is why the grammar is
frozen at Gate A rather than written in a style guide.

```
identifier  ::= segment ( "." segment )*          three or more segments
segment     ::= [a-z0-9] [a-z0-9_-]*
```

- **Lowercase ASCII only.** Two identifiers can therefore never differ from each other only by case.
- **At least three segments**, so an identifier names an owner, an area and a message rather than
  just a word. `core.save` is refused; `core.settings.save_action` is accepted.
- **Namespaced by owner**, exactly as every other contributed identifier already is: `core.…` for
  what the CMS ships, `vendor.name.…` for what an extension ships. It is the same rule
  `ContributionOwner` applies, so there is one namespacing convention to learn rather than two.
- **At most 190 bytes**, so an identifier is usable unchanged as an array key, a log field and an
  XLIFF unit attribute.
- **Never the source text.** `Save settings and design` is refused by name, with a message that says
  so, rather than by a generic grammar complaint.

`Kumwe\App\Localization\Domain\MessageIdentifier` is the validator. `fromString()` checks the
grammar; `ownedBy()` additionally proves the contributor may claim the namespace; `isValid()`
answers without raising, which is what the compiler and the extraction gate use to report every
offending identifier in one pass.

### Writing the message itself

Messages are ICU MessageFormat patterns. Write the whole sentence as one message, and let ICU choose
between forms — never assemble a sentence from fragments in a template, because a translator handed
two halves of a sentence cannot make a sentence out of them in a language that orders it differently.

```xml
<unit id="core.business.record.saved_count">
  <notes>
    <note category="context">Confirmation after a bulk save. {count} is how many records were written.</note>
  </notes>
  <segment>
    <source>{count, plural, one {# record saved} other {# records saved}}</source>
  </segment>
</unit>
```

The plural categories are not a stylistic choice. The nine languages in scope span **one** category
(`zh-Hans`), **two** (`en-GB`, `en-US`, `af`, `de`, `es`, `pt-BR`), **three** (`he`, which
distinguishes a dual) and **six** (`ar`, which distinguishes zero, one, two, few, many and other).
A formatter that substitutes rather than selects is simply wrong in Arabic on every count it renders.

Available in a pattern: `plural`, `selectordinal`, `select` (gender, or any flag), `number`,
`number, currency`, `number, percent`, `date` and `time`. Boolean parameters arrive as the strings
`true` and `false`, so a message may `select` on one.

Always write a `<note category="context">` when the identifier does not make the situation obvious.
A translator sees the note and the source text, never the template.

Keep markup and icons out of messages. An `<svg>` or any element outside the `t_html` subset belongs in
the template around the message: `t()` escapes it, so it would render as literal text, and
`composer translation:quality` refuses it.

Dates and times on administrator screens go through the ICU skeleton messages
`core.administrator.common.date`, `core.administrator.common.date_time` and
`core.administrator.common.date_time_seconds`, never a PHP `date()` format, so each locale chooses its own
field order, month names, digits and clock: `t('core.administrator.common.date_time', {value: date(at)})`.

### Messages that contain inline markup

A sentence that wraps one of its own words in an element keeps the element inside the message and is
rendered with `t_html` instead of `t`:

```twig
<p>{{ t_html('core.administrator.access_denied.explanation', {capability: missing_capability}) }}</p>
```

`t_html` escapes every supplied value before substitution, so only the markup the catalogue itself
carries is treated as markup. Administered overrides are checked before storage and may carry only
balanced, attribute-free `code`, `em`, `span` and `strong` elements; active elements, attributes and
malformed nesting are refused. Administered markup is also refused inside `plural`, `selectordinal`,
`select` or `choice` patterns: each branch renders independently, and balancing tags across the raw
pattern cannot prove that every possible result is balanced. Keep the element outside the branching
message or use separately balanced non-branching identifiers. Use `t` everywhere else; `t_html` exists
for messages that genuinely contain an element, not as a way around escaping.

### What is *not* translated

The extraction gate is deliberately precise about this, and the categories below are stated rather
than inferred:

| Not translated | Why |
|---|---|
| A stable machine error code | A translated error code is a broken contract for every caller matching on it. |
| An audit action name | It is a key in an evidence record, compared across installations and releases. |
| A log message | It is read by an operator through a log pipeline and by tooling that greps it, not by a visitor. |
| A developer exception message | It exists for whoever is reading the stack trace, and never reaches a rendered page. |
| A machine identifier, route, capability, field name or selector | It is a name, not a sentence. |
| A product name | `Kumwe` is a proper noun and is the same word in every language. |
| Operator-authored content | A site name, a page body, a navigation label an operator typed. That is content, and content translation is its own model. |

`tools/translation-extraction.json` carries the register of what the gate exempts, and every entry
names its reason. A template that appears in neither the enforced set nor the register is enforced,
so a newly added template cannot quietly reintroduce hardcoded text.

The same register carries the source half. `untranslatable_categories` states each category above
once, in its own words; `untranslatable_sources` names the files whose user-facing keys carry text of
that kind, each entry pointing at the category that justifies it. A file in neither list is enforced,
and an entry whose file has been deleted, or whose category the register never declared, fails the
build rather than lingering.

### The console

Console output is a translatable surface. A command writes wording through `message()` and
`failure()` on the `Output` it is handed, and names the message rather than the words:

```php
$output->message('core.console.database_status.pending', ['id' => $migration->id()]);
```

`description()` returns an identifier too — `core.console.<command>.description` — and the listing
`bin/kumwe list` prints resolves it. The translator is bound once, where the container builds the
console output, exactly as one Twig extension serves all three rendering surfaces; no command carries
a translator of its own.

`line()` and `error()` remain, and remain untranslated, for what is not wording: a JSON envelope, an
identifier, a secret printed once. Exit codes, stable JSON field names and machine error codes are
never translated — a caller matches on them.

Numbers substituted into console wording are passed to ICU as their own digits, so a count or an
identifier stays greppable instead of gaining a locale's digit grouping.

---

## For an operator: changing the wording without a deployment

**Administrator → Wording**, at `/administrator/wording`. Choose the language, choose whether the
change applies to the whole site or only to your organization, search for the message by what it
currently says, and write what it should say instead. It takes effect on the next page; nothing is
deployed and no file is edited. `localization.overrides.manage` is the capability, and every change
is written to the audit trail with the identifier, the layer and the locale it applied to.

The four-step chain exists so that changing one word is an administrative act rather than a fork.
Lookup resolves **core → extension → site → organization**, most specific wins, **per identifier and
never per file**: overriding one message leaves every other message in that catalogue exactly as it
was, and a later core release still improves the ones you did not touch.

Five rules bound what may be stored, and each exists for a reason worth knowing:

- **Only a message a shipped catalogue declares may be overridden.** An identifier nobody looks up is
  wording that never appears, and an operator who mistyped one would believe they had changed a word
  that never changes.
- **Only a language this installation carries may be written**, so no override is stranded in a
  locale nothing resolves to.
- **Every stored pattern must compile as ICU MessageFormat** for its locale, so a form submission is
  refused instead of turning the next page render into a runtime failure.
- **Markup is the same small safe inline subset accepted by `t_html`**, with no attributes or active
  elements and no ICU branch constructs, so administered terminology cannot become stored script
  execution or markup balanced only across mutually exclusive results.
- **A scope carries at most 500 overrides per language.** The whole map is read once per unit of work
  on the render path; relabelling a vertical's vocabulary is tens of messages, and an unbounded map
  would make every page pay for one bulk import.

The quota read, wording mutation and audit record share one transaction. A durable site-row lock
serializes writers even when the scope is initially empty, so two concurrent additions cannot both
observe the last available quota slot.

Withdrawing an override is how the shipped wording comes back — saving an empty replacement is
refused rather than storing a message that renders as nothing.

**This is also how a vertical speaks its own language.** A health vertical relabels "Client" as
"Patient", an education vertical as "Learner", a hospitality vertical as "Guest" — in one language or
in all nine — without forking core and without an extension shipping a parallel string table. That is
the reason the chain has four steps rather than two, and an operator who does not know it will fork
something they did not need to fork.

Resolution walks two axes. The outer axis is the locale and its fallbacks (`pt-BR`, then `pt`, then
the source locale `en-GB`); the inner axis is the override chain. A locale-specific override
therefore beats a source-language core message, which is what you expect when you change a word for
one language only.

A message no layer carries comes back as **its own identifier**, never as an empty string. A visibly
untranslated interface is a defect anybody can see and report; a silently blank one is a defect
nobody notices until a customer does.

### Which language a request renders in

Three inputs are consulted, and the first that names a locale this installation carries wins:

1. **An explicit choice** — the `locale` query parameter. A parameter rather than a header, because
   an explicit choice has to survive being copied into a link, shared and bookmarked.
2. **The client's `Accept-Language` header**, honouring quality values and dropping `q=0`.
3. **The site's `default_locale` setting**, which you administer under regional defaults.

If none of the three names a carried locale, the source language is used, so negotiation always
produces a language. A stored `default_locale` of `en` resolves to `en-GB`, so an existing
installation renders exactly as it did before.

The resolved locale is published on the request as `kumwe.locale` and on the unit-of-work
`ActiveLocale` holder, which is closed when the request ends. After authentication, trusted membership
enriches its override scope with the selected organization. The three layouts emit `lang` and `dir`
from it, so a site whose `default_locale` is `he` renders right-to-left with no further configuration.
Localized HTML responses and redirects carry `Content-Language`; when they are publicly cacheable they
also merge `Accept-Language` into `Vary`. Machine JSON, media, metrics and crawler directives do not gain
language metadata merely because locale negotiation surrounds their routes.

---

## For an extension author

An extension ships its catalogues in the same shape core does:

```
your-extension/
  localization/
    messages/
      en-GB.xlf          authored, what a translator receives
    compiled/
      en-GB.php          generated, what the runtime reads
```

Every identifier sits under your package namespace: `acme.tools.dashboard.title` for `acme/tools`.
`MessageIdentifier::ownedBy($identifier, 'acme.tools')` is the check, and it refuses an identifier
that claims another owner's namespace.

The compiled directory is discovered from your package root when the runtime map loads it, beside the
template directories the loader already finds — there is nothing to declare in the manifest and no
second registration path. Only the compiled half is read, because nothing on the request path parses
XML; the XLIFF beside it is what a translator and a translation platform receive.

An extension may **add** messages and may **override** core's, and a site or an organization may
override either. Within the extension layer, catalogue directories resolve in runtime-map order, so
the outcome is a property of the compiled map and not of filesystem enumeration.

Read the translator through the `Kumwe\App\Localization\Application\Translator` port, injected
through your constructor. Pass the locale explicitly wherever you are not on the request path — a
queue handler, a scheduled job, a report — because the locale is an argument and never process state.

Navigation is the one place where contributed wording is declared rather than looked up: an
`AdministratorNavigationDefinition` or `PortalNavigationDefinition` carries its label and description
as text, and the shell renders that text as declared. Core's own entries resolve per request through
`core.navigation.*` messages in all nine catalogues, in the sidebar, the dashboard quick links and the
command palette alike; an extension that wants its menu in another language declares it in that
language or ships the wording its own screens look up.

---

## Right-to-left

Hebrew and Arabic are both in scope and their layout work is one piece of work, so they were done
together. There is no second stylesheet: **every inline-axis rule in `assets/` is a logical
property**, and the whole mirroring follows from the `dir` attribute the layouts emit.

- `margin-inline-start` / `margin-inline-end`, not `margin-left` / `margin-right`
- `padding-inline-start` / `padding-inline-end`
- `border-inline-start` / `border-inline-end`
- `inset-inline-start` / `inset-inline-end`, not `left` / `right`
- `text-align: start` / `text-align: end`, not `left` / `right`
- `border-start-start-radius` and its three siblings, not `border-top-left-radius` and its

`composer assets:direction` fails the build on a physical inline-axis declaration in every CSS asset
the committed Vite manifest names. The manifest CSS union must equal every regular `.css` file below
the recursive build root, so a missing reference, an orphan output, traversal or a symlink fails
closed instead of narrowing discovery. CI rebuilds the manifest and refuses tracked **and untracked**
changes, binding those emitted bytes to the complete source and package graph Vite consumed. CSS
escapes are prohibited and comments are removed before inspection, so escaped or comment-split
identifiers cannot hide physical properties, `@import` or `url()`. Four-side margin, padding, inset
and border shorthands fail when their two inline values differ; asymmetric corner shorthands fail as
well. Opaque CSS query modes (`?raw`, `?inline`, `?url`), constructed stylesheets and CSS loaded
through `new URL(..., import.meta.url)` are prohibited in the owned frontend source because they
produce no emitted stylesheet for that contract to inspect. Static Lit `css` tagged templates are
included in the same scan, including named import aliases; interpolation, `unsafeCSS`, namespace
aliases and composed style arrays fail closed.

The same gate checks the site, portal and administrator stylesheets served when the Vite manifest is
absent. Those runtime fallbacks are named explicitly in `tools/stylesheet-direction.json`, and the
gate verifies that each renderer contains exactly one live asset-entry call with that entry and URL.
The site fallback is regenerated atomically after every Vite build from the initial-document graph:
the entry record's `css`, CSS `file` and CSS `assets` first, followed by its recursive **static**
`imports` in Vite's dependency-first post-order. Direct-import sibling order stays stable and the
first stylesheet occurrence wins. `dynamicImports` remain attached to the later module load and are
never promoted into render-blocking CSS. The PHP production resolver and Node fallback generator
implement that same ordering, missing-reference and cycle contract. Relative
`url()` values and retained CSS `@import` rules are refused because concatenating them would change
their base URL. CI requires both the fallback and hashed build tree to reproduce byte for byte. A
declaration that is genuinely correct in physical terms earns an entry in that register naming why;
the exception list ships empty, because so far none is.

The browser matrix has a **language axis** as well as a device axis: `desktop-chromium-he`,
`desktop-chromium-ar`, `mobile-chromium-he` and `mobile-chromium-ar` run the right-to-left journeys,
and `playwright.config.ts` files a baseline under the project name. That separation is the point — a
right-to-left page compared against a left-to-right baseline is either a false failure or a green run
that checked nothing, so each language compares against its own. The source-language projects keep
their original names, because their committed baselines are filed under those names. Each of the four
projects carries its committed baselines under `tests/Browser/screenshots/`, and the journeys hold
every surface to the same acceptance as any other locale: the mirrored render matches its committed
baseline, the document lays out with zero horizontal overflow, and every critical control on the
surface stays visible and keyboard-reachable after the mirroring.

`right-to-left.spec.ts` covers what a visitor reaches without signing in.
`signed-in-right-to-left.spec.ts` carries the same acceptance into the administrator shell — the
dashboard, the content list and the account form — and into the portal home and account security page,
each with its own baseline per project and a clean WCAG 2.2 AA scan. Any file whose name ends in
`right-to-left.spec.ts` runs only under the four locale-scoped projects, which is how the signed-in
baselines stay filed beside the public ones without a configuration change.

---

## The checks

```bash
composer translation:compile    # XLIFF -> compiled PHP catalogues
composer translation:check      # the compiled catalogues match their XLIFF source
composer translation:strings    # no enforced template, console command or error path carries text inline
composer translation:quality    # every catalogue formats, pluralizes and is translated in its own language
composer assets:direction       # no stylesheet pins a rule to one writing direction
```

`composer translation:compile` (and therefore `translation:check`) also refuses an incomplete language
set: each of the nine catalogues must exist, declare its own `trgLang`, carry every identifier the
source declares and no other, and give every unit a non-empty target.

`composer translation:strings` covers three surfaces. It refuses user-facing text nodes, translatable
attributes and prose in Twig expressions across `templates/`; it refuses a prose literal handed to the
console sink's `line()` or `error()` anywhere in `src/`; and it refuses a prose literal filed under a
user-facing key — `error`, `detail`, `summary` and their siblings — on an error path that has not been
exempted by category. It proves both directions of the catalogue contract over both surfaces: every
identifier a template or a source file looks up exists, and every identifier the catalogue carries is
referenced by something.

In Twig expressions a literal with a space in it is prose, and so is a capitalised single word filed
under a key a person reads — `label`, `title`, `eyebrow`, `heading`, `summary` and their siblings — so a
component heading such as `eyebrow: 'Publishing'` is refused like any other inline wording, while a
token such as `JSON` is not.

The console rule reads the sink rather than the method name, so a PSR-3 logger's `error()` is left
alone: a log line is read through a log pipeline, and translating it would break the tooling that
greps it.

`composer translation:quality` (`tools/verify-catalogue-quality.php`) qualifies each catalogue in its
own language, which completeness alone cannot:

- **ICU.** Every pattern is compiled and formatted by `MessageFormatter` for its own locale with
  representative arguments — counts across the plural boundaries, every `select` key, a timestamp for
  dates — and a translation must name exactly the arguments its source names. The `core.studio.shell.*`
  corpus is formatted by Studio rather than ICU and is held to placeholder parity instead.
- **Plural categories.** The categories the installed ICU selects for the counts 0 to 1000 are probed per
  locale — `one`/`other` for English, Afrikaans, German, Spanish and Portuguese; `one`/`two`/`other` for
  Hebrew (CLDR 42 and later no longer give Hebrew a separate `many`); all six for Arabic; `other` alone
  for Simplified Chinese — and every `plural` must declare each of them. Spanish and Portuguese `many`
  applies only to exact multiples of a million, where `other` is grammatical; it is reported, not
  required.
- **Untranslated wording.** A non-English target identical to its source fails when the source is prose
  — it has a letter, no `{` or `/`, is not an upper-case token and is not made only of product names —
  unless the register in the tool records why the identical word is correct in that language (`Status`
  in German, `Portal` in Spanish). A register entry that no longer matches fails as stale.
- **Markup.** A message may carry only the `t_html` subset (`code`, `em`, `span`, `strong`).

These four run inside `composer qa`. Each is proven in both directions — green on the committed tree,
and red with a useful message on a tree that puts back what it forbids — by
`tests/Architecture/InterfaceTranslationGateTest.php` and `tests/Architecture/CatalogueQualityGateTest.php`.
A check that has only ever been observed passing is a check nobody knows works.

---

## The language set

`en-GB` is the source. Version 2 ships nine: `en-GB`, `en-US`, `af`, `de`, `he`, `ar`, `es`, `pt-BR`,
`zh-Hans`. Traditional Chinese (`zh-Hant`) is not in Version 2 scope.

**All nine catalogues are authored and complete.** Each non-source catalogue under
`resources/localization/messages/` carries a `<target>` for every unit the source declares, authored
against the `en-GB` source and its context notes; `composer translation:compile` turns each into its
compiled PHP array and refuses the whole set if any catalogue is missing, incomplete or carries an
identifier the source does not. A locale that resolves but whose catalogue lacks a message still falls
back through `pt-BR` → `pt` → `en-GB` rather than rendering blank, but no shipped catalogue relies on
that for core wording.

### Qualification evidence per locale

Gate B criterion 11 asks for each language to be qualified in its own right (`V2-LNG-010`, `PL-G`).
The evidence is automated and runs with the browser lane:

| Evidence | Where | What it proves |
|---|---|---|
| Catalogue quality | `composer translation:quality` | ICU formatting, CLDR plural coverage, argument parity and real translation for all nine |
| Locale matrix | `tests/Browser/locale-qualification.spec.ts` | For each of the nine, on the administrator dashboard, content list, content editor (as Studio launches it, and as the structured form with its rich-text toolbar), settings, business definitions, business records and access control, the portal home and account security, and the public home: resolved `lang`/`dir`, zero horizontal overflow against both the visual and the layout viewport (a phone that zooms a too-wide page out hides the overflow from the visual one), no overlapping controls, every critical control visible, focusable and uncovered, a clean WCAG 2.2 AA scan, and no catalogue-translated wording left in English. Per-locale screenshots and JSON evidence are attached |
| Right-to-left baselines | `right-to-left.spec.ts`, `signed-in-right-to-left.spec.ts` | Committed `he` and `ar` baselines for public, administrator and portal surfaces at desktop and mobile |
| Task journeys | `tests/Browser/locale-journeys.spec.ts` | A content-authoring journey completed in German (long compounds: no truncation, labels aligned, German dates) and a generated-business journey completed in Hebrew (right-to-left layout, typed numbers and instants, Hebrew dates, a Hebrew status announcement) |

The matrix and the journeys run inside the `desktop-chromium` and `mobile-chromium` projects, one
browser context per locale, rather than multiplying projects; the right-to-left baselines belong to the
four locale-scoped Chromium projects. Pixel baselines are Chromium's: desktop Firefox and WebKit run the
same matrix and journeys nightly with snapshots ignored, as every breadth project does, and their
screenshots are evidence only.

The wording check reads the catalogue, not a guess: a visible string is untranslated when it equals a
source message whose translation in that locale differs. Operator-authored content (a page body, a menu
label, the site footer), definition data (content-model names and field labels, workflow-state names,
business definition labels) and wording declared by an installed extension's manifest are content in
their own language, not core interface wording, and are left out by named region.

**Decision: seeded content-model, workflow-state and theme-preset names are content, not interface.**
The names a content model, its fields and its workflow states carry — `Article`, `Page`, `Draft`,
`Published` — and the names of the seeded presentation presets are data an operator can rename, not
wording core renders from a template. Under
[ADR 0002](roadmap/decisions/0002-interface-translation-architecture.md) §6 they are translated the way
content is, with the model or site data they belong to — the rule that gives business definition labels
their own locale dimension — and never by filing them in the interface catalogue. The locale matrix
therefore treats them as content, and their English in a seeded site is not an untranslated interface
surface. Wording a component renders itself is interface, however it reaches the page: the rich-text
editor's toolbar, editor name, help line and link prompt resolve from `core.administrator.rich_text.*`
and are handed to the Lit component as `data-message-*` attributes, the pattern the Studio composition
component uses, and the matrix checks them on the structured content form in every locale.
