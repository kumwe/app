# Business definitions

Business definitions are Kumwe's typed, versioned contract for operational entities. They describe entity identity, exact fields, relationships, views, actions, workflow bindings, delivery exposure, ownership, and compatibility. They are deliberately separate from CMS content types and do not create a generic record API, EAV store, or universal JSON record table.

Open **Structure → Business definitions** in the administrator. An administrator with `content.update` can create a site-owned draft with graphical controls, validate it, inspect the compatibility plan, and publish an immutable version. A user with `content.read` can inspect and export definitions and their version history. Package-owned definitions are read-only and follow their extension's trust and activation lifecycle.

## Definition contract

Every entity has:

- a UUID definition ID, stable namespaced handle, explicit site and owner;
- one declared identity strategy and matching identity field;
- relational storage mode and installation, site, organization, or combined scope;
- audit and revision policy;
- bounded field, relationship, view, action, and workflow collections;
- explicit administrator, portal, and public exposure.

Portal exposure also carries a closed per-operation allow-list that defaults to empty. Browse, read, create,
update, archive, delete, restore, history, relation, reorder, action, approval, report, export, and status are
independent choices; a form view or portal-visible action never implies another operation. Optional custom
view/action handler and schema references are owner-namespaced signed contributions, omitted from canonical output
when absent so historical checksums remain stable.

Fields carry required/null/default rules, length or exact precision and scale, normalizers, typed configuration, validators, uniqueness and index intent, immutability, server/read-only rules, visibility, search/filter/sort/report/export use, sensitivity, localization, help, grouping, ordering, and placements. Built-in types cover UUID and external identities, plain and rich text, integer, exact decimal, money, quantity, boolean, choices, date/time variants, email, URL, phone-like text, media/entity references, embedded values, ordered lines, bounded JSON, encrypted secrets, and server-computed values.

Decimal, money, and quantity values are represented as canonical base-10 strings and require precision and scale. Definition parsing rejects PHP floats. Secret fields require secret sensitivity and cannot be searched, filtered, sorted, reported, or exported.

## Document views

A view of kind `document` renders a record as a business document — an invoice that looks like an
invoice — on the generated administrator and portal record pages. It is declared inside the entity like
every other view, travels in the canonical checksummed bytes, and carries an optional typed `document`
block naming which declared parts play which documentary role:

```json
{
  "handle": "invoice_document",
  "label": "Invoice document",
  "kind": "document",
  "fields": ["invoice_number", "issued_on", "due_on", "subtotal", "tax", "total"],
  "administrator": true,
  "portal": true,
  "public": false,
  "document": {
    "identity": "invoice_number",
    "groups": [{"label": "Invoice dates", "fields": ["issued_on", "due_on"]}],
    "parties": [{"label": "Billed to", "relationship": "client"}],
    "lines": "lines",
    "totals": ["subtotal", "tax", "total"]
  }
}
```

Every role is optional and validated against the entity: `identity`, group fields, and `totals` must
name declared non-UUID fields inside the view's own `fields` projection; `lines` must name a declared
`owned_line_collection` relationship; every party must name a declared `many_to_one` relationship. A
document view cannot bind a custom handler — it always uses the generated rendering path — and the
`document` block is omitted from canonical output when absent, so historical checksums stay stable.

When a definition declares a generated document view for a surface, that surface's record detail page
renders the document layout instead of the generic field list: a header with the definition label and
the record's human identity (the `identity` field's value, falling back to the label plus the record
date — never the UUID, which stays in the URL only), the party cards and meta groups, the owned lines
as one table whose columns come from the line definition's first generated list view, and the totals
block. The lines hydrate through the same bounded relationship read as the relationship route, all rows
on one page, and the same record policy and disclosure filter every role — a withheld field or
relationship is simply dropped. The relations, actions, and history workspaces keep their existing
rendering, and browser print yields a clean paper document: screen chrome carries print-hidden styles
in the shared generated-business stylesheet.

The shipped VDM demonstration declares document views on its invoice and quotation definitions; see
`resources/demo/business/vdm/definitions/invoice.json` for the reference declaration.

## Allocated document numbers

A field of type `core.sequence` carries a number the server reserves, not one a caller sends. It is the
answer to the requirement that invoices, quotations, credit notes and delivery notes carry exact unique
numbers: a unique index can refuse a duplicate, but it cannot produce the next number and it cannot make
a run contiguous.

The declaration is closed by construction — `BusinessDefinitionValidator` refuses a `core.sequence` field
that is not `server_only`, `read_only`, `immutable_after_create`, `required`, non-null and `unique`, that
declares a default or a formula, or that is narrower than the widest number its format can render. Its
`configuration` chooses the counter and the printed form:

| Key | Values | Meaning |
| --- | --- | --- |
| `scope` | `site` (default), `organization` | Tenancy boundary the run is contiguous within. |
| `reset` | `never` (default), `yearly`, `monthly` | Calendar boundary the run restarts at. |
| `prefix` | up to 16 of `A-Z`, `0-9`, `-`, `/` | Literal head of the number, such as `INV-`. |
| `padding` | 1 to 12 | Digits the counter is zero-padded to. |
| `timezone` | IANA identifier, default `UTC` | Zone the reset boundary is judged in. |

`INV-`, `yearly`, `6`, `Africa/Windhoek` renders `INV-2026-000001`, and the first invoice raised after
midnight local on 1 January is `INV-2027-000001` even while UTC is still in the old year.

**The guarantee is gapless-on-commit, and it is worth stating precisely.** Within one counter — site,
definition, field handle, scope key and period key together — the numbers on *committed* records run
contiguously from one, with no duplicates and no gaps. A command that rolls back for any reason consumes
nothing, because the counter is advanced inside the record command's own transaction, behind the mutation
fence and after the record access plan. A replayed idempotent command allocates nothing and returns the
number the original stored.

