# Presentation preference runtime

This document maps the normative customization contract to the runtime types and persistence boundary. It
does not introduce another customization mechanism: every stored value remains one allowlisted slot from
[`presentation-preference.schema.json`](schemas/presentation-preference.schema.json) on a live, owner-bound
KIS surface.

## Runtime boundaries

| Concern | Authoritative type | Guarantee |
| --- | --- | --- |
| Portable record | `PresentationPreference` | Exact schema and KIS version, surface ownership, scope/slot compatibility, optimistic version and audit attribution |
| Slot value | `PresentationPreferenceValue` | Closed, bounded value grammar with no CSS, script, markup, template, URL, selector, component or policy channel |
| Durable identity | `PresentationPreferenceKey` | Exact surface/slot/scope identity and a database-neutral null-scope key |
| Live admission | `RegisteredPresentationPreferencePolicy` | Current contribution owner and exact declared customization permission must still match |
| Resolution | `PresentationPreferenceResolver` | Low-to-high hierarchy, safe fallback and stable diagnostics for stale owners or removed slots |
| Mutation use case | `PresentationPreferenceManager` | Scope authorization, compare-and-swap, transaction-coupled audit, validated import/export and reset |
| Persistence | `DoctrinePresentationPreferenceRepository` | Atomic create/update/delete and read-time revalidation across supported DBAL platforms |
| Schema | `InterfacePresentationPreferenceMigration` | Portable JSON value, optimistic version, attribution and composite identity columns |

The portable value, record and key live under `Kumwe\CMS\InterfaceStandard`. Application orchestration
lives under `Kumwe\CMS\Presentation\Application\Preference`, and the DBAL adapter lives under
`Kumwe\CMS\Presentation\Infrastructure\Persistence`; neither persistence nor delivery is part of the
portable KIS semantic contract.

## Resolution order

The caller supplies a KIS default and a `PresentationPreferenceContext` built from server-resolved request
state. Stored records replace the whole selected slot; they are not deep-merged.

| Rendered area | Applied layers, lowest to highest |
| --- | --- |
| Public site | KIS default, current site, authenticated user when present |
| Administrator | KIS default, current site, installation administrator, active role/workspace, authenticated user |
| Portal | KIS default, current site, active role/workspace, authenticated user |

A template is an implementation of these actor-facing areas and is not a separate preference context.
Role/workspace identity must come from current membership or another server-owned selection, never a request
field accepted without authorization.

If an upgraded or disabled extension no longer owns a stored surface, resolution ignores that record and
emits `kis.preference.owner-stale`. If the current surface no longer exposes the slot at that layer, it
emits `kis.preference.slot-removed`. In both cases the next valid lower layer remains effective. Invalid row
structure is corruption, not compatibility drift, and fails closed at repository hydration.

## Mutation and authorization

`PresentationPreferenceManager` is the only supported write boundary:

1. Parse or build a schema-valid value.
2. Require the live contribution owner to expose the exact slot and scope.
3. Bind site mutations to the execution-context site. Administrator records use the null global identity;
   role/workspace and user records require a named identity.
4. Permit a human actor to manage only its own user record; foreign-user and system-to-user writes are not
   supported by this foundation and fail closed.
5. Require `settings.manage` on the exact execution-context site for site records. Role/workspace records
   additionally require the exact workspace selected by a current live membership, revalidated with a write
   lock inside the mutation transaction.
6. Require installation-global `themes.administrator.manage` authority for the null-identity administrator
   layer. Site-scoped settings authority cannot alter that installation-wide layer.
7. Persist the exact next optimistic version and record the success audit event in one transaction.

Creates expect version `0` and store version `1`. Updates expect the last observed positive version and
store its exact successor. Reset remains available when a live declaration removed the slot: it requires
the stored owner and exact current version, deletes only that layer, and lets the next resolver call reveal
the lower layer or KIS default. A mismatch raises
`PresentationPreferenceVersionConflict`; it never performs last-write-wins replacement.

Exports include the portable record's compatibility, version and attribution fields and therefore use the
same authorization as a mutation. Import parses every source field, checks current surface and actor policy,
then rebases the value onto the destination's expected version and attributes it to the importing actor. The
source version, actor, timestamp and document digest are audit metadata; source attribution never impersonates
the destination update actor.

Audit events contain surface, owner, scope, scope identity, slot and version movement, but never the stored
preference value. The actions are:

- `interface.preference.create`
- `interface.preference.update`
- `interface.preference.import`
- `interface.preference.reset`

## Composition invariants

The foundation is registered in the production composition root but deliberately adds no route or user
interface. These invariants must remain true as delivery support is added:

- add `InterfacePresentationPreferenceMigration` to the ordered core `MigrationPlan` after the core schema;
- share `DoctrinePresentationPreferenceRepository` as `PresentationPreferenceRepository` using the existing
  `Connection` and `TableNames` services;
- construct `RegisteredPresentationPreferencePolicy` with
  `ExtensionContributionRegistrySet::interfaceSurfaces()`;
- share `PresentationPreferenceManager` with the canonical authorization gateway, live membership validator,
  audit recorder, clock and transaction manager;
- share `PresentationPreferenceResolver` with the same repository and live policy;
- build resolution contexts only from the current `SiteContext`, authenticated principal and validated
  membership/workspace state;
- keep browser delivery behind existing CSRF, authentication and idempotency conventions; no UI or route may
  write the repository directly;
- execute the unit, SQLite persistence, supported-database, backup/restore and template-reset qualification
  gates before enabling a graphical editor.

Delivery must also re-check that a requested slot is in the rendered surface declaration instead of trusting
hidden form fields or imported metadata. New slots require a KIS/schema version decision, a bounded value
validator, resolution behavior, migration/import policy and adversarial tests before they are admitted.
