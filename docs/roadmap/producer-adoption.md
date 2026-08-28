# Producer adoption

**Companion to** [`docs/studio-composition-authoring.md`](../studio-composition-authoring.md) and
phase S. **Findings** `V2-STU-002` – `V2-STU-007`. Forward work only; nothing here is delivered.

## The decision this records

The PHP half of the Studio integration now has its own home:
[`kumwe/producer`](https://github.com/kumwe/producer) — a dependency-free Composer library that
implements the host-neutral mechanics of the Studio contract. Studio designs a composition,
Producer makes it real on a PHP host, and this application keeps every authoritative concern.
Producer's [charter](https://github.com/kumwe/producer/blob/main/CHARTER.md) forbids it authority,
storage, Node.js, render-time code generation, and runtime dependencies; its
[host agreement](https://github.com/kumwe/producer/blob/main/docs/host-agreement.md) records what a
host implements, what Producer promises, and the three-way pin protocol. Kumwe App is Producer's
first consumer, never its owner: nothing App-specific may enter that repository.

## What this application delegates to Producer

| Concern | Today in this repository | Direction |
| --- | --- | --- |
| Canonical JSON bytes and digests | Internal implementation | Producer's `Canonical\CanonicalJson`, proven against Studio's canonical corpus |
| `studio.profile/schema-property` admission and instance validation | `src/Studio/Domain/Contract/SchemaPropertyProfile.php` (the origin of Producer's port) | Producer's `Schema\SchemaPropertyProfile`, proven against the 62-vector corpus; the internal copy retires at the adoption pin |
| Request envelope parsing, the closed operation registry, the error taxonomy | Partial, App-shaped | Producer's wire layer; this application implements only the small authoritative port interfaces |
| Published-composition rendering across the full Studio block catalog | Four lossy renderers | Producer's renderer engine, proven against the renderer-web vectors, with semantic no-JavaScript fallbacks and a bounded unresolved-block fallback |
| Design tokens and layout vocabulary to public CSS | Preview-only token emitter plus a hand-authored public stylesheet | Producer's stylesheet generator, static and deterministic |
| The public enhancement runtime | Absent | A prebuilt, minified, content-hashed Studio release artifact; Producer computes the per-page need signal, this application serves the file |

What never moves: authorization and the deny-by-default gateway, session authority and generation
invalidation, artifact persistence and revisions, idempotency storage, media storage, workflow,
audit, Twig templates, and every route. Producer returns a structured render result — an HTML
fragment, a stylesheet reference, the enhancement-need flag, preload hints — and this
application's templates embed it like any other value.

## Adoption sequence, bound to the existing ladder

1. **Pin.** Producer enters `composer.json` at an exact version; a non-exact specifier for
   `kumwe/producer` or any `@kumwe/studio` artifact fails the build. Producer's own `PIN.json`
   names the Studio release it implements, so this application's signed release evidence records
   one coherent chain: App → Producer → Studio.
2. **Replace inward.** Where a ladder rung needs mechanics Producer proves — envelope validation,
   schema-property, rendering, stylesheets — the rung consumes Producer instead of growing an
   App-internal implementation, and duplicated internals retire in the same change that adopts
   their replacement. Retiring `SchemaPropertyProfile` is the first such change.
3. **Prove here regardless.** Producer's corpus replay does not discharge this repository's own
   proof obligations: the vendored-corpus conformance suites keep running here, over the adopted
   library, on every supported engine — a regression in the pair App+Producer must fail this
   repository's lane, not only Producer's.
4. **Raise mismatches upstream.** A need Producer's contract surface cannot express is a finding
   in `kumwe/producer` (or `kumwe/studio` when it is contractual), never an App-side fork or
   workaround; the pin advances only as a deliberate re-pin with its own evidence.

## What must not happen

- No composition mechanics re-implemented here once Producer proves them; duplication is drift.
- No Producer version range, ever, while Studio's contract is pre-release.
- No authoritative concern handed to Producer — its charter refuses them, and so does phase S.
