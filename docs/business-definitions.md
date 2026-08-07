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

Fields carry required/null/default rules, length or exact precision and scale, normalizers, typed configuration, validators, uniqueness and index intent, immutability, server/read-only rules, visibility, search/filter/sort/report/export use, sensitivity, localization, help, grouping, ordering, and placements. Built-in types cover UUID and external identities, plain and rich text, integer, exact decimal, money, quantity, boolean, choices, date/time variants, email, URL, phone-like text, media/entity references, embedded values, ordered lines, bounded JSON, encrypted secrets, and server-computed values.

Decimal, money, and quantity values are represented as canonical base-10 strings and require precision and scale. Definition parsing rejects PHP floats. Secret fields require secret sensitivity and cannot be searched, filtered, sorted, reported, or exported.

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

Install and upgrade synchronize declarations inside the extension transaction. Activation makes them available; disablement, quarantine, emergency trust revocation, and uninstall make them inactive. Catalog rows, canonical versions, checksums, compatibility plans, and ownership remain available for audit and restore after uninstall. Removing a declaration deprecates its last published entity version instead of erasing history.

The announcements example contributes a severity field type and related category and announcement definitions. It is the conformance example for package declaration, provider registration, inverse relationships, views, actions, workflow, lifecycle visibility, and history preservation.

## Persistence, backup, and scope

Doctrine DBAL creates only definition metadata tables: field types, catalog heads, drafts, immutable versions, and dependency edges. The same migration and repository run on MariaDB, MySQL, and PostgreSQL. Complete database backups include these tables automatically; clean-target restore drills compare definition and version counts so publication history is part of recovery acceptance.

This runtime stops at definition authoring and publication. It does not implement business-record tables, generic business-record CRUD, a REST or MCP record API, or dynamic record execution. A later runtime may consume published definitions only through an explicit, separately reviewed storage and application boundary.

## Verification contract

The test suite covers canonical checksums, exact arithmetic, expression fuzzing and limits, cycles and graph invariants, compatibility classification, graphical form mapping, authorization, optimistic revisions, immutable publication, extension lifecycle and trust quarantine, portable migrations, database backup/restore, architectural separation, and Playwright accessibility and visual evidence.

Before release, run `composer qa`, `npm run check`, `npm run build`, and `npm run test:browser`. The database and deployment workflows repeat integration and recovery acceptance on all supported engines.
