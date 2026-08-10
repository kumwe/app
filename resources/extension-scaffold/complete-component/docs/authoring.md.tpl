# Authoring @@LABEL@@

Application decisions belong in `OverviewService`; delivery handlers only adapt authenticated request context to a
render model. The provider receives bounded extension capabilities and registers only the services and contributions
declared by `kumwe.json`. Business definition changes require a new definition version and compatible migration.

All contributed routes require `@@EXTENSION_DOTTED@@.access`. Kumwe still grants that capability to nobody by default;
an administrator must assign it through normal role management.
