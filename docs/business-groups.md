# Business groups

A single Kumwe installation can serve several related businesses. They share staff, clients, products and
price lists **by explicit declaration**, keep their accounting apart **by construction**, and still report
together. This page explains how that works, what an operator can and cannot change afterwards, and what an
extension author must do to make a new kind of record take part.

This is not multi-tenant hosting. Kumwe 2.0 serves one organisation's group of businesses, not unrelated
customers sharing a server. See [ADR 0001](roadmap/decisions/0001-resource-ownership-scope.md) for the
decision and the alternatives it rejected.

## One owner, held at a level

Every resource in Kumwe has **exactly one owner**. That has always been true — the ownership registry is
keyed on the resource, so the question "who owns this?" has one answer, which is what lets a denial name a
site and an audit entry name a record.

What is new is the *level* the owner may be held at:

| Level | Meaning |
|---|---|
| **Site** | One business owns the record. This is what every record means today. |
| **Group** | A named, declared set of businesses owns the record together. |
| **Installation** | The installation itself owns the record; a person needs an installation-wide grant to reach it. |

Authorization asks one question: **is the site I am working in inside the scope that owns this record?**
For a site-owned record that question is the identifier comparison it has always been, on the same single
value. Nothing an installation could reach before it declared a group changes when it declares one.

## Groups are declared, never inferred

A group is a named set of sites an operator writes down. It is not derived from a hierarchy, and groups may
overlap freely: the workshop and the retail arm may share clients while the workshop and the freight arm
share products. Sharing in a business group is not a tree, so nothing here pretends it is.

Both halves are explicit. Declaring the group names the sites in it; there is no membership by proximity,
by naming convention, or by default. Bringing a site in and taking one out are separate, capability-gated,
audited acts under `sites.group.manage`, which is an installation-wide capability precisely because it
decides where the sharing boundary runs.

A group must always have at least one member. Removing the last one is refused, because a group with no
members would own records nobody could reach.

## What each kind of record may be shared as

Sharing is opt-in per record, but *what a record is allowed to become* is fixed by the build, not by
configuration. There is no setting, environment variable or manifest key that makes a ledger shareable.

| Category | Site | Group | Installation |
|---|---|---|---|
| Clients | yes | yes | — |
| Products and services | yes | yes | — |
| Price lists | yes | yes | — |
| People and staff master data | yes | yes | — |
| Accounting documents | **yes, only** | no | no |
| Ledgers | **yes, only** | no | no |
| Pay runs | **yes, only** | no | no |

A legal entity's books must not be jointly owned, so the refusal is structural. An owner is assembled
through `ResourceOwnership::of()`, which consults the category table and refuses an impermissible pairing;
holding one of those objects is the proof the pairing is legal, and nothing can be written to the registry
without one. On the engines that support a table check constraint, the ownership row itself refuses to
spell no owner or two.

A category nobody has declared is **site-only**. A new kind of record is isolated until someone deliberately
opts it into sharing, never the other way round.

## Payroll, the worked example

- The **person** is group-owned. One human being, known once across the businesses they work for.
- The **employment**, the **cost allocation** and the **pay run** are site-owned.

So one employee can work for three businesses of the group without their pay becoming ambiguous: there is
one person and three employments, each belonging to exactly one legal entity, each paid from that entity's
own pay run and posted to that entity's own books.

## Changing your mind later

Membership and sharing change after the fact. Nothing is rewired and no data moves — the record stays
exactly where it is and only the scope that owns it changes.

**Widening is cheap.** Bringing a record into a group costs an ownership change and an audit entry. It adds
reach and takes none away.

**Narrowing is guarded, and this is the asymmetry to remember.** Taking a record back out of a group takes
reach away from businesses that may already have built records around it. Before it completes, Kumwe proves
that nothing in the sites about to lose access still refers to the record, and **refuses with those sites
named** when something does. Resolve the references first, then narrow.

A record owned by the **installation** cannot be narrowed at all. Its membership is every site there is,
so there is no bounded set for the guard to check and an unguarded answer would be worse than a refusal.
Record the resource at the narrower owner you want and withdraw the installation-scoped one deliberately.

An operator who widens casually and expects to narrow casually will be surprised at exactly the wrong
moment. Widen deliberately.

Both directions are gated on `ownership.scope.manage` and both leave an audit entry naming the record, the
owner it moved from, the owner it moved to and the sites released.

## Reporting across the group

Accounting is isolated at the write layer and unified at the read layer. Consolidated reporting is a
**separate read capability**, `reports.consolidated.read`, bound to the group as a resource and to nothing
else. Holding it lets a report read across the member sites of a group. It authorizes no write anywhere, in
any business, of any kind — a caller holding only that capability fails the ordinary ownership check on
every write exactly as it did before groups existed, and the test suite proves it.

No transaction ever spans sites. A transfer between two businesses of a group is **two transactions
coordinated by a durable event**, not one. Design inter-business flows that way from the start; there is no
mode in which they become atomic.

## Withdrawing a site

Disabling a site withdraws it from every group it was declared in, without deleting anything. Its own
records stop resolving, and it stops being able to reach the group's. A group whose members have all been
disabled resolves to nothing at all rather than to an empty owner, so the records it owned fail closed
instead of becoming reachable by whoever asks next.

## Making a new record category take part

An extension contributing a new kind of record decides, once, what that record may be owned as.

1. **Declare the category's permitted scopes** by registering it with the shared
   `ResourceOwnershipScopePolicy` from the extension's service provider, before the first record of that
   kind is created:

   ```php
   $scopes->register('inspection_asset', OwnershipScopeRule::SiteOrGroup);
   ```

   `OwnershipScopeRule::SiteOnly` keeps the category isolated, `SiteOrGroup` lets an operator share it, and
   `SiteGroupOrInstallation` additionally admits an installation-wide owner. A category may be declared once;
   restating it differently is refused, because a category whose rule can change is a category whose
   isolation can be negotiated. Categories the build reserves — the accounting and shared-master-data rows
   in the table above, and every category core itself carries — cannot be declared at all.

2. **Bind capabilities to the category** through the ordinary resource-policy contribution, naming the
   category as a resource target. This is unchanged; a category with no policy binding is simply unreachable.

3. **Record ownership when the record is created**, through `ResourceSiteOwnershipWriter::record()`, naming
   the site that created it. Records are always born owned by one site; widening is a later, separately
   authorized act.

4. **Contribute a reference inspector** if your records can refer to a shared record across sites.
   Implement `ResourceOwnershipReferences` and report which of the sites about to lose access still point at
   the record. Every contributed inspector is consulted before a narrowing, and one finding a reference is
   enough to refuse it. Without an inspector your references are invisible to the guard, and a narrowing
   that would strand them will proceed.

If a category should never be shared, do nothing: the default is isolation.

## Where the parts live

| Concern | Where |
|---|---|
| The owner and its level | `Kumwe\App\Application\Authorization\OwnershipScope` |
| The declared groups | `SiteGroupRegistry`, `SiteGroupWriter`, `SiteGroupAdministration` |
| What a category may be owned as | `ResourceOwnershipScopePolicy`, `OwnershipScopeRule` |
| Proving a pairing is legal | `ResourceOwnership::of()` |
| The containment decision | `DenyByDefaultAuthorizationGateway` |
| Widening and narrowing | `ResourceOwnershipScopeService` |
| Consolidated reads | `Kumwe\App\BusinessReporting\Application\ConsolidatedGroupReportScope` |
