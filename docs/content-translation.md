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
- **Extensions declare translation sets and associate their content with them.** A package declares its
  sets through `contributions.content.translation_groups`, and at runtime places each of its stored
  entries into a declared set through the generation-one item-association contract, which enforces the
  declaration's locales and fallback at the moment of storage.

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
| Composite group-owner foreign key plus owner-equality check | An entry attaching directly to a group owned by another site |

Group attachment also locks the group row and refuses a sixty-fifth live member before writing the entry,
so the domain's 64-locale ceiling remains true under concurrent authors as well as when a group is read.
The constraints are proven by the content and migration integration suites on the supported engines.

Translation-group rows are internal lifecycle records. Their composite member relationship deliberately
uses `RESTRICT`, so a group with attached members cannot be deleted directly. Any future cleanup path or
operator maintenance must transactionally clear both `translation_group_id` and
`translation_group_site_identifier` on every member before deleting the group. That explicit detach keeps
the owner-pair check valid and avoids database-specific cascade ordering.

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

Because `/` is language-neutral, alternate links rendered there carry the explicit choice themselves:
`/?locale=de`, for example. Every root selector and `hreflang` link therefore names the locale it serves,
even when the reader's `Accept-Language` preference differs and one member of the group is the nominated
homepage entry. The rendered variant declares that explicit URL as canonical rather than asking a crawler
to consolidate every language back into `/`. Locale-bearing paths keep their canonical menu or slug URL
without the query parameter.

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

Schema 4 lets a package declare the translation-group inventory it expects to contribute:

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

The declaration sits with the contract rather than in `Content\Domain` because it is admission and
inventory metadata: nothing in the content model reads it, and what it describes is a promise a
*package* makes when its contribution set is admitted.

That boundary is important. The registrar validates and inventories the declaration, and no runtime
item identifier is carried by it: content entries only come into existence after install, so the
declaration cannot name them. Attaching a stored entry to a declared set is therefore its own
**additive, versioned contract** — the frozen declaration is never reinterpreted after release.

The locale list is a **closed declaration**, not a hint: an operator can read which languages a package
claims before installing it, and provider registration cannot widen the manifest declaration. The
declared fallback must be one of the declared locales. These admission guarantees are enforced against
individual contributed items by the association contract below.

The registrar is a separate one-method interface, so a package that publishes in one language is source
compatible and untouched. Its signature, the manifest section it is read from and the members a
declaration carries are pinned in
`tests/Fixtures/ExtensionApi/content-translation-registrar-v1.json`.

A manifest that declares no content set exports no `content` section at all, so an already-published
package's bytes are the bytes it was admitted against.

### Associating a stored entry with a declared set

The runtime half of the contract is `TranslationSetItemAssociation`, generation one. A package
constructs it with its own identifier and one of its declared sets, and hands it to the same
`ContentService` every host-service consumer stores content through:

```php
use Kumwe\CMS\Extension\Contribution\TranslationSetItemAssociation;

$association = new TranslationSetItemAssociation('acme/blog', 'acme.blog.articles');
$content->translateContributed($context, $entryId, $version, LocaleTag::fromString('de'), $association);
```

Core resolves the association against the **active** contribution registry before anything is stored,
and the resolved declaration — never the caller — supplies both the runtime group and the fallback:

- The group is derived, not allocated: one name-based UUID per generation, site, owner and set. The
  same association always resolves to the same group, across requests, restarts and reinstalls, which
  is what makes each entry's stored `translation_group_id` a durable link back to the declaring
  package without a second storage surface.
- The declared fallback is restated on every attachment, so a group whose stored declaration ever
  contradicted the manifest refuses the write instead of drifting.
- A locale the declaration does not carry is refused. A set the claimed owner has not actively
  declared is refused — including after the package is disabled, because resolution consults the live
  registry rather than a copy. Another package's set cannot even be spelled: the association requires
  the set identifier to sit inside its owner's namespace.
- The attachment commits through exactly the transaction core content uses, and its audit event names
  the owner and set beside the entry, so the association is reconstructable from the trail.

Delivery needs no second path: the derived group is an ordinary translation group, so negotiation,
fallback, `hreflang` and the language selector treat extension-contributed variants exactly as they
treat core content — and stored variants keep rendering after the contributing package is disabled,
because content is content. The association class, its exported members, the derivation (down to a
recorded byte-for-byte example) and the one `ContentService` method it travels through are pinned in
`tests/Fixtures/ExtensionApi/content-translation-association-v1.json`; a future generation is added
beside generation one, never edited into it.

## The checks

| Check | What it proves |
|---|---|
| `tests/Unit/Content/Domain/TranslationGroupTest.php` | One entry per locale, per-locale publication, fallback resolution, and that an untranslated entry's snapshot keys are unchanged |
| `tests/Unit/BusinessDefinition/Domain/LocalizedDefinitionLabelTest.php` | An untranslated definition checksums to a hand-written pre-dimension document |
| `tests/Unit/Content/Application/ExtensionContentTranslationTest.php` | Manifest/provider admission agrees and cannot widen the inventoried language declaration |
| `tests/Unit/Content/Presentation/TranslationGroupPresenterTest.php` | Alternates list exactly the published locales, named in their own language |
| `tests/Unit/Extension/Development/ContentTranslationRegistrarFixtureTest.php` | The additive contract's bytes are the released ones |
| `tests/Unit/Extension/Development/ContentTranslationAssociationFixtureTest.php` | The association contract's bytes, members and group derivation are the released ones |
| `tests/Unit/Extension/Contribution/TranslationSetItemAssociationTest.php` | The association claim is closed over owner, namespace and generation, and its derivation is a stable function |
| `tests/Unit/Content/Application/ContributedContentTranslationTest.php` | The resolved declaration decides group and fallback, the refusals leave the store untouched, and the audit trail names the set |
| `tests/Integration/Content/MultilingualContentIntegrationTest.php` | All of it on a real database, including both uniqueness constraints and the rendered public page |
| `tests/Integration/Extension/ContributedContentTranslationIntegrationTest.php` | The signed fixture: a package installs through the trust path, stores two variants through the public application path, renders both through real negotiation, and every promised refusal refuses |

## What is not here

Translating the *content itself* is editorial work, not platform work: Kumwe stores and serves the
languages an operator writes, and integrates with no translation service. Per-locale visual
qualification of the public surface is separate work.
