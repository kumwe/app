# ADR 0019 — Laminas ServiceManager and EventManager run composition and events behind Kumwe-owned seams

**Status** Superseded for extension events by the pre-stable canonical SDK extraction
**Decided by** Product owner
**Findings** None; the preservation covenant requires a separately approved architecture decision before
the platform stack moves, and this record is that decision
**Gate** None — Gate A remains passed and untouched
**Verified against** `670703a9` and `2821aaf0`, plus the change that removes `joomla/di` and
`joomla/event` from `composer.json`

> The application still uses Laminas EventManager for its private lifecycle notifications, but those
> notifications are no longer extension-author API. Signed manifests are the sole declarative source and
> extension executable behavior binds by manifest identifier through `kumwe/extension-sdk`; there is no
> code-side event registrar or alternate package identity.

---

## Context

Version 2 opened on six Joomla Framework packages. Two carried runtime roles: `joomla/di` was the service
container behind `ContainerFactory`, and `joomla/event` was the in-process dispatcher behind the
`onKumweExtension*` lifecycle events. The other four — `joomla/archive`, `joomla/filesystem`,
`joomla/filter` and `joomla/registry` — were installed but referenced by nothing under `src/`, `tests/`
or `tools/`: app-owned implementations took their places as the codebase grew, and the packages remained
in the lock file as audit and upgrade surface without a consumer.

The rest of the platform already comes from one ecosystem. Mezzio owns the PSR-15 pipeline, Laminas
Diactoros owns PSR-7 messages, and mezzio-fastroute, mezzio-twigrenderer, laminas-authentication and
laminas-httphandlerrunner sit around them. The container and the dispatcher were the last two runtime
components sourced from a second framework, and each has a maintained equivalent inside the ecosystem the
HTTP stack already trusts: `laminas/laminas-servicemanager` and `laminas/laminas-eventmanager`.

Two Kumwe surfaces constrain how the engines may move:

- **The composition root speaks `share()` and `alias()`.** `ContainerFactory` carries 549 registrations
  in that vocabulary, every factory closure type-hints the container it receives, and the covenant
  forbids a second composition root. An engine change must not become a rewrite of the composition
  root's grammar.
- **The pre-stable extension listener surface leaked the vendor.** Its code-side listener callable named
  `Joomla\Event\EventInterface`, so an author would have named the engine vendor in an executable signature.
  The canonical SDK extraction removed that author-facing path: signed manifest declarations now own
  extension behavior and owner-scoped SDK binding ports bind executable implementations by declaration ID.

There is precedent. [Persistence](../../architecture/persistence.md) records that `joomla/database` left
in favour of Doctrine DBAL behind application-owned repository and transaction interfaces: the engine
changed and the contracts above it did not. This decision applies the same shape to composition and
events, and it is the separately approved change the preservation covenant's platform paragraph demands.

## Decision

1. **Laminas ServiceManager is the container engine, behind an app-owned wrapper.**
   `Kumwe\App\Kernel\Container` is a final PSR-11 class that composes a
   `Laminas\ServiceManager\ServiceManager` — the ServiceManager is `@final` in Laminas 4, so composition
   rather than subclassing is the only supported shape — and passes itself as the creation context, which
   is what keeps every factory closure's `Container` type-hint working unchanged. The wrapper keeps the
   composition root's registration vocabulary: `share()` sends a closure to a lazily invoked factory and
   any other value to a ready instance, `alias()` maps one identifier onto another, and everything
   resolves as a shared singleton because the ServiceManager shares by default.
2. **Overwrite protection is the container-wide stance.** `allowOverride` stays false, so re-registering
   a pre-built instance throws immediately and re-registering a factory-backed entry throws once that
   entry has been resolved. This is a hardening, not a behaviour change: registration is single-pass at
   boot inside `ContainerFactory`, so no legitimate path registers an identifier twice, and the stance
   exists so that a future second registration is an error rather than a silent replacement. `share()`
   keeps its `$protected` parameter for the registration vocabulary; every entry is protected alike.
