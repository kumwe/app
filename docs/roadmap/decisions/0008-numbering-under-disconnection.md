# ADR 0008 — A disconnected terminal carries a client reference; the number is allocated at sync

**Status** Accepted
**Decided by** Product owner
**Verified against** `0af2c27cbc3c0c60f6c1c5c9364533695b57c84d`
**Findings** `V2-POS-002`
**Gate** A

---

## Context

Decision D14 deferred offline point of sale beyond Version 2 as a product, and required Version 2 not to
foreclose it. Three of the four constraints that carry that positioning are delivered and recorded in
`CHANGELOG.md`: the bounded, declared idempotency replay window; the declared home for a client-asserted
capture instant, proved unreachable from every ordering, expiry, period and numbering path; and the
deferrable versus non-deferrable validation split. The fourth constraint is this decision: what a
document's number is while its terminal is disconnected.

The constraint is structural, and the shipped code states it explicitly.

### What exists today, verified

- **The allocator cannot be called from a disconnected terminal, by design.**
  `DoctrineBusinessNumberSequenceAllocator`'s class documentation says exactly why: "this class opens
  none. It joins the transaction `BusinessRecordService` already has open around the whole create
  command, behind the mutation fence and the authorization plan, so the number, the row, the revision
  and the audit entry commit together or not at all." A terminal with no connection has no such
  transaction to join.
- **The promise a number carries is documented and shipped.** `BusinessNumberSequenceAllocator` states
  the guarantee an implementation owes, exactly: "Within one counter — site, definition, field handle,
  scope key and period key together — the values handed to *committed* records are contiguous from one,
  with no duplicates and no gaps." Eight qualification waves of auditability rest on statements of this
  kind being true rather than approximate.
- **A retried command already has a stable outcome.** `BusinessRecordIdempotencyRepository` claims a
  caller-minted key before a mutation runs and completes it with the result in the same transaction, so
  a repeat of the same key replays the stored outcome. The claim is scoped to site, organization, actor,
  operation and key, and it expires: replay is a bounded window, not a permanent record, and the window
  is actor-bound.
- **Field uniqueness is declared, tenancy-scoped and index-enforced.** A field declared `unique` is
  compiled into a unique index the schema compiler leads with the entity's scope columns, so the value
  is unique within the definition's own tenancy; a violation is decided by the index inside the write
  transaction and surfaces as `BusinessRecordUniqueConflict`, never by a read-then-check a concurrent
  command could slip past.

## Decision

**Option 1 of decision D14: the human document number is allocated at synchronisation time.**
Gaplessness is preserved in full.

1. **A disconnected terminal carries its own client reference.** The terminal mints an identifier for
   each captured document and holds it for as long as the document is offline. The reference is the
   document's only identity until it syncs.

2. **The number is allocated by the receiving command at synchronisation time, exactly as the allocator
   works today.** The sync submits an ordinary create; the number, the row, the revision and the audit
   entry commit together in the receiving command's transaction, behind the same fence and the same
   authorization plan as any connected create. The allocator is not changed, relaxed, or given a second
   entry point.

3. **The client reference becomes a declared field with its own uniqueness scope.** The declaration
   uses the field vocabulary that already ships: an ordinary caller-writable field, optional because a
   connected create carries no reference, `unique` so its uniqueness scope is the definition's own
   tenancy, and `immutable_after_create` so the dedupe key cannot be edited out from under a later
   re-submission. A re-submitted sync therefore cannot double-create: within the idempotency window the
   ledger replays the stored outcome, and beyond it — or under a different operator's key — the unique
   index refuses the duplicate inside the write transaction, which rolls the duplicate's allocation
   back with everything else. The two mechanisms compose; neither replaces the other.

## Alternative rejected: per-terminal reserved blocks

The alternative was to issue each terminal a reserved block of numbers to allocate from while
disconnected. It is rejected because it forfeits the shipped gapless guarantee. The promise the
allocator documents — "the values handed to *committed* records are contiguous from one, with no
duplicates and no gaps" — cannot survive a block whose unused remainder evaporates with a terminal that
is lost, wiped or retired, and a platform whose identity is auditability does not quietly convert a
guarantee into an approximation. Had blocks been chosen, the finding required the forfeit to be declared
on the sequence itself and stated in the supported envelope; choosing sync-time allocation makes that
disclosure unnecessary because there is nothing to disclose.

## Consequences

**An offline document has no final number until it syncs.** Stated plainly: anything printed while
disconnected shows the client reference, not a document number. A receipt handed to a customer at an
offline terminal carries the reference; the number exists from the moment the sync commits, and any
reprint from the platform after that moment carries it.

**The existing gapless concurrency tests run unmodified.** That is this option's enforcing check as the
finding records it: sync-time allocation changes nothing about how a counter advances, so the tests that
pin contiguity, first-use arbitration and rollback-returns-the-number keep describing the platform
without edits.

**Sync order decides number order.** Two documents captured offline in one order may sync — and
therefore be numbered — in another. This is the documented boundary the client-asserted instant already
has: a client-asserted capture time never reaches an ordering, expiry, period or numbering decision, and
the number reflects when the platform accepted the document, which is what an auditor can verify.

**The offline product owns the reference's lifecycle.** Core declares the field, its uniqueness scope
and its immutability, and proves the create semantics. How a terminal mints references, batches its
queue, retries a refused duplicate, and reconciles the returned number against its local copy belongs to
the separate long-standing application decision D14 defers — nothing here needs to change when it is
built, which is the non-foreclosure D14 demands.
