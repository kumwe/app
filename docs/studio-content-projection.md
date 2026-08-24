# Studio content projection

Kumwe exposes its CMS content types and entries to Studio through a read-only application service.
Studio receives canonical `content-model` and `entry` documents; Kumwe remains authoritative for
content definitions, field visibility, workflows, translations, publication windows, and stored
values. This is the AP-2 model-port foundation. It does not open a Studio session and it does not add
any composition or Content write path.

The implementation boundary is:

| Concern | Owner |
|---|---|
| Studio document schemas and canonical JSON | `Studio\Domain\Contract` over the exact vendored corpus |
| Authorized Content reads | `ContentModelService` and `ContentService` |
| Projection rules and diagnostics | `Studio\Application\Projection` |
| Blueprint bindings and entry overrides | host-owned Studio projection tables |
| Composition engine, commands, and session behavior | Studio packages, not Kumwe PHP |

## Application surface

`StudioContentProjectionService` is the only model-port-facing service. Its three operations are:

- `models(context)` lists models already admitted by `ContentModelService`;
- `model(context, modelId, version)` resolves one reversible Studio coordinate through that service;
- `entry(context, entryId)` resolves an entry, its exact pinned content-type version and, for a
  custom workflow, its exact workflow version through the existing Content services.

The projection service never reads Content repositories directly. A denied or absent addressed model
or entry produces the same `unavailable` refusal, so the port cannot be used as an identifier oracle.
Collection denial is collapsed to that refusal too. A field-disclosure interface makes description
and value disclosure separate decisions; a denied field is omitted and no diagnostic names it. The
current implementation admits fields only after Content's existing record-level authorization because
Content does not yet define a narrower field policy. Adding such a policy replaces that implementation
instead of changing the projector.

## Reversible coordinates and values

| Content fact | Studio projection | Reverse rule |
|---|---|---|
| content-type UUID | `content-model:<lowercase UUID>` | reserved prefix plus canonical UUID |
| content-type version `N` | semantic version `0.0.N` | only that reserved semantic-version line decodes |
| content-type revision | `content-type-vN` | immutable definition version |
| entry UUID | `content-entry:<lowercase UUID>` | reserved prefix plus canonical UUID |
| entry optimistic version `N` | revision `content-entry-vN` | only that reserved revision form decodes |
| title | `values.title` string | byte-for-byte stored string |
| slug | `values.slug` string | byte-for-byte stored string |
| data field `x` | model/entry member `data_x` | remove the reserved prefix; the source key is also carried in the field extension |
| locale | `locale` | the authoritative normalized `LocaleTag` |
| translation group UUID | `translationOf = content-translation:<UUID>` | reserved group prefix plus UUID |
| workflow state key | reversible hex-qualified `workflowState` and exact extension value | decode the hexadecimal bytes |
| publication window | exact microsecond RFC 3339 strings in the entry extension | null or the authoritative boundary instant |

The portable entry `status` is a lifecycle summary. Built-in states map exactly to `draft`,
`in-review`, `published`, and `archived`. A custom workflow's public state maps to `published`, its
initial private state to `draft`, and another private state to `in-review`; the exact custom key and
workflow ID/version remain authoritative and reversible in `workflowState` and the entry extension.
An undeclared state refuses the whole projection.

Strings are never reinterpreted. In particular, a Content body whose schema says `string` remains a
string; the projector never guesses that it is Studio rich text, HTML, Markdown, or a URL. Integers,
finite decimals, booleans, lists, and closed objects recurse according to the exact Content schema.
Open objects, unions, nested collections that Studio cannot express, unknown entry members, non-string
enum identifiers, or a value requiring coercion refuse the whole document. The refusal is typed as
`invalid-identifier`, `unavailable`, `unsupported-field`, `lossy-value`, or `invalid-document`, with a
safe JSON Pointer and no source value. Stored data is revalidated against its exact pinned Content
schema before mapping, so corruption cannot become a plausible partial Studio entry.

Every completed projection is validated by `StudioContractSchemas` against the exact vendored
`content-model` or `entry` schema before it leaves the application service. The same neutral registry
continues to validate extension contribution documents. The former extension-internal helper names
remain loadable aliases, and its registry remains a contribution-only compatibility adapter; new code
uses `Studio\Domain\Contract`.

## Blueprint bindings and entry overrides

Composition is optional and does not change Content's meaning. Two host-owned stores capture its read
coordinates:

- `studio_content_blueprint_bindings` pins one immutable content-type version to an exact Blueprint
  ID/version and optional Blueprint revision, with its own binding revision;
- `studio_entry_composition_overrides` holds one canonical JSON object per Content entry, keyed by
  Studio stable identifiers, with its own override revision.

Both repositories require the server-resolved site in every query. Composite foreign keys include
that site and the authoritative Content coordinate, preventing tenant metadata from being attached to
another site's definition or entry. Replay repairs an interrupted partial-table creation. Domain values
revalidate UUIDs, Studio identifier grammars,
semantic versions, member limits, depth, forbidden property names, and canonical JSON before a row can
enter a projection. A binding appears in the model extension. Override values appear only in
`entry.compositionOverrides`, and their host revision appears separately in the entry extension.

The repository port deliberately contains reads only. AP-2 has no authorized, audited publication use
case for these coordinates, and adding a generic save method here would create an AP-3/AP-4 write path
before Studio's session-generation and optimistic artifact contracts are available. The later write
service must supply that authorization, audit, expected-revision, and replay discipline explicitly.

## Business-record deferral

No BusinessRecord adapter is included. The business runtime has a separate definition vocabulary,
record policy compiler, exact-decimal and structured value codecs, relationship model, and projection
purposes. Reusing the Content projector would either import the two contexts into each other or bypass
BusinessSecurity's query and field-disclosure plan. The existing architecture tests forbid that
coupling.

A later BusinessRecord model-port adapter is safe only when it has its own application service that:

1. obtains definitions and records through `BusinessSurfaceCatalog`/BusinessRecord application reads;
2. applies the purpose-specific BusinessSecurity projection before pagination, counts, relations, or
   values are exposed;
3. maps exact decimal, money, quantity, encrypted, computed, relationship, and owned-line shapes with
   their own typed loss rules; and
4. shares only the neutral Studio contract validator and diagnostic vocabulary with the Content
   adapter.

Until then, Content and BusinessRecord remain parallel bounded contexts with no cross-imports and no
generic "record" abstraction joining them.
