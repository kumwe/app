# Kumwe CMS 2.0

Kumwe is a modern, extensible content-management system built on maintained
Joomla Framework components, with Laminas and Mezzio filling capability gaps.

Kumwe 2.0 is a clean application baseline. It does not migrate Kumwe 1.x data,
preserve undocumented PHP APIs, or carry legacy runtime behavior forward.

## Product capabilities

- Structured content, custom fields, taxonomies, revisions, workflow and search
- Hierarchical menus, redirects and publishing schedules
- Media storage with MIME validation and pluggable storage adapters
- Capability-based access control, secure sessions, MFA-ready identity and audit
- Installable components, plugins, modules, templates, packages and languages
- Structured page composition with extension-provided block types
- REST/OpenAPI, CLI, background jobs, scheduling and an MCP adapter
- PostgreSQL as the primary database, with Redis available for operational services
- Container-first deployment and reproducible release archives

## Architecture policy

Kumwe selects dependencies in this order:

1. A maintained Joomla Framework component.
2. A maintained Laminas component or Mezzio package when Joomla does not provide
   the required capability or has discontinued the relevant package.
3. A small Kumwe-owned abstraction when neither ecosystem has the required
   contract.

Symfony and Laravel are not application dependencies. Kumwe does not reimplement
capabilities already provided by the selected maintained libraries.

See [the architecture](docs/product/kumwe-2.0-architecture.md),
[the phase roadmap](docs/product/phase-roadmap.md), and
[the security policy](SECURITY.md) for the authoritative 2.0 baseline.

## Current development status

Kumwe 2.0 is developed on `agent/kumwe-2.0-phases-0-8`. Every phase has explicit
exit criteria and a dedicated commit. The default branch remains the historical
1.x baseline until the 2.0 pull request is accepted.

## License

Copyright (C) 2022–2026 Llewellyn van der Merwe and contributors.

Kumwe is licensed under the GNU General Public License version 2.0; see
[LICENSE](LICENSE).
