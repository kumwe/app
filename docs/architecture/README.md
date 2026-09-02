# Architecture guide

The runtime's cross-surface security boundaries are documented in [Business security](../business-security.md)
and [Ordinary-user portal](../portal.md).

This section records the boundaries that must remain stable as Kumwe grows. It is for maintainers, extension authors, and automated coding agents that need to change the system without reconstructing its intent from implementation details.

Start with the [folder map](map.md) when the question is "which directory owns this?". Start with
[principles](principles.md) when the question is "which layer may this depend on?". The operator
checklist that sequences a change is [`AGENTS.md`](../../AGENTS.md).

Portable behaviour belongs to Kumwe packages; App is the composition root that pins, installs and composes
them, and two gates in `composer qa` keep it that way.

- [Folder map](map.md) — if you want to touch X, that is where Y lives
- [Principles and ownership](principles.md)
- [Persistence and database portability](persistence.md)
- [Delivery surfaces and authorization](delivery.md)
- [Generated business surfaces](generated-business-surfaces.md)
- [Extension and event model](extensions.md)
- [Business integrations and extension SDK](../business-integrations.md)
- [Business definitions](../business-definitions.md)
- [Growth paths](growth.md)
- [Governance guide](governance/README.md) — library-first policy, capability index, Core Growth gate,
  migration records
- [Capability index](capability-index.md) — generated; what the installed Kumwe packages already own

These are decision records, not a development log. They describe the current design contract and the tests a change must preserve.
