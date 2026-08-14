# ADR 0001 — Resource ownership is held at a scope, not shared through lists

**Status** Accepted
**Decided by** Product owner
**Verified against** `26a7b3963c255064754f541dc8286e75dd566b1f`
**Findings** `V2-GRP-001` through `V2-GRP-006`
**Gate** A

---

## Context

Version 2 must support a **business-group installation**: several related businesses — on the order of
four — on one installation, selling different things, sharing staff, clients, products and payroll master
data *by explicit choice*, with accounting isolated per business but reportable in consolidated form.

Multi-tenant service to unrelated tenants is **explicitly out of scope for Version 2** and sits at version
3 or 4. Nothing in this decision is a step toward it, and nothing here should be justified by it.

The decision is time-critical because it shapes the data model extension authors build against at Gate A.
Retrofitting it after extensions exist would mean migrating live business records.

### What exists today, verified

- **`resource_site_ownership`** has primary key **`(resource_type, resource_id)`**
  (`ApplicationAuthorizationMigration` lines 91–107). One owner per resource is therefore already a
  structural property of the schema, not a convention.
- **`DoctrineResourceSiteOwnership::lookup()`** reads `o.site_identifier` with an inner join to `sites` on
  `s.enabled = ?`. A disabled site's resources stop resolving, and a resource with no row is reported as
  unowned rather than credited to the caller. That is the fail-closed behaviour every cross-site denial
  rests on.
- **`DenyByDefaultAuthorizationGateway::evaluate()`** lines 240–243 make the decision with a single string
  equality:
  ```php
  $globalGrantRequired = $resourcePolicy->installationGlobal;
  if (!$globalGrantRequired && $owner->identifier() !== $context->site()->identifier()) {
      return new AuthorizationDecision(false, 'core.site-ownership.v1', 'resource_site_mismatch');
  }
  ```
- **`ResourcePolicyDefinition::$installationGlobal`** already exists, but it is a property of a *resource
  type* in the policy registry, not a scope of a *resource instance* in the ownership registry. The two are
  different things and must be reconciled rather than duplicated.
- **`ScopeMode`** (`Installation`, `Site`, `Organization`, `SiteOrganization`) partitions the *storage* of
  a business entity's records and emits control columns. It is not resource ownership and must not be
  confused with it.
- **No group concept exists anywhere.** A grep for group, consolidation or franchise across the
  authorization and site modules returns nothing relevant.
- **22 files inject `ResourceSiteOwnershipWriter`, with 25 `record()` call sites.** That is the blast
  radius of any signature change on the port.

## Decision

### 1. Ownership is held at a scope

Every resource keeps **exactly one owner**. What widens is the *level* the owner may be at:

| Level | Meaning |
|---|---|
| **Site** | One site owns the resource. Identical to today's behaviour. |
| **Group** | A named, declared set of sites owns the resource. |
| **Installation** | The installation owns the resource. |

This is a **widening of the existing registry**, not a parallel mechanism. The `site_identifier` column
becomes a scope reference; the primary key `(resource_type, resource_id)` is unchanged, so one owner per
resource remains structurally enforced. `DoctrineResourceSiteOwnership` keeps its fail-closed contract: a
resource with no row is unowned, and a scope whose sites are all disabled resolves to nothing rather than
to the caller.

The gateway's single equality becomes a single containment test — *is the caller's site inside the
resource's owning scope* — and for a site-scoped owner that containment test **reduces to exactly the
equality it replaces**. Every existing isolation test therefore continues to describe correct behaviour
unchanged, which is the property that makes this widening safe.

### 2. Selective sharing comes from named groups

A group is a declared, named set of sites. A resource owned by a group is visible to the members of that
group and to nobody else. Both inclusion and exclusion are explicit.

**Groups may overlap.** Sites A, B and D may share clients while A and C share products. That is the point
of naming groups rather than deriving them from a hierarchy: a business group's sharing is not a tree.

### 3. Each resource category declares the scopes it may be owned at

| Category | Site | Group | Installation |
|---|---|---|---|
| Clients | yes | yes | — |
| Products and services | yes | yes | — |
| Price lists | yes | yes | — |
| Staff and person master data | yes | yes | — |
| Accounting documents | **yes, only** | no | no |
| Ledgers | **yes, only** | no | no |
| Pay runs | **yes, only** | no | no |

A legal entity's books must not be jointly owned. Declaring the permitted scopes per category is what
makes "isolated by design" a structural property rather than a matter of operator discipline: a
group-scoped ownership row for a ledger is refused by the registry, not merely discouraged in
documentation.

The declaration lives with the resource-policy definition, so an extension contributing a resource
category declares its permitted scopes the same way it declares everything else, and the declaration is
part of the frozen contract at Gate A.

### 4. Isolation at the write layer, unification at the read layer

