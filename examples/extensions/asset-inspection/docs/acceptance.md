# Asset inspection acceptance map

This example is accepted only when the signed declaration, provider implementation, and live generation agree.

| Gate | Evidence |
| --- | --- |
| Neutral scope | Administrator and portal pages state that the package is an extensibility proof, not ERP functionality. |
| Related model | Location → assets → inspections, with inspection → ordered findings and ordered measurements. |
| Workflow | `submit`, `verify`, and `close` are capability-gated transitions over four declared states. |
| Computation | `risk_score` is a stored integer formula over `raw_score + adjustment`. |
| Field policy | `internal_note` is restricted and non-queryable; the host-constrained record reader never discloses it to extension code. |
| Row policy | The signed profile is deployment evidence applied by host administration; the provider cannot parse, select, or install policy. |
| Atomic event path | Listener and consumer both accept only `core.business_record.mutated@1`, which the record runtime appends to the outbox in its mutation transaction. |
| Durable consumption | Consumer identity is stable, aggregate ordered, aggregate-version idempotent, and bound to the owned queue. |
| Automation | A site-scoped payload schema, handler, queue, and enabled daily UTC schedule reconcile as one generation. |
| Projection | The builder has no clock or external read and derives replaceable state only from the exact event version. |
| Reporting | The bounded report names safe scalar fields, a typed parameter/filter, deterministic sort, capability, and explicit portal visibility. |
| Delivery | Administrator and portal routes use SDK request context and isolated route-bound renderers. |
| Lifecycle | Disable withdraws executable contributions; re-enable restores the signed set; persistent records and delivery evidence survive. |
| Package | Deterministic build, code-free inspection, static conformance, and detached signing complete without source edits. |

Production-matrix tests should assert durable outbox/inbox/checkpoint, schedule/job, projection checksum, export
checksum, audit, backup/restore, and runtime-generation state in MariaDB, MySQL, and PostgreSQL. The bounded page
counters are intentionally unsuitable as persistence evidence.
