# Content translation

[Interface translation](interface-translation.md) is about the wording Kumwe itself ships — buttons,
headings, error text. This document is about the other half: **the content an operator writes, and the
business definitions an extension publishes.** They share one notion of the current locale and one
fallback grammar, and nothing here invents a second.

## The short version

A translated item is a **translation group**: one logical item, one content entry per locale.

- Each locale is a real content entry with **its own slug, its own workflow state and its own publication
  window**. English can be live while German is still drafting, because publication was never a property
  of the item — it is a property of the entry, and each locale has its own.
- The group declares a **fallback locale**. A reader whose language the item does not carry, or carries
  but has not published, is served the fallback rather than a miss.
- `hreflang` is emitted **automatically** and lists **exactly the published locales**. A drafting locale
  is not advertised, and the declared fallback is emitted as `hreflang="x-default"`.
- A **language selector ships by default** on the public site, offering exactly the locales the item
  publishes, each named in its own language.
- **Business definition labels carry locales too** — `EntityTypeDefinition`'s singular and plural labels
  and `FieldDefinition`'s label, description and help text.
- **Extensions get all of it**, by declaring `contributions.content.translation_groups` in their manifest.

Nothing above is optional or configured on. A site that never translates anything is completely
unaffected: no alternates, no selector, no extra columns in use, and the same bytes in every stored
revision checksum and every published definition checksum it already had.

## For an editor: translating a page

An entry that declares no locale is content whose language nobody has stated. That is every entry
authored before this existed, and it stays valid.

To translate one:

1. Author the second language as an ordinary page, with **its own slug**. `/about` and `/ueber-uns` are
   two entries, not one entry with two names.
2. Place both entries in the same group. `ContentService::translate()` takes the entry, the locale it is
   written in and the group identifier, and the first call for a group also records the fallback:

   ```php
   $group = Uuid::uuid7()->toString();
   $content->translate($context, $englishId, $englishVersion, LocaleTag::fromString('en-GB'), $group);
   $content->translate($context, $germanId, $germanVersion, LocaleTag::fromString('de'), $group);
   ```

3. Publish each locale on its own schedule, through the ordinary workflow. Nothing about translating an
   entry moves it through its lifecycle, and nothing about publishing one locale touches another.

`translate()` changes no wording, no body and no schedule. Saying what language something is in is not an
edit to it, which is why it is a separate operation with its own audit action.

### What the database guarantees

Two properties are enforced by the engine rather than by the application:

| Constraint | What it prevents |
|---|---|
| `uniq_content_translation_locale` on `(translation_group_id, locale)` | One item carrying two entries for the same language |
| `uniq_content_site_slug` on `(site_identifier, slug)` | Two locales of one item colliding on a route segment |

Both are proven in `tests/Integration/Content/MultilingualContentIntegrationTest.php` by watching the
engine refuse the write, on every supported database.

## Which locale a reader is served

Content follows the interface. `LocaleNegotiator` resolves the request's locale once — an explicit
`?locale=` choice first, then `Accept-Language`, then the site's `default_locale` setting — and publishes
it on `ActiveLocale`. Content translation reads that and nothing else.

A URL that names a locale is honoured as written. Somebody who followed a link to the German page gets
the German page, whatever their browser prefers, because the alternative is a site whose URLs do not mean
what they say. Negotiation therefore decides only what a **language-neutral** entry point serves, which
on the public site means the root: `/` resolves the nominated homepage's group against the reader's
locale, falling through that locale's own chain — `pt-BR`, then `pt` — before it reaches the declared
fallback.

Where nothing in a group is published, nothing is served. A fallback that is still drafting is not a page
anybody may read, so it is not offered as one.

## `hreflang` and the language selector

Both come from one calculation and are rendered by `templates/site/layout.twig`:

```html
<link rel="alternate" hreflang="en-GB" href="/about">
<link rel="alternate" hreflang="de" href="/ueber-uns">
<link rel="alternate" hreflang="x-default" href="/about">
```

and, in the footer, one link per published locale, each carrying `hreflang`, `lang` and `dir`, with
`aria-current="true"` on the one being read.

Each choice is named **in its own language** — `Deutsch`, not `German`. A language selector is the one
control on a page whose reader may not be able to read the current language, so its labels come from ICU
locale data rather than from the message catalogue. That is also why the selector needs no translation of
its own, and adds no message identifier.

A page whose item publishes fewer than two locales renders neither block. An untranslated site therefore
looks exactly as it did.

## Business definition labels

`EntityTypeDefinition` and `FieldDefinition` carry an optional locale dimension beside their declared
wording:

