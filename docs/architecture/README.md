# Architecture guide

The runtime's cross-surface security boundaries are documented in [Business security](../business-security.md)
and [Ordinary-user portal](../portal.md).

This section records the boundaries that must remain stable as Kumwe grows. It is for maintainers, extension authors, and automated coding agents that need to change the system without reconstructing its intent from implementation details.

- [Principles and ownership](principles.md)
- [Persistence and database portability](persistence.md)
- [Delivery surfaces and authorization](delivery.md)
- [Extension and event model](extensions.md)
- [Business definitions](../business-definitions.md)
- [Growth paths](growth.md)

These are decision records, not a development log. They describe the current design contract and the tests a change must preserve.
