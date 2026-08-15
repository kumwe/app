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
| **Enforcement** | `composer translation:check`, `composer translation:strings`, `composer assets:direction` |

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
   every other catalogue is authored against it by a translator.
3. **Run `composer translation:compile`.** The compiled catalogue is generated output and is read
   before it is merged, like every other generated artifact in this repository.
4. **Look it up in the template** with `t('core.administrator.settings.save_action')`.
5. **Run `composer translation:strings`.** It fails if any enforced template still carries the words
   inline, if the template references an identifier the catalogue does not carry, or if the catalogue
   carries an identifier nothing references.

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

`Kumwe\CMS\Localization\Domain\MessageIdentifier` is the validator. `fromString()` checks the
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

### Messages that contain inline markup

A sentence that wraps one of its own words in an element keeps the element inside the message and is
rendered with `t_html` instead of `t`:

```twig
<p>{{ t_html('core.administrator.access_denied.explanation', {capability: missing_capability}) }}</p>
```

`t_html` escapes every supplied value before substitution, so only the markup the catalogue itself
carries is treated as markup. Use `t` everywhere else; `t_html` exists for messages that genuinely
contain an element, not as a way around escaping.

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

Three rules bound what may be stored, and each exists for a reason worth knowing:

- **Only a message a shipped catalogue declares may be overridden.** An identifier nobody looks up is
  wording that never appears, and an operator who mistyped one would believe they had changed a word
  that never changes.
- **Only a language this installation carries may be written**, so no override is stranded in a
  locale nothing resolves to.
- **A scope carries at most 500 overrides per language.** The whole map is read once per unit of work
  on the render path; relabelling a vertical's vocabulary is tens of messages, and an unbounded map
  would make every page pay for one bulk import.

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

The resolved locale is published on the request as `kumwe.locale` and on the request-scoped
`ActiveLocale` holder, which is closed when the request ends. The three layouts emit `lang` and `dir`
from it, so a site whose `default_locale` is `he` renders right-to-left with no further
configuration.

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

Read the translator through the `Kumwe\CMS\Localization\Application\Translator` port, injected
through your constructor. Pass the locale explicitly wherever you are not on the request path — a
queue handler, a scheduled job, a report — because the locale is an argument and never process state.

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

`composer assets:direction` fails the build on a physical inline-axis declaration anywhere under
`assets/`. A declaration that is genuinely correct in physical terms earns an entry in
`tools/stylesheet-direction.json` naming why; the register ships empty, because so far none is.

The browser matrix has a **language axis** as well as a device axis: `desktop-chromium-he`,
`desktop-chromium-ar`, `mobile-chromium-he` and `mobile-chromium-ar` run the right-to-left journeys,
and `playwright.config.ts` files a baseline under the project name. That separation is the point — a
right-to-left page compared against a left-to-right baseline is either a false failure or a green run
that checked nothing, so each language compares against its own. The source-language projects keep
their original names, because their committed baselines are filed under those names. What the axis
still owes is the screenshots themselves, tracked as `V2-LNG-009`.

---

## The checks

```bash
composer translation:compile    # XLIFF -> compiled PHP catalogues
composer translation:check      # the compiled catalogues match their XLIFF source
composer translation:strings    # no enforced template carries user-facing text inline
composer assets:direction       # no stylesheet pins a rule to one writing direction
```

The last three run inside `composer qa`. Each is proven in both directions by
`tests/Architecture/InterfaceTranslationGateTest.php`: green on the committed tree, and red with a
useful message on a tree that puts back what it forbids. A check that has only ever been observed
passing is a check nobody knows works.

---

## The language set

`en-GB` is the source. Version 2 states nine: `en-GB`, `en-US`, `af`, `de`, `he`, `ar`, `es`,
`pt-BR`, `zh-Hans`. Traditional Chinese (`zh-Hant`) is not in Version 2 scope.

The translated catalogues for the eight non-source languages are scheduled work and are tracked in
[`docs/roadmap/findings.json`](roadmap/findings.json) as `V2-LNG-010`. Until a catalogue exists for a
language, a request that resolves to it renders the source wording — correctly laid out, including
right-to-left — rather than failing or rendering blank.
