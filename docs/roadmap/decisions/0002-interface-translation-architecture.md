# ADR 0002 — Interface translation is authored as XLIFF, compiled to PHP, and formatted by ICU

**Status** Accepted
**Decided by** Product owner
**Verified against** `26a7b3963c255064754f541dc8286e75dd566b1f`
**Findings** `V2-LNG-001` through `V2-LNG-010`, `V2-MLC-001` through `V2-MLC-004`
**Gate** A, except the translated catalogues and per-locale visual qualification, which are Gate B

---

## Context

Version 2 must present its interface in nine languages, and must present business content in those
languages too, including content contributed by extensions. Two of the nine are written
right-to-left.

This is not a late presentation concern. Three parts of it are irreversible in practice once real
translation exists, and each of those parts therefore belongs in the frozen contract at Gate A rather
than in the release run-up:

- the identifier a translated message is looked up by,
- the locale dimension on a business definition's labels, because published definition versions are
  immutable, and
- the translation-group shape extension-contributed content must declare.

An extension author who builds against a contract without those three has to be migrated later, which
is exactly what Gate A exists to prevent.

### What exists today, verified

- **There is no translation layer at all.** No catalogue format, no catalogue loader, no translator
  service, no translation filter or function registered on any Twig environment. A grep for
  `addFilter` across `src/` returns nothing on the five classes under `src/Presentation/Twig/`, so the
  three rendering environments expose Twig's stock helpers and nothing localizable.
- **Every user-facing string is hardcoded English.** Seventy-five templates under `templates/`, 47
  console commands under `src/Delivery/Console/Command/`, and 1,852 `InvalidArgumentException`
  constructions in `src/` alone carry their text inline. `templates/administrator/settings.twig` line
  100 renders `Save settings and design` directly.
- **`default_locale` exists and does nothing.** `DynamicSiteContentMigration` line 59 seeds
  `site.default_locale` as `en`. `DoctrineSiteSettings` line 94 defaults it to `en`, line 284 reads
  it, and line 314 normalises separators and stores it. `validate()` refuses anything that is not a
  language subtag with optional subtags — a genuine locale-tag check. Its only appearance in a
  template is `templates/administrator/settings.twig` line 97, which echoes it back into its own form
  input. Nothing consumes it to select a language. The three layouts —
  `templates/site/layout.twig`, `templates/administrator/layout.twig`,
  `templates/portal/layout.twig` — each hardcode `<html lang="en">`, and no template emits a `dir`
  attribute or an `hreflang` link.
- **`ext-intl` is already a hard requirement.** `composer.json` line 19 requires it unconditionally.
  It is currently used for exactly one thing: `RecordValueCodec::unicodeNfc()` line 521 calls
  `Normalizer::normalize()`. No `MessageFormatter`, `NumberFormatter`, `IntlDateFormatter` or
  `Collator` appears anywhere in `src/`. The dependency the message formatter needs is therefore
  already paid for and unused.
- **The stylesheets are most of the way to direction independence.** Across `assets/` there are 16
  logical inline-axis declarations (`margin-inline`, `padding-inline`) and two direction-relative text
  alignments (`text-align: start`, `text-align: end`), against 20 physical `margin-left`,
  `margin-right`, `padding-left` and `padding-right` declarations, 11 physical `border-left` and
  `border-right` declarations, five `text-align: left` and **zero** floats. Right-to-left support is a
  bounded conversion of about three dozen declarations, not a rebuild.
- **Content carries no locale.** `ContentEntry` holds an identifier, title, slug, body, workflow
  state, publication window and version, and its own docblock enumerates what is deliberately absent.
  There is no locale, no translation group, no per-locale slug and no fallback declaration.
- **Definition labels are single strings inside an immutable checksummed document.**
  `EntityTypeDefinition` carries `singularLabel` and `pluralLabel`, each bounded to 120 bytes;
  `FieldDefinition` carries `label`, `description` and `helpText`. All of them are members of the
  document `CanonicalDefinitionJson` encodes and a published version is identified by a SHA-256 over
  those bytes. Adding a locale dimension to labels after extensions publish definitions would mean
  migrating live immutable documents.