Consolidated group reporting is a **group-scoped read capability** served through the existing projection
machinery. It is never a hole in transactional isolation.

A group report is authorized, audited and policy-filtered exactly as any other read. It reads across the
sites of a group because the reading capability is owned at group scope, not because the write path
relaxed. No transaction ever spans sites.

### 5. Scope changes are asymmetric, deliberately

- **Widening** (site → group, group → installation) is an ownership change plus an audit entry. It is
  cheap, supported, and needs no data migration: the resource does not move, only the scope that owns it.
- **Narrowing** (group → site, installation → group) is **guarded**. It must first prove that no other
  member site's records reference the resource. If they do, narrowing is refused with the referencing
  sites named, because completing it would leave those records pointing at something they can no longer
  see.

State the asymmetry plainly in the operator documentation. An operator who widens casually and expects to
narrow casually will be surprised at exactly the wrong moment.

### 6. Payroll is the worked example

- The **person** is group-owned — one human being, known once across the businesses they work for.
- The **employment**, the **cost allocation** and the **pay run** are site-owned.

So one employee can work across several businesses of the group without their pay becoming ambiguous:
there is one person and several employments, each belonging to exactly one legal entity, each paid from
that entity's own pay run and posted to that entity's own books.

### 7. Inter-business documents are not atomic, and extension authors are told so up front

The covenant that one configured relational database owns an atomic business transaction, and that no
transaction spans sites, is unchanged. A transfer between two businesses of a group is therefore **two
transactions coordinated by a durable event**, not one.

This is stated in the Gate A extension contract rather than buried in a covenant list, because an author
designing an inter-company transfer needs to know it before they design it, not after.

## Alternative rejected: per-row sharing lists

The obvious alternative is a many-to-many `resource ↔ site` table: a resource row, and one row per site it
is shared with.

It is rejected for three reasons, and the reasons are recorded here so a future reader does not reopen the
question without them.

1. **Authorization becomes a set query.** Today the gateway resolves one owner and compares one value. With
   a sharing list it must ask whether a membership row exists for this resource and this site, on the hot
   path of every authorized operation, for every resource. The current design lets ownership be resolved
   once and cached within a request; a set membership test does not have that shape.
2. **"Who owns this?" becomes unanswerable.** The audit trail, every denial reason, and every operator
   question about a record depend on there being one owner. A sharing list has *n* participants and no
   owner, so `resource_site_mismatch` has nothing to name, and an audit entry cannot say whose resource was
   touched. The current primary key `(resource_type, resource_id)` exists precisely to guarantee the
   question has one answer.
3. **It cannot be changed safely later.** Sharing lists accumulate per-row state that no declaration
   describes. Once a hundred thousand client rows each carry their own hand-assembled visibility set, there
   is no declarative object to reason about, no way to answer "what does this group share" without
   scanning, and no safe bulk correction. A named group is a single object an operator can inspect,
   change and audit; a hundred thousand implicit sets are not.

A scope is a declaration. A sharing list is an accumulation. The programme chooses the declaration.

## Consequences

**Positive, and verified.** The mutation fence is taken per **site and definition installation**:
`DoctrineBusinessRecordMutationFence::acquire()` selects the row with `WHERE h.site_identifier = ?` on the
site-scoped `business_definitions` table, joined to `business_schema_installations`, and re-checks the
installation's own site on the joined row. Four businesses running the same logical definition therefore
hold four distinct definition rows and four distinct installation rows, so a group of four **partitions
the write hot spot naturally into four independent fences** rather than concentrating it. This is directly
relevant to `V2-SCL-001` and to the phase 5 scale work: a group installation contends less, not more.

**Cost.** `ResourceSiteOwnership::siteFor()` returns `SiteContext` today and must return an ownership
scope. 22 files inject the writer across 25 `record()` call sites. The change is mechanical but wide, so it
is staged: introduce the scope type and let a site scope be constructed from a `SiteContext`, widen the
registry and the gateway, then migrate call sites in small groups. Every call site that legitimately means
"this site owns it" keeps saying so.

**Risk that must be tested, not assumed.** The containment test replaces an equality on the hot path of
every authorized operation. It must not become a join per authorization call. The lookup returns a scope
identifier and its membership is resolved against a small, bounded, cacheable set — group membership
changes are administrative events, not transactional ones.

## Non-goals

- Not multi-tenant service to unrelated tenants. That is version 3 or 4 and is not designed here.
- Not a hierarchy. Groups are named sets and may overlap; they are not a tree and have no inheritance.
- Not cross-site transactions. The covenant is unchanged.
- Not a replacement for `ScopeMode`. Record storage partitioning and resource ownership stay separate
  concepts with separate names.
- Not a relaxation of `installationGlobal`. The policy-level flag and the instance-level scope are
  reconciled, and the reconciliation is part of the work package, not left to interpretation.