**What it is not.** Gaplessness is a property of allocation, not of the row surviving: hard-deleting a
numbered record, or an operator editing `business_number_sequences`, leaves a hole that nothing will fill,
because a number is never re-used. Archiving and soft-deleting keep the number. And contiguity has a
price — allocation holds an exclusive lock on the counter row until the enclosing transaction commits, so
concurrent creates against one counter run one at a time. That is the reason `scope` and `reset` are worth
choosing deliberately: a per-organization yearly counter contends only with its own branch and its own
year. A monotonic-with-gaps allocator would scale further, and it was rejected here because a numbering
run a tax authority can audit is worth more to a business than the throughput of one document type.

The mechanism is the one already proven on this schema — take the counter row `FOR UPDATE`, advance it
with a compare-and-set that must affect exactly one row — and it behaves identically on MariaDB, MySQL,
PostgreSQL and SQLite. On SQLite the lock clause compiles away, and contiguity rests instead on SQLite
admitting one writer at a time plus the compare-and-set, which refuses rather than duplicates. Contention
of any kind — a held row, a lock wait, a deadlock, or two commands creating the same counter for the first
time — is reported as temporarily unavailable, and the record service replays the whole command.

The shipped VDM demonstration uses this for both of its document types; see
`resources/demo/business/vdm/definitions/invoice.json` and `quotation.json` for the reference declaration.

## Safe expressions

Computed formulas and conditions are a typed AST. Supported nodes are literals, field references, comparisons, boolean logic, exact numeric arithmetic, concatenation, coalescing, conditional selection, null tests, membership, and containment. Parsing is deterministic and enforces maximum bytes, depth, operation count, arity, types, and field dependency cycles.

Definitions never evaluate PHP, SQL, Twig, JavaScript, shell text, or another embedded language. The administrator exposes bounded operator and field selectors rather than a code or raw-JSON formula editor.

## Validation and publication

Saving a draft validates its internal field graph before persistence and uses an optimistic draft revision. Graph validation also checks active field types, relationship targets, site boundaries, inverses, delete behavior, and owned-relationship cycles. Invalid imports and graphical submissions are rejected without creating a catalog row.

Exported published site definitions can be imported as new version-zero drafts; the import boundary normalizes only publication status and version, then revalidates the complete contract. Replacing an existing definition requires its current draft revision, including revision `0` when starting from a published head, so imports cannot silently overwrite concurrent work.

Comparison classifies changes as additive, compatible constraint tightening, behavior-changing, data-migration-required, or destructive. The machine-readable plan contains the old and next version, both checksums, confirmation/destructive flags, and path-specific changes. Publication requiring confirmation cannot proceed until the user explicitly confirms it.

Publication writes, in one transaction:

- the immutable canonical payload and SHA-256 checksum;
- the deterministic field/entity/field-type dependency graph;
- the machine-readable compatibility plan;
- publisher and timestamp metadata;
- queryable dependency edges and the catalog head.

Prior versions and their checksums remain immutable. Supersede, deprecate, and reject are lifecycle states stored outside the canonical payload so status changes do not rewrite version bytes.

## Extension contributions

Schema-2 packages may declare `contributions.business.field_types` and `contributions.business.definitions`. Their provider registers the identical typed objects through `fieldType()` and `businessDefinition()`. Identifiers must live under the extension namespace, field-type bytes cannot change under an existing identifier, and entity versions must advance by exactly one.

An extension-specific view or action may add a `handler` and `schema` reference as a pair. Both references must
belong to the definition owner and resolve to a signed `contributions.business.view_handlers` or
`action_handlers` contract registered by the provider. A workflow-transition action cannot also name a custom
handler. Omitting the pair preserves the generated view/action path and omits both keys from canonical output, so
older published definition checksums remain stable. Custom schemas are closed and bounded; input is checked before
extension code runs and result data is checked before any delivery surface receives it.

Install and upgrade synchronize declarations inside the extension transaction. Activation makes them available; disablement, quarantine, emergency trust revocation, and uninstall make them inactive. Catalog rows, canonical versions, checksums, compatibility plans, and ownership remain available for audit and restore after uninstall. Removing a declaration deprecates its last published entity version instead of erasing history.

The schema-3 announcements example contributes a severity field type with a signed safe presenter and related
category and announcement definitions. It is the conformance example for package declaration, provider
registration, inverse relationships, views, actions, workflow, lifecycle visibility, and history preservation.

## Persistence, backup, and scope

Doctrine DBAL creates only definition metadata tables: field types, catalog heads, drafts, immutable versions, and dependency edges. The same migration and repository run on MariaDB, MySQL, and PostgreSQL. Complete database backups include these tables automatically; clean-target restore drills compare definition and version counts so publication history is part of recovery acceptance.

Publication now feeds the separately authorized [transactional business runtime](business-runtime.md). It persists
a deterministic schema plan but never runs DDL. The installed schema and one `BusinessRecordService` application
boundary consume immutable versions. Business records have no generic REST, CLI, MCP, portal, or administrator
adapter.

## Verification contract

The test suite covers canonical checksums, exact arithmetic, expression fuzzing and limits, cycles and graph invariants, compatibility classification, graphical form mapping, authorization, optimistic revisions, immutable publication, extension lifecycle and trust quarantine, portable migrations, database backup/restore, architectural separation, and Playwright accessibility and visual evidence.

Before release, run `composer qa`, `npm run check`, `npm run build`, and `npm run test:browser`. The database and deployment workflows repeat integration and recovery acceptance on all supported engines.
