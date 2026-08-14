# ADR 0003 — An approved document is corrected by a linked reversal, never by mutation

**Status** Accepted
**Decided by** Product owner
**Verified against** `26a7b3963c255064754f541dc8286e75dd566b1f`
**Findings** `V2-ERP-005`
**Gate** A

---

## Context

Kumwe's stated identity is auditability. Eight qualification waves were spent proving that what the
system says happened is what happened: a tamper-evident digest chain with monotonic positions and an
anchor ledger, append-only triggers where the server grants the privilege, a protected export, and a
verification command and job.

None of that answers the question this decision is about. Those controls prove **that a change was
recorded**. They do not prove **that an approved document was never changed**.

A platform whose identity is auditability cannot allow an approved document to be edited. That is not
an accounting feature and it is not a preference borrowed from double-entry bookkeeping: it is the
property that makes the audit trail worth reading. If an approved invoice can be edited, then every
statement about what was approved is a statement about the current state of a mutable row, and the
digest chain proves only that somebody with the capability made the change.

### What exists today, verified

- **`BusinessRecordService`'s update and archive paths mutate an approved record like any other.**
  Revisions record what changed and the audit chain proves the record was not tampered with outside
  the application, but nothing declares "this document is immutable after this transition, and is
  corrected by issuing a linked reversing document".
- **The parts a reversal needs already exist, separately.** `WorkflowBinding` gives a per-record state
  machine whose transitions run only through an action whose capability the actor holds.
  `FieldDefinition::$immutableAfterCreate` (line 130) makes a single field refuse updates.
  `RelationshipKind` supplies typed relationships including `OwnedLineCollection`. `BusinessSecurity`
  supplies approvals, maker-checker and payload-digest-bound step-up. What is missing is the
  declaration that binds them: a post-transition immutability rule, and a first-class link from a
  correcting document to the document it corrects.
- **Field-level immutability is not document-level immutability.** `immutableAfterCreate` freezes a
  field from creation, which is a different rule from freezing a whole document from a transition. A
  document is mutable while it is a draft and frozen once approved; no existing declaration expresses
  that.

## Decision

### 1. A definition may declare a document immutable from a transition

A workflow binding may declare that entering a given state makes the record immutable. After that
transition the record refuses every mutation of its own fields and its owned lines, on every surface,
including the surfaces an extension contributes. The refusal is a stable, named error, not a policy
denial, because the caller may well be fully authorized — the document is simply closed.

### 2. Correction is a new document, linked to the one it corrects

A correction is a new record of the same definition carrying a first-class typed link to the record it
reverses, and its own approval path. The link is part of the core relationship vocabulary rather than
a convention an extension re-invents, so "what corrected this, and what did this correct" is a query
against a declared relationship on every installation and not a per-vertical field name.

A reversal never rewrites the original and never suppresses it. Both documents remain readable, both
remain in history, and the net effect is the pair.

### 3. Core owns the mechanism; the extension owns what a correction means

Core supplies the immutability declaration, the reversal link and the enforcement. Core does **not**
know what reversing an invoice means for a ledger, what a credit means for a customer balance, or
whether a corrected pay run re-posts. Those are business rules and they stay in extensions, exactly as
the product objective requires.

### 4. It is enforceable, not advisory

The rule fails the build when it is violated rather than being described in documentation an author
may not read. Three checks carry it, and each is named on the work package that delivers it:

- an architecture test asserting that no mutation path in `src/` can write a record whose definition
  declares it immutable in its current state, so a new write path cannot silently bypass the rule;
- a three-engine integration test proving that update, owned-line mutation and archive are all refused
  on an immutable record with a stable error on every surface, and that the reversal path succeeds;
  and
- a conformance-fixture assertion in the exact-value approved-document portfolio entry, so an
  extension that corrects by mutation fails conformance rather than shipping.

## Alternative rejected: correct in place and rely on the revision and audit trail

The alternative is to leave approved documents mutable and rely on what already ships — every change
produces a revision, and the audit chain is tamper-evident.

It is rejected for three reasons.

1. **A revision proves a change was recorded; it does not prevent the change.** The programme's own
   distinction, stated throughout this roadmap, is between evidence and enforcement. Tamper evidence
   answers "did anyone alter the record outside the application". It does not answer "may this record
   be altered at all", and an auditor asking the second question is not satisfied by an answer to the
   first.
2. **The corrected state has no independent existence.** With in-place correction, the original
   approved figures live only inside a revision payload. Every downstream consumer — a ledger posting,
   a projection, a report, a delivered event, a printed document already in a customer's hands — refers
   to a document that no longer says what it said. With a linked reversal, the original is still a
   document, still queryable, still reportable and still the thing the delivered event described.
3. **It cannot be retrofitted once extensions exist.** Immutability is a shape a document model has or
   does not have, and an extension's whole correction workflow is built on the answer. Changing it
   after extensions are published means changing their behaviour, which is precisely the class of
   change Gate A exists to make impossible. That is why this is a Gate A decision and not a phase 6
   improvement.

## Consequences

**It narrows what "update" means, deliberately.** Some existing behaviour on generated surfaces will
refuse where it previously allowed, for definitions that adopt the declaration. Definitions that do
not declare post-transition immutability are unaffected, so nothing regresses for a definition that
never opts in.

**It interacts with the atomic aggregate command.** A reversal of a thousand-line document is itself a
thousand-line document, so it commits through the same aggregate command with the same one-transaction,
one-version, one-revision, one-audit-action, one-bounded-event contract. No separate reversal write
path exists, and none may be added.

**It interacts with numbering.** A reversal is a document and takes its own number from the same
sequence discipline, allocated at its own final approved transition. It never consumes, reuses or
reopens the original's number.

**It interacts with period close.** A correction issued after its original's period has closed is
dated in an open period, which is what a temporal posting lock is for. The two mechanisms are
delivered together and their interaction is a required test rather than an emergent behaviour.

## Non-goals

- Not a general-ledger model. Core gains no concept of a debit, a credit, a journal or a balance.
- Not a hard-delete replacement. Hard deletion through the authorized lifecycle is a separate,
  existing, audited operation and is unchanged.
- Not a workflow redesign. This is a declaration a workflow binding may carry, not a new state
  machine.
- Not automatic reversal. Core never issues a correcting document by itself; a correction is an
  authorized, audited, capability-gated act by a person or an extension.
- Not a claim that every document should be immutable. Drafts are mutable and must stay mutable; the
  declaration exists so a definition can say exactly where the line is.