3. **Laminas EventManager is a private host dispatch engine.** It carries App lifecycle notifications
   behind infrastructure adapters and is not extension-author API. Extensions declare domain listeners,
   integration consumers, jobs, projections, webhooks, and routes in signed manifests. After admission,
   the host accepts only owner-scoped executable bindings for those exact declaration identifiers through
   canonical SDK ports; no code-side App event registrar is published.
4. **PSR-14 is evaluated and deferred for private host dispatch.** Laminas ships no PSR-14 implementation
   to stand on, and the App needs no public dispatcher abstraction. A future host-engine change remains an
   infrastructure decision because signed declarations and SDK executable binding ports, rather than the
   private event engine, define the author contract.
5. **The Joomla Framework leaves the requirement blocks, permanently.** `joomla/archive`,
   `joomla/filesystem`, `joomla/filter` and `joomla/registry` are removed as unused (`2821aaf0`);
   `joomla/di` and `joomla/event` follow once the test suite is retyped. `composer architecture:policy`
   then holds the line: any `Joomla\` reference in first-party PHP and any `joomla/*` package in a
   `composer.json` requirement block fails the gate. The byte-immutable extension-API generation fixtures
   and prose history in `docs/` and `CHANGELOG.md` are exempt — history is allowed to be true.
6. **The dependency-selection policy reverses.** "Prefer a maintained Joomla Framework component" was the
   Version 2 default; the default is now a maintained Laminas or Mezzio component, another maintained,
   focused package only with explicit justification, and Symfony and Laravel still excluded as direct
   production dependencies. The Joomla-flavoured phpDocumentor documentation style is unaffected — it
   names the shape of a documentation block, not a dependency.

## Alternatives rejected

### Subclass the ServiceManager instead of wrapping it

Rejected. The class is `@final` in Laminas 4: a subclass builds on an explicitly unsupported extension
point and inherits the whole ServiceManager API as accidental public surface. The wrapper exposes exactly
the four members the composition root and its factories use.

### Rewrite `ContainerFactory` into native ServiceManager configuration

Rejected. Retyping 549 registrations into factory/alias configuration arrays buys no capability, discards
the registration grammar every line of the composition root already speaks, and risks silent drift in
sharing and construction order. The vocabulary is the stable contract; the engine underneath it is what
this decision changes.

### Adopt PSR-14 class-keyed host events now

Rejected, as decision point 4 records: there is no Laminas implementation to lean on and no author-facing
dispatcher contract that requires another event abstraction.

### Expose the Laminas event and container types in the SPI

Rejected. It repeats the defect this migration pays for — the vendor in a listener signature meant the
engine could not move without touching every published extension. The Laminas `EventInterface` also
grants target and parameter mutation the SPI deliberately withholds.

### Keep `joomla/di` and `joomla/event`

Rejected. It keeps a second framework resident for two roles the platform's own ecosystem covers, and it
doubles the dependency, audit and upgrade surface under the two most central runtime seams for no
capability in return.

## Consequences

- The composition root is unchanged in grammar: the 549 `share()`/`alias()` registrations and every
  factory closure compile against the same `Kumwe\App\Kernel\Container` type they always named.
- Overwrite protection is container-wide rather than per-key, so any future double registration fails at
  boot instead of silently replacing a service.
- The extension SPI is vendor-free because signed manifest declarations and canonical SDK binding ports
  contain no App or engine type; private lifecycle dispatch can change without moving an author contract.
- The preservation covenant's platform paragraph now names Laminas ServiceManager and Laminas
  EventManager behind the Kumwe seams, citing this record as the approving decision.
- `composer.json` carries no `joomla/*` requirement and no `joomla-framework` keyword, and the
  architecture policy gate fails any return of either, alongside any first-party `Joomla\` reference.
- With `joomla/database` → Doctrine DBAL and now this, every runtime engine sits behind a contract Kumwe
  owns, and the next engine migration — should one ever be required — has two precedents for its shape.
