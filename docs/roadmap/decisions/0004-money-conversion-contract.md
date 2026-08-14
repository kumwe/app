# ADR 0004 — Core owns money-with-currency and the conversion contract; rates come from a pipeline

**Status** Accepted
**Decided by** Product owner
**Verified against** `26a7b3963c255064754f541dc8286e75dd566b1f`
**Findings** `V2-CUR-001` through `V2-CUR-004`, `V2-ERP-004`
**Gate** A

---

## Context

Multi-currency is core. A product priced in one currency must be presentable in others, and the
platform must be able to say so without every extension inventing its own answer.

The same shape applies to units of measure, and for the same reason: if core does not own the typed
value and the conversion contract, a stock extension and a sales extension will each invent their own,
and they will not agree about what a case of a product is. This ADR decides money; the unit-of-measure
decision is identical in shape and is recorded alongside it in the roadmap as decision D13.5.

### What exists today, verified

- **The typed value exists and is exact.** `MoneyValue` binds an `ExactDecimal` amount to an uppercase
  ISO 4217 alphabetic code, refusing anything that is not exactly three uppercase letters.
  `QuantityValue` binds an `ExactDecimal` to a bounded portable unit identifier.
- **Storage is exact and split.** `CanonicalDefinitionPhysicalSchemaCompiler` line 706 emits
  `core.money` as an exact decimal `.amount` column beside a fixed three-character ascii `.currency`
  column; line 710 does the same for `core.quantity` with a `.unit` column. `RecordValueCodec` splits
  and rebuilds the pair, and refuses a currency that differs from one pinned in the field
  configuration. `BusinessDefinitionValidator` line 977 compares a configured currency against a
  default with `hash_equals()`.
- **Nothing converts.** There is no rate table, no rate row, no conversion operator and no conversion
  service anywhere under `src/`. `QuantityValue`'s own docblock states it plainly: "nothing here
  converts between units, so two quantities are only comparable when their units are identical."
  `Expression::OPERATORS` (lines 58–80) holds 21 scalar operators; none of them converts anything.
- **A field pins one currency at most.** `core.money` accepts a `currency` configuration entry, and
  when it is set the field refuses any other denomination. That is a correct exactness control and it
  is also, today, the whole of the currency model.

So the exact half is already right, and the missing half is entirely the conversion half.

## Decision

### 1. Core owns the type and the conversion contract

Core owns money-with-currency as a type — it already does — and additionally owns **the conversion
contract**: the shape of a conversion request, the shape of a converted result, and the rules a
conversion must obey. An extension that converts an amount does so through the core contract, so two
extensions converting the same amount produce results with the same shape and the same provenance.

### 2. Rate providers and rate sourcing are extension and integration concerns

Core ships no rate table, no rate feed and no rate policy. A rate provider plugs into a pipeline: an
external rate service, a manually administered table, a bank feed or a contractual fixed rate are all
implementations of the same port, and none of them is wired into core.

This is the boundary test the product objective states. Core supplies the primitive; the business rule
— which rate, from whom, applying when, rounded how, approved by whom — is an extension's.

### 3. A converted amount is always marked as converted, and carries its rate and its as-at instant

This is the non-negotiable rule of the decision.

A converted amount is never interchangeable with a stored one. It is marked as converted, and it
carries the rate applied and the instant that rate was as at, everywhere it appears: on a screen, in a
report, in an export, in an API response and in an event payload.

**A displayed price that silently drifts from its stored value is an audit defect, not a formatting
choice.** An operator reading a figure must be able to tell, without asking anyone, whether they are
looking at what was agreed or at what it is worth today — and if the latter, at what rate and as at
when.

### 4. Conversion is presentation and reporting; it never mutates a stored value

The exact-value storage discipline is unchanged. Conversion is a layer **above** stored exact values:
it reads them, it never writes them, and a converted figure is never written back into the field it
was derived from. Exact-value drift stays at the contract's zero.

Where a business genuinely needs a second stored denomination — a reporting currency posted alongside
a transaction currency — that is a second stored exact value produced by an extension's own rule and
committed in the same transaction, not a conversion masquerading as storage.

### 5. It is enforceable, not advisory

The rule fails the build. Named on the work packages that deliver it:

- a serialization test asserting that no converted money value can be encoded without its
  `converted` marker, its rate and its as-at instant — the type makes the incomplete shape
  unconstructible, and the test proves the encoder cannot drop the fields;
- an architecture test asserting that no `src/` write path accepts a converted amount as the value of
  a `core.money` field, so conversion cannot leak into storage; and
- a qualification objective: `undeclared_currency_conversions` is added to the capacity contract's
  integrity objectives at zero, so a run that presents a converted figure without provenance fails
  qualification rather than passing with a caveat.

## Alternative rejected: leave conversion entirely to extensions

The alternative considered was to leave the whole subject outside core: extensions own the type
extension, the rates and the conversion, and core knows nothing about it.

Rejected for two reasons.

1. **Extensions would invent incompatible converted values and could not exchange data.** The exact
   argument that makes unit conversion a core concern applies unchanged to currency. If a sales
   extension's converted amount and a reporting extension's converted amount are different shapes,
   neither can read the other, and the platform's promise that extensions compose is false for the
   most common enterprise value there is.
2. **Provenance would be optional.** The audit rule in section 3 is only worth stating if it is
   universal. Left to extensions, some would carry the rate and the as-at instant and some would carry
   a bare number, and a report combining both would be unreadable and quietly wrong. A rule that half
   the ecosystem follows is not a rule.

The rejected alternative's legitimate half is retained: **rates** genuinely are an extension concern,
and core takes no dependency on any rate source.

## Consequences

**Nothing about stored values changes.** `MoneyValue`, `ExactDecimal`, the split physical columns and
the pinned-currency refusal are unchanged. This decision adds a layer; it modifies no existing one.

**Presentation gains a locale dimension at the same time.** Formatting a money value for display is
locale-dependent and goes through ICU under ADR 0002. The two decisions meet exactly at the
presentation boundary and nowhere else: ICU decides how a number is written, this contract decides
whether the number is a stored value or a converted one and what must travel with it.

**Reports and exports carry more, not less.** A report column holding a converted amount carries the
rate and the as-at instant as part of the value, so an exported artifact stays self-describing after
it leaves the system. That is a widening of the export payload and is stated in the work package.

**Cost.** The conversion contract is a new core type and a new port, both small. The genuine cost is
the audit rule: every surface that renders money has to render the provenance too, which touches the
generated surfaces, the document view kind, reports, exports, the REST schemas and the machine
surface.

## Non-goals

- Not a rate table, a rate feed, or a bundled rate provider of any kind in core.
- Not a rounding policy. Rounding on conversion is a business rule and belongs to the extension that
  owns the rate.
- Not triangulation or base-currency policy. Core carries the contract; whether a conversion routes
  through a base currency is a provider's business.
- Not historical revaluation, hedging, or gain-and-loss posting. Those are ledger rules and core has
  none.
- Not a second stored denomination in core. Core stores what it is given, exactly.
- Not a change to `core.money`'s pinned-currency control, which stays exactly as it is.
