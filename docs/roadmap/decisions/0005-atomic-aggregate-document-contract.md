# ADR 0005 — A document is a header and its owned lines, written as one declarative command

**Status** Accepted
**Decided by** Product owner
**Verified against** `f3ef832` plus the change that implements it
**Findings** `V2-SCL-003`, `V2-ERP-001`
**Gate** A

---

## Context

Decision D8 says the aggregate command's public shape is settled as an architecture decision before the
implementation lands, so extension authors building after Gate A build against the real shape rather than a
placeholder. This record states that shape. It was written alongside the implementation rather than ahead of
it, which is a departure from D8's sequencing and is noted here plainly; what D8 actually protects — that the
shape is written down, argued for, and stable before extensions depend on it — is satisfied.

Nearly every demanding business object is a document: an invoice, a purchase order, an attendance batch, a
job card, a stock movement, a pay run. Each is a header and the lines belonging to it. Before this change
core could express the *storage* of that — `RelationshipKind::OwnedLineCollection` and its line table — but
not the *write*. A header plus N lines was a header mutation followed by N relationship mutations, each with
its own transaction, version, revision, audit entry, event and idempotency row. An invoice whose header said
one total and whose lines said another was reachable, and every extension would have invented its own way to
avoid it, and none of them would have agreed.

Two questions had to be answered together, because answering either alone leaves the other broken. How is a
document written atomically? And how does a definition state a rule about the collection rather than about
one row?

## Decision

### 1. One command writes one whole document

`BusinessRecordService::writeDocument()` takes a `WriteDocumentCommand` carrying the header's values and the
document's whole line list. Header and lines commit inside the one transaction the definition's exclusive
mutation fence is already held for. There is no window in which a reader observes a header without its lines.
A refusal anywhere takes the whole document with it.

This extends the existing machinery rather than paralleling it: the same fence, the same idempotency ledger,
the same compare-and-set write, the same revision, audit and event path. Nothing about the single-record
write was reimplemented, and the single-line relation commands are unchanged and remain supported.

### 2. The line list is declarative, not a set of edits

The submitted list **is** the collection as it is to end up. A line naming an identity the document holds is
amended; a line naming none is added; a stored line the list does not name is removed; and each line's
position is its index in the list.

The alternative — per-line operation verbs — was rejected. It makes two lines in one slot expressible, makes
a hole in the numbering expressible, and makes the aggregate rule harder to evaluate because the final
collection is not known until every verb has been applied. Declaring the end state makes ordering and
identity properties of the model rather than conventions a caller is trusted to honour.

### 3. Concurrency is settled at the document, not at the line

The expected version is the header's, and it guards every line write in the command. Two callers amending one
document contend for one value; the loser is refused as a stale conflict rather than interleaved. This is
the same optimistic-concurrency concept the single-record path already had, raised to the aggregate.

### 4. A record invariant may reduce an owned-line collection

The expression vocabulary gains exactly one leaf, `line_aggregate`: one collection the entity declares as an
owned-line relationship, one reduction from a closed set — `sum` over one line field, or `count` over the
collection — inside the byte, node and depth budget every other expression already lives under.

That is the whole widening, and it is deliberately not more. A general query language inside a published
definition would be a different decision with a different risk profile, and it is not this one.

The rule is evaluated once per command over the prepared collection, never once per line and never by
re-reading rows. Decimal folding runs through the exact decimal type, so a thousand values produce a
canonical base-10 string and never a float.

### 5. What a definition may declare is settled before publication, not at write time

An aggregation must name a collection the entity declares; a sum must name a field the line entity carries,
and that field must hold an exact number and must not be restricted or secret. A field formula, a visibility
or editability condition and an action condition may not aggregate at all. Each of these is refused when the
definition is declared, so a rule that could never be evaluated can never be published.

### 6. A violated document rule is reported as itself

The violation names the invariant's handle and carries the definition's own operator-facing message, rather
than naming a row or collapsing into a generic field-access refusal. A rule's handle and message describe a
rule, not a value, so they disclose nothing about the record — and an operator told that a total disagrees
with its lines can fix it, while an operator told that "one or more submitted fields are unavailable" cannot.

### 7. The rule belongs to the document, so every command that can break it enforces it

The document write, an ordinary header update, and a single-line `relate()` or `unrelate()` all judge the
aggregate invariants. A `reorder()` moves no value and changes no count and is not re-judged. A definition
declaring no aggregate invariant reaches no extra lock and no extra statement.

## Consequences

- An extension declares a document and its rules in a definition document and gets atomicity, ordering,
  identity, concurrency and enforcement without a core edit. That is the platform's central promise, and it
  is now testable rather than asserted.
- A thousand-line document costs ten batched insert statements against the line table, and a document
  resubmitted unchanged costs none. Statement growth is bounded by the parameter budget rather than by the
  collection.
- The whole stored collection must be visible to the caller. A command that replaces a collection cannot
  safely work from a filtered view of it, so a stored line the row policy hides fails the command closed
  rather than being destroyed invisibly.
- One command writes one collection. A line type that declares an aggregate invariant of its own is refused
  rather than half-enforced; a nested document needs a command that writes its own lines, and that is a
  separate decision.
- Immutable correction by linked reversal ([ADR 0003](0003-immutable-correction-by-reversal.md)),
  number-sequence scoping and the fence and sequencer work all sit above or beside this contract and are
  unaffected by it.