```php
new EntityTypeDefinition(
    // …
    labelTranslations: [
        'singular_label' => ['de' => 'Rechnung'],
        'plural_label'   => ['de' => 'Rechnungen'],
    ],
);

new FieldDefinition(
    'reference',
    'Reference',
    'core.uuid',
    'Unique identifier.',
    textTranslations: ['label' => ['de' => 'Kennung']],
);
```

Read it back with `singularLabelIn()`, `pluralLabelIn()`, `labelIn()`, `descriptionIn()` and
`helpTextIn()`. Each resolves through the requested locale's own fallback chain and then to the declared
text, so a definition always has wording to show — never a blank, never an identifier.

**Why this had to exist before the first extension published.** A published definition version is
immutable and identified by a SHA-256 over its canonical bytes. Adding a locale dimension after packages
were in the field would mean migrating live definition documents, which is exactly the class of change
Gate A exists to make unnecessary. So the dimension is shaped to be invisible until it is used:
`label_translations` and `text_translations` are written into the canonical document **only when
non-empty**, exactly as `soft_delete_enabled`, `record_invariants`, `portal_operations` and
`computation_mode` already are. A definition declared in one language encodes to the bytes it always
encoded to, and its checksum is unchanged. `LocalizedDefinitionLabelTest` asserts that against a
hand-written pre-dimension document rather than against anything derived from the new code.

Locale keys are normalised and both dimensions are sorted, so `pt_br` and `PT-BR` cannot become two
translations of one thing and declaration order cannot change a published checksum.

## For an extension author

Content contributed by a package is content, and gets the same model. Declare it in the manifest, under
schema 4:

```json
{
  "contributions": {
    "version": 2,
    "content": {
      "translation_groups": [
        {
          "group_id": "acme.blog.articles",
          "locales": ["en-GB", "af", "de"],
          "fallback_locale": "en-GB"
        }
      ]
    }
  }
}
```

and register it in the provider through the additive `ContentTranslationRegistrar`, which the owner-bound
registrar implements alongside every other contribution surface. Both types live in
`Kumwe\CMS\Extension\Contribution`, beside the rest of the contribution contract:

```php
use Kumwe\CMS\Extension\Contribution\ContentTranslationRegistrar;
use Kumwe\CMS\Extension\Contribution\TranslationGroupDeclaration;

if ($registrar instanceof ContentTranslationRegistrar) {
    $registrar->contentTranslationGroup(
        new TranslationGroupDeclaration('acme.blog.articles', ['en-GB', 'af', 'de'], 'en-GB'),
    );
}
```

The declaration sits with the contract rather than in `Content\Domain` because it is not a content
concept: nothing in the content model reads it, and what it describes is a promise a *package* makes at
admission time.

The locale list is a **closed claim**, not a hint: an operator can read which languages a package promises
before installing it, and a package cannot widen that promise afterwards — registering a locale set the
manifest never carried is refused at contribution time. The declared fallback must be one of the declared
locales, because a fallback naming a language the package never publishes is not a fallback.

The registrar is a separate one-method interface, so a package that publishes in one language is source
compatible and untouched. Its signature, the manifest section it is read from and the members a
declaration carries are pinned in
`tests/Fixtures/ExtensionApi/content-translation-registrar-v1.json`.

A manifest that declares no content set exports no `content` section at all, so an already-published
package's bytes are the bytes it was admitted against.

## The checks

| Check | What it proves |
|---|---|
| `tests/Unit/Content/Domain/TranslationGroupTest.php` | One entry per locale, per-locale publication, fallback resolution, and that an untranslated entry's snapshot keys are unchanged |
| `tests/Unit/BusinessDefinition/Domain/LocalizedDefinitionLabelTest.php` | An untranslated definition checksums to a hand-written pre-dimension document |
| `tests/Unit/Content/Application/ExtensionContentTranslationTest.php` | An extension reaches the model with no core edit, and cannot widen its language claim |
| `tests/Unit/Content/Presentation/TranslationGroupPresenterTest.php` | Alternates list exactly the published locales, named in their own language |
| `tests/Unit/Extension/Development/ContentTranslationRegistrarFixtureTest.php` | The additive contract's bytes are the released ones |
| `tests/Integration/Content/MultilingualContentIntegrationTest.php` | All of it on a real database, including both uniqueness constraints and the rendered public page |

## What is not here

Translating the *content itself* is editorial work, not platform work: Kumwe stores and serves the
languages an operator writes, and integrates with no translation service. Per-locale visual qualification
of the public surface is Gate B, in `V2-LNG-010`.
