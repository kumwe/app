# ADR 0011 — The create command is the numbering transition, and gapless is the declared policy

**Status** Accepted
**Decided by** Product owner, by the constraint ADR 0008 accepted; recorded here so package P4-C's
"final approved transition" language resolves against the platform's actual transition model
**Verified against** `a75b3e557adb75206a65c7d00d9d95ed717a38ed`
**Findings** —
**Gate** A

---

## Context

Package P4-C requires numbers to be allocated "at the final approved transition and as late as
possible in the transaction", and forbids reserving "a final number during draft construction". Read
against an invoice workflow where a draft is edited for days before approval, that language implies
allocation at an approval action. The shipped platform has a different, already-decided transition
model, and this record pins how P4-C's requirement is satisfied inside it.

### What exists today, verified

- **One allocator entry point, joined to the create command's transaction.** ADR 0008 (Accepted)
  fixed that the human number is allocated by the receiving create command, by the unchanged
  allocator — "no second entry point, no relaxation". `DoctrineBusinessNumberSequenceAllocator`
  opens no transaction of its own; it joins the one `BusinessRecordService` already holds around the
  whole create, behind the mutation fence and the authorization plan.
- **Allocation is already as late as the transaction allows.** On the plain create path the
  allocator runs after the fence, definition resolution, scope, access plan, field-input assertion
  and identity resolution. On the document path it runs after every line is validated and the whole
  collection is prepared — "everything is decided before anything is written" — and before the
  header insert and the bulk line write. Nothing expensive runs while the counter row is held that
  could have run before it was taken.
- **A caller can neither supply nor mutate a number**, and a refused create returns its number with
  the rest of its transaction, leaving no hole.

## Decision

1. **The create command is the platform's numbering transition.** A record or document that exists
   is a numbered member of its sequence; there is no earlier "draft construction" phase inside the
   platform's transition model that could reserve a number, because nothing exists before create.
   A workflow state named "draft" on a created record is a post-creation state of a real, numbered,
   audited record — not the un-numbered authoring buffer P4-C's language guards against.
2. **Un-numbered authoring is modelled outside the sequence, never by a provisional number.** A
   definition that needs approval-gated numbering keeps its authoring artifact out of the numbered
   definition entirely and submits it through create when it is accepted — exactly the client
   reference pattern ADR 0008 shipped for disconnected capture: an immutable, unique client-side
   reference until the receiving create command allocates the human number. Adding an
   approval-time allocation seam instead would be the second allocator entry point ADR 0008
   forbids, and would put an unnumbered mutable record inside a legally numbered definition.
3. **Gapless is the single declared gap policy.** Every sequence the platform allocates is
   contiguous-from-one per counter for committed records; a rolled-back allocation returns its
   number. Decision D1 already reserves the honest disclosure for the cost side: a legally
   constrained single gapless sequence states its throughput limit in the supported envelope
   rather than weakening the guarantee. No gap-tolerant or reserve-ahead policy surface exists,
   and none may be added without a new decision here.
4. **Calendar-reset rollover is proven at the counter-composition seam by design.** The command
   instant that keys a calendar reset comes from the kernel's protected clock, which the
   integration harness deliberately cannot substitute. Rollover is therefore pinned where the
   period key is composed (`NumberSequenceFormat::counter()` and the allocator seam), while the
   command path's participation in that composition is pinned by the end-to-end allocation tests;
   fiscal-period rollover, which keys on the record's declared posting date, is proven through the
   full command.

## Consequences

- P4-C's "never reserve a final number during draft construction" holds as: nothing that is not yet
  created holds a number, and nothing created can lose or change one.
- The P4-C proof set (contention, first-use race, refusal reflow, replay, rollover, multi-site
  independence, the thousand-line hold window and the hot-counter run) binds to the create paths
  and the allocator seam, and `BusinessNumberSequenceHotPathIntegrationTest` carries the hold-window
  and hot-counter proofs.
- Any future approval-time numbering feature must supersede both this record and ADR 0008 together,
  or model the approval as the create of a numbered record fed from an un-numbered artifact.
