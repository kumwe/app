# ADR 0021 — Automated acceptance and sampled capacity

**Status** Accepted decision; runtime implementation and verification remain separate
**Decided by** Product owner, 2026-09-24
**Gate** B
**Partially supersedes** Dedicated-hardware and duration prerequisites in the capacity contract and
`P7-A`; named human interface reviewers in `P7-E`; agent merge authority in the standing mandate

## Decision

The next App implementation increment starts from merged pull request 151. It completes contextual
Studio authoring, remaining scale engineering, recovery and diagnostics, and security, interface,
language and automation gaps. Framework extraction and native acceleration are the baseline, not new work.
The original increment kept exact release artifact qualification/publication and the extension proof portfolio
on a separate track. The 2026-09-28 amendment below expands the current pull request.
Demo redesign and the Version 3 Flutter client SDK are outside this increment.

### Scope amendment — 2026-09-28

The product owner instructed agents to finish Points 1 through 5 in pull request #152, repair its rebase
readiness, and prepare Version 2 Beta 1 (`2.0.0-beta.1`). Exact artifact qualification and release-pipeline
readiness therefore join the existing runtime increment. The release pipeline must preserve the identity of
the artifacts it qualifies and publishes. It must not publish the unmerged draft as an accepted release.

Beta 1 is a prerelease, not a claim that the stable Version 2 Gate B assessment has passed. Required runtime,
security, browser and artifact checks remain enforceable; unresolved requirements and external dependency
limits remain visible until their implementation and evidence exist. The maintainer still performs the merge.
The sampled-capacity decision, optional longer soaks, automated acceptance, demo exclusion and Version 3
boundary are unchanged.

### Capacity evidence

Use the infrastructure available to the workflow. Record its CPU, memory, database configuration and
runtime versions; exercise genuinely concurrent workers sharing the authoritative database, with bounded
warm-up, repeated samples and reported failures, latency distributions and throughput. A serial latency
measurement alone is not concurrency evidence. Keep short correctness and contention checks on pull
requests, with larger samples available as workflow jobs.

The five-million logical-business-transaction daily profile remains a planning target. Publish measured
sample results separately from mathematical estimates. Include sample size, variation, model assumptions,
uncertainty and the measured concurrency range. Predictions outside that range are extrapolations; they
are not a supported production capacity, availability or recovery guarantee. CPU count, RAM or worker
count alone cannot justify linear scaling through a shared database or a hot legal-number sequence.
Neither dedicated production-sized hardware nor 24-hour rated and 72-hour soak runs are prerequisites
for this increment. Operators may qualify larger topologies independently using the same harness.

### Interface and language acceptance

Workflow tests supply the interface evidence: supported browser engines, desktop and mobile layouts,
keyboard and focus behavior, accessibility assertions, visual comparisons, locale completeness and
right-to-left journeys. Repair reproducible appearance-switch failures and preserve automated regression
coverage. A WebKit workflow result must be called WebKit evidence, not a claim that a person used native
Safari. Language evidence must describe the checks performed without inventing fluent human review.
No manual browser, screen-reader or language checklist blocks this increment.

### Delivery and human acceptance

Agents implement, review, test, update the live documentation and push coherent commits to one draft
App pull request. It becomes ready for review only after the scoped implementation and required checks
are complete. Only maintainers merge it. That merge is acceptance of the implementation and documentation
already present in the pull request; no second checkbox or approval-record commit follows.

In a branch, completed-work records describe the implemented behavior and actual evidence, while human
acceptance remains conditional on merge. On the default branch, the merge itself supplies that acceptance.
Unrun tests, failed checks, deferred release artifacts and unresolved runtime work remain explicitly open.
This decision changes the acceptance method; it does not declare Gate B passed or Version 2 released.
