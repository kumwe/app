# Pattern selection

KIS selects a pattern from the task shape before markup is written. A surface declaration records the
interaction intent and chosen pattern; conformance rejects an unsupported pairing.

## Semantic intents

| Intent | User outcome |
| --- | --- |
| Collection | Find, filter, compare, select, or act on resources |
| Detail | Understand one resource, its state, relationships, and available actions |
| Form | Create or change one resource |
| Parent and child | Manage bounded child values within a parent transaction |
| Chooser | Select one or more authorized related resources |
| Workflow | Move a resource through explicit states and transitions |
| Review | Understand and confirm the exact scope and impact of an operation |
| Comparison | Inspect meaningful before/after or version differences |
| Monitor | Follow progress, failures, retries, recovery, or durable history |
| Settings | Configure one singleton or scoped configuration resource |
| Diagnostics | Investigate technical state without mixing it into routine work |

## Decision order

1. Identify the actor and one primary task.
2. Identify whether the task targets a collection, one resource, a bounded child set, or a process.
3. Count stable concerns, ordinary fields, conditional dependencies, children, and table columns.
4. Classify actions as ordinary, advanced, destructive, irreversible, high-impact, or long-running.
5. Identify permission-reduced, empty, dense, error, conflict, and recovery states.
6. Apply the first matching required rule below.
7. Record any secondary pattern inside the chosen workspace; do not make competing patterns peers.

## Required rules

| Measurable condition | Required pattern |
| --- | --- |
| Ordinary resource CRUD | Collection workspace with discovery toolbar, results, pagination, and one Create action |
| Up to 8 simple creation fields with no branching | Focused form or side drawer |
| More than 8 fields, branching, dependencies, protected review, or distinct ownership decisions | URL/state-addressable step flow ending in review |
| Parent with 0–8 simple children owned by the parent transaction | Inline subform |
| More than 8 children, complex child fields, independent permissions, or independent history | Child collection and focused child editor |
| Resource with 2–5 stable concerns | URL-addressable horizontal tabs |
| Resource with more than 5 stable concerns | Grouped local workspace navigation; tabs only within a concern when necessary |
| Technical data not required for the primary decision | Secondary metadata disclosure with copy control |
| Destructive, irreversible, high-impact, or externally visible operation | Dedicated review and confirmation showing target, scope, version, impact, and recovery consequence |
| Meaningful before/after state | Standard comparison/diff, never parallel free-form text |
| Table exceeds usable container width | User-controlled columns plus labelled intentional scroll, or responsive summary cards |
| Actor can resolve an empty collection | Instructional empty state with one primary action |
| Actor cannot resolve an empty collection | Explanatory empty state naming the prerequisite or responsible role; no dead action |
| Infrequent or advanced operation | Secondary action menu or labelled disclosure |
| Mutation requires step-up | Collect step-up at the submission/review boundary only |
| Long-running operation | Status workspace with progress, last update, retry/recovery state, and safe navigation away |

The field and child thresholds are named KIS policy limits. A reviewed KIS release may change them in
one place; product templates must not scatter alternative numeric thresholds.

## Composition limits

- A route may contain one primary pattern and focused supporting patterns.
- A tab must represent a stable concern, not merely reduce page height.
- A modal is not a workspace and must not contain a long, branching, or recoverable process.
- Nested editable subforms beyond one level are prohibited.
- A drawer is for bounded work whose context remains useful. It is not a substitute for a dedicated
  route when the task needs history, deep links, complex validation, or more than one dominant action.
- A dashboard summarizes and links; it does not duplicate every management form.
- Diagnostics never share equal visual weight with the ordinary path.

Any deviation must be proposed as a KIS change if it represents a reusable need. A named, tested local
exception is allowed only when the need is demonstrably unique and the standard proposal is rejected.