## Decision

### 1. XLIFF 2.0 is the authored and interchange format

Source messages are authored and exchanged as XLIFF 2.0. Every professional translation tool and
platform reads it, so a translator never opens a source file, and an external translation service or
an AI-assisted translation pipeline plugs in through the format everything already speaks rather than
through bespoke tooling this repository would then own.

### 2. Catalogues are compiled to plain PHP arrays at build time

XLIFF is an authoring and interchange format, not a runtime format. The build compiles it to plain PHP
array catalogues, so a runtime lookup is an array access against a file the opcode cache already
holds: no XML parsing, no file I/O and no cache warm-up on the request path. The compiled catalogue is
generated output, so it is read before it is merged and its cleanliness is a build check like any
other generated artifact.

### 3. ICU MessageFormat does the formatting, through `ext-intl`

Plurals, gender, ordinals, numbers, currencies and dates go through ICU MessageFormat.

The reason is arithmetic, not preference. The nine languages in scope span **one** plural category
(`zh-Hans`), **two** (`en-GB`, `en-US`, `af`, `de`, `es`, `pt-BR`), **three** (`he`, which distinguishes
a dual) and **six** (`ar`, which distinguishes zero, one, two, few, many and other). The four-category
Russian class is not in the Version 2 set but is the next step outward, and the pipeline is chosen so
that adding it is a catalogue, not an engineering change. `sprintf` has no vocabulary for any of this:
it substitutes, it does not select. Anything other than ICU means re-implementing plural-category
selection for six-form Arabic in application code, and getting it wrong quietly.

`ext-intl` is already a hard requirement, so this adds no dependency.

### 4. Message identifiers are stable and semantic, never the source text

A message is looked up by a stable, semantic identifier such as
`administrator.settings.save_action`. **The English source text is never the lookup identifier.**

If the identifier were the English string, correcting a typographical error in English would
invalidate that message in all eight other languages, and every translator would redo work for a
change that altered no meaning. Once real translation exists in nine languages this is irreversible in
practice, which is why the rule sits in the Gate A contract and not in a style guide.

### 5. Catalogues resolve through a four-step override chain

Lookup resolves **core → extension → site → organization**, last wins. Core ships the base catalogue,
an extension may add and override its own and core's messages, a site may override either, and an
organization within a site may override again.

Site- and organization-level overrides are stored in the database, so an operator changes wording
through an administered surface without editing a file and without a deployment.

**The strategic consequence is deliberate and is the reason the chain has four steps rather than
two.** This same mechanism is **terminology adaptation**: a health vertical relabels "Client" as
"Patient", an education vertical as "Learner", a hospitality vertical as "Guest" — in one language or
in all nine — without forking core and without an extension shipping a parallel string table. The
override chain is not only how Kumwe is translated; it is how Kumwe is made to speak a vertical's
language.

### 6. Content is translated as a translation group

One logical item, one entry per locale. Each locale entry carries its own slug and its own
publication state, so English may be live while Afrikaans is still drafting. The group declares the
fallback locale used when a translation is absent, `hreflang` is emitted automatically from the
group's members, and a front-end language selector ships by default rather than being added later.

**This model belongs to the extension contribution contract, not only to core content.** Content
contributed by an extension needs locale variants exactly as core content does, and an extension
declares its translation-group behaviour the same way it declares everything else. That is the
strongest reason this work cannot wait for Gate B: an extension published against a contract with no
locale dimension would have to be migrated.

Business definition labels — `singularLabel`, `pluralLabel`, and each field's `label`, `description`
and `helpText` — carry locales for the same reason, and because a published definition version is an
immutable checksummed document, the dimension has to exist before the first extension publishes one.

### 7. The convention is enforced by a build check

A check fails the build when a new hardcoded user-facing string is introduced on a translatable
surface, and a second fails it when a catalogue entry is orphaned or a referenced identifier is
missing from the source catalogue. A convention without a gate is a suggestion, and this programme
has already recorded what happens to conventions nothing mechanical protects.

