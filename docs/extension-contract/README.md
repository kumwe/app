# The extension contract

This is the answer to the question an extension author has to ask before writing a line of code: **what
can I depend on, and what will be taken away from me?**

Two machine-readable documents answer it, and this page explains them:

| Document | What it settles |
| --- | --- |
| [`classification.json`](classification.json) | Which types are public, which are internal, and what you do with each. |
| [`generations.json`](generations.json) | What each supported manifest and contribution-SPI generation promises, which compatibility package proves it, and what has been withdrawn. |

Both are checked on every build by `composer extension:contract`, which runs inside `composer qa`. The
check is dependency-free, so it runs before `composer install`.

## Public and internal

Everything under `Kumwe\App\` is **internal** unless `classification.json` lists it. That is the default
and it is deliberate: the absence of a type from that file is the answer, not an omission. Internal code
may be renamed, split or deleted at any time, and a package that reaches for it will break without
warning and without recourse.

A type that *is* listed is **public**. It belongs to a generation, and it changes only by that generation
gaining a successor beside it.

Each entry says what you do with the type:

| Role | Meaning |
| --- | --- |
| `implement` | You write a class that satisfies the contract. |
| `call` | Core hands you an instance and you call it. |
| `construct` | You build instances and hand them to core. |
| `receive` | Core hands you an instance to read. |
| `resolve` | You resolve it from the restricted container you are handed. |

`pinned_by` names the compatibility fixture whose committed bytes hold that type's exact member
signatures. Where it is set, the shape is frozen to the byte — adding a method to a pinned interface is
treated as a breaking change, because every existing implementation would stop loading. Where it is
null, the type is public and governed by its generation, but its members are not byte-pinned at this
release; treat it as stable, and expect the pin to arrive rather than the promise to leave.

### What you may resolve from the container

The container your provider is handed is not the application container. It carries your own registered
services plus a fixed allowlist of host services, and nothing else — resolving anything outside the list
fails closed at runtime. The allowlist is in `generations.json` under `host_services`, and it is short on
purpose. Their identifiers are frozen; the method surface behind each is public but not byte-pinned yet.

## Generations

A **manifest generation** is a `schema` number in `kumwe.json`. A **contribution SPI generation** is the
`contributions.version` number inside it. They move together but are versioned separately, because the
manifest grammar and the runtime registrar are two different things to break.

| Manifest | SPI | What it added |
| --- | --- | --- |
| `schema: 1` | — | Identity, requirements, autoload, migrations, assets, and a provider that registers its own services, boots and declares its own routes. No typed contributions. |
| `schema: 2` | `1` | Typed contributions: capabilities, resource policies and the administrator shell. The manifest becomes closed to unknown keys. |
| `schema: 3` | `1` | The portal surface, safe field presentation, and custom business view and action handlers. |
| `schema: 4` | `2` | Durable integration — event contracts, listeners, consumers, jobs, queues, schedules, projections, reports, webhooks, money rate providers — and KIS semantic surfaces bound to every graphical route. |
| `schema: 5` | `3` | The declarative composition contract, frozen at Gate A ahead of the Gate B surface that consumes it: blocks with bounded property schemas, slots and renderer bindings, patterns, field controls, inspectors, design vocabularies including size roles, and composition migrations. |

Every one of those five is still promised. A package on schema 1 installs on this release exactly as it
did, and nothing obliges you to move.

### What a generation guarantees

1. **It parses.** A manifest declaring that schema is accepted, with the key set that generation
   declared and no other. From schema 2 onward an unknown key is a refused install, not a shrug.
2. **It installs, activates, upgrades, disables, reactivates and uninstalls.** Each generation ships a
   signed compatibility package that is driven through the whole sequence on every build.
3. **Its surface does not shrink.** What the package contributed before an upgrade is what it contributes
   after, entry for entry.
4. **Its types stay classified public.** A type reachable from a generation is not quietly demoted.

The guarantee is about the generation, not about the release: core will add generations, and a later
generation may promise more. It will not promise less under the same number.

### How to target one

Declare the schema you want, and the SPI it binds to:

```json
{
  "schema": 3,
  "name": "acme/orders",
  "type": "component",
  "version": "1.0.0",
  "provider": "Acme\\Orders\\Provider",
  "autoload": {"psr-4": {"Acme\\Orders\\": "src/"}},
  "requires": {"kumwe": "^2.0.0", "php": "^8.5.0"},
  "contributions": {"version": 1, "capabilities": []}
}
```

Declaring the wrong SPI for the schema is refused rather than corrected: schema 2 and 3 require
`contributions.version` 1, schema 4 requires 2, schema 5 requires 3. That is the point of the pairing —
a package built against a later runtime cannot install itself into an older one by accident.

Pick the **lowest** generation that carries what you need. Nothing is gained by moving to schema 4 for a
package that only contributes an administrator workspace, and staying low keeps your package installable
on more sites.

Several registrars are **additive** and must be feature-detected rather than assumed — the KIS surface,
money rate, content translation, unit conversion, and composition registrars all arrived this way:

```php
if ($contributions instanceof InterfaceSurfaceRegistrar) {
    $contributions->interfaceSurface($surface);
}
```

That is how a provider written against the base registrar keeps working when a new registrar appears
beside it.

### Manifest keys that are not what they look like

Three keys are accepted and do nothing, and they are recorded that way rather than removed, because
removing them would break packages that already ship them:

| Key | Status | What actually happens |
| --- | --- | --- |
| `routes` | advisory | Reported by `extension:inspect`. It mounts nothing. Mount routes from `RuntimeExtension::registerRoutes()` or contribute `administrator.routes`. |
| `events` | advisory | Labels one line in the contribution summary. It subscribes nothing. Register listeners through `ExtensionEventRegistrar` in `boot()`. |
| `configuration` | accepted, uninterpreted | Nothing reads it. |
| `permissions` (schema 1 only) | advisory | Nothing grants it. From schema 2 it must match the contributed capability identifiers exactly, order included. |

## What has been withdrawn

`generations.json` carries a `withdrawn` list. An entry there names a type that was once part of the
extension-facing surface, the commit that removed it, and why. The build check refuses to let a withdrawn
type quietly come back, and refuses to let one be recorded as withdrawn while it still exists.

If you built against something on that list, the entry tells you what to do instead.

## The compatibility packages

Each generation names a compatibility package under
[`tests/Fixtures/ExtensionApi/generations/`](../../tests/Fixtures/ExtensionApi/generations). They are
not examples to copy — [`examples/extensions`](../../examples/extensions) holds those. They are probes:
each declares the smallest thing its generation is *for*, and every build

1. builds it twice and requires the same bytes,
2. runs the code-free static conformance gate over the archive,
3. signs the package digest with `PackageSigner` and the fixture key published in `generations.json`,
4. admits it through `PackageTrustPolicy` over `SodiumEd25519Verifier` — the production trust path,
5. drives the install plan to commit, then activates, upgrades, disables, reactivates and uninstalls,
6. and compares the contributed surface at every step against what the generation promises.

The signing key is a fixture key and is deliberately derivable from a written stem, so nothing secret is
committed and anyone can rebuild and re-sign the packages. It proves the signing and admission path, not
provenance; a release key proves provenance and lives outside the repository.

One detail of the lifecycle is worth knowing before you meet it in production: **an upgrade leaves the
extension disabled**, even if it was active. That is not a bug in the fixture. An operator re-activates
against the new code deliberately, so a bad release cannot re-enter service unattended.

## Changing the contract

- **Adding a generation** is the ordinary way forward. Add an entry to `generations.json`, ship its
  compatibility package, and leave every existing entry alone.
- **Changing a frozen generation** fails the build. Each entry carries a `surface_digest` over its own
  canonical bytes; the check recomputes it. If the change really is deliberate, record the new digest in
  the same change that makes it, so it appears in the diff and in review.
- **Withdrawing something** means moving it to the `withdrawn` list with a reason, not deleting the line.

Internal code needs none of this. That is the trade the classification exists to make: a small surface
that is genuinely fixed, and everything else free to move.