### 8. Right-to-left is one piece of work, not two

Hebrew and Arabic are both in scope and their layout work is identical, so they are converted
together: the remaining physical inline-axis declarations become logical ones, the layouts emit `lang`
and `dir` from the resolved locale, and right-to-left gets its own visual-regression baselines rather
than being asserted against left-to-right ones.

### 9. The Version 2 language set is nine, and the proof set is four

`en-GB` (source), `en-US`, `af`, `de`, `he`, `ar`, `es`, `pt-BR`, `zh-Hans`.

The first four delivered are `en-GB`, `af`, `de` and `he`, because together they stress every hard
axis: source extraction at scale, a smaller language with a thin tooling ecosystem, a
layout-stressing language of long compounds, and a right-to-left script. Once those four pass, the
remaining five are translation work rather than engineering work, and they are scheduled as such.

Traditional Chinese (`zh-Hant`) is **not** in Version 2 scope.

## Alternative rejected: `gettext`

`gettext` is the obvious alternative. It is mature, it has excellent tooling, and PHP has bound to it
for two decades. It is rejected for one disqualifying reason and one supporting reason.

1. **`setlocale()` is process-global, and Kumwe runs long-lived queue workers.** `gettext` selects its
   catalogue from the process locale. A worker draining a queue processes one job for a site whose
   locale is `ar`, then the next for a site whose locale is `de`; with a process-global selector the
   first job's locale is still in effect when the second begins unless every handler resets it
   perfectly on every path, including the failure paths. That is a correctness defect that presents as
   occasional wrong-language output in a background job — the hardest class of defect to reproduce and
   the easiest to ship. `DoctrineJobQueue` workers, the scheduler and the sequencer are all long-lived
   by design, so this is not a hypothetical.
2. **It depends on locales generated by the operating system.** A `gettext` locale must exist in the
   image. That makes correct output a property of the base image's locale generation rather than of
   the artifact this programme qualifies, and the build-once, test-the-exact-artifact chain in phase 2
   cannot prove something the image supplies out of band.

A compiled PHP array catalogue selected by an explicit argument has neither problem: the locale is a
value passed to a call, so two jobs in one worker cannot contaminate each other, and nothing outside
the artifact has to be installed for the right words to appear.

## Consequences

**Positive, and verified.** `ext-intl` is already required and almost unused, so ICU costs nothing new
to depend on. The stylesheets already favour logical properties, so right-to-left is a bounded
conversion of roughly three dozen declarations rather than a rebuild. `default_locale` already exists,
is already validated as a locale tag and is already administered, so the selection input exists and
only its consumer is missing.

**Cost.** Every user-facing string in 75 templates, 47 console commands and the error paths of `src/`
has to be extracted and given a stable identifier. That is the single largest mechanical change in the
programme, which is why `en-GB` extraction is delivered and proven at Gate A: if extraction does not
work at scale, everything downstream of it is a guess.

**Risk that must be tested, not assumed.** The override chain resolves on the render path. It must
resolve from a bounded, cacheable, per-request-resolved set and must not issue a database query per
message. Site and organization overrides are administrative state, not transactional state, and are
cached accordingly.

**Consequence for exact values.** Number and currency formatting becomes locale-dependent at the
presentation boundary only. Stored exact values are unchanged, and a formatted amount is never read
back as a value. This is the same discipline ADR 0004 records for currency conversion.

## Non-goals

- Not machine translation as a shipped product feature. The pipeline accepts externally produced
  translations, including AI-assisted ones; it does not translate at runtime.
- Not per-user locale preference beyond what the resolved site and organization settings and the
  language selector already determine.
- Not Traditional Chinese in Version 2.
- Not a general content-versioning redesign. A translation group is a locale dimension on the existing
  content model, not a replacement for it.
- Not runtime XLIFF parsing. XLIFF is an authoring and interchange format; the runtime reads compiled
  PHP.
- Not a locale-aware collation change in the database. Sorting under a locale is a separate question
  and is not decided here.
