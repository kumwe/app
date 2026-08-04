# Kumwe 2.0 phase roadmap

This roadmap is an implementation contract. A phase is complete only when its
exit criteria pass in continuous integration.

## Phase 0 — Product and engineering baseline

Deliverables:

- Kumwe naming corrected across active 2.0 materials.
- Clean-rebuild and no-legacy-migration decision recorded.
- Joomla/Laminas/Mezzio dependency policy recorded.
- PostgreSQL-first persistence decision recorded.
- Architecture, extension boundary, security invariants and quality gates defined.

Exit criteria:

- Architecture decisions are internally consistent and reviewable.
- Every later phase has measurable deliverables and tests.
- No roadmap item implies 1.x data or API migration.

## Phase 1 — Modern project and security baseline

Deliverables:

- PHP 8.4 application baseline and maintained dependencies.
- `public/` document root and environment-based configuration.
- PHPUnit, PHPStan, coding standards, Composer audit and CI.
- PostgreSQL, Redis and mail development services.
- Error responses, trusted proxy/host handling and security headers.

Exit criteria:

- Dependencies install from a clean checkout.
- No direct Symfony or Laravel production dependency exists.
- CI boots PostgreSQL and validates an empty installation.
- Production errors do not disclose internal details.

## Phase 2 — Application kernel

Deliverables:

- One Joomla DI composition root.
- Mezzio PSR-7/PSR-15 pipeline and routing.
- Configuration, logging, request IDs, transaction boundary and domain event ports.
- Public, administrator, API and health route groups.

Exit criteria:

- No static service locator or global application state exists in active code.
- Middleware order is functional-tested.
- Safe methods cannot invoke mutation handlers.
- Liveness and readiness behave independently.

## Phase 3 — Identity, authorization and audit

Deliverables:

- Accounts, roles, capabilities, sessions, password reset and verification.
- MFA-ready credential contracts and recovery codes.
- Scoped hashed API tokens.
- Rate limiting, CSRF, session rotation and immutable audit records.
- CLI-only initial administrator creation.

Exit criteria:

- Registration cannot obtain administrative access.
- Every administrator route is deny-by-default and capability-tested.
- Authentication and token endpoints pass abuse/rate-limit tests.
- Privileged mutations append complete audit events.

## Phase 4 — CMS domains

Deliverables:

- Content types, fields, entries, categories, tags and relationships.
- Drafts, revisions, preview, workflow, scheduling, rollback and trash.
- Menus, canonical routes and redirect history.
- Media assets, variants and storage adapters.
- Search indexing and reindex jobs.

Exit criteria:

- A complete content lifecycle passes through draft, review, scheduled publish,
  public rendering, revision rollback and trash recovery.
- Menu cycles and duplicate canonical paths are rejected.
- Media validation rejects MIME confusion and executable uploads.
- Public queries enforce publication windows and visibility.

## Phase 5 — Extension platform

Deliverables:

- Manifest schema and extension SDK.
- Registry, dependency solver and lifecycle manager.
- Secure staged installer, signature policy and atomic activation.
- Compiled service, route, event, block and asset maps.
- Component, plugin, module, template, package and language contracts.

Exit criteria:

- Valid signed fixtures install, enable, disable, update and uninstall.
- Malicious archives, invalid signatures, conflicts and incompatible versions fail
  without changing active files, schema or maps.
- The request path performs no extension directory scanning.

## Phase 6 — Presentation and site building

Deliverables:

- Site and administrator templates with parent inheritance.
- Named module positions and rule-based assignment.
- Structured page blocks and extension-provided block types.
- Asset graph, cache busting, CSP integration and controlled overrides.
- Accessible administration flows for assembling a site.

Exit criteria:

- An administrator can assemble and publish a complete site without code changes.
- Unknown or invalid block payloads fail validation without data loss.
- Template overrides cannot traverse paths or execute uploaded source.
- Rendered output meets accessibility and cache-variation tests.

## Phase 7 — Programmable platform

Deliverables:

- Versioned REST API and generated OpenAPI document.
- CLI administration and operational commands.
- Durable jobs, retries, dead-letter handling, scheduler and distributed locks.
- Official PHP MCP SDK adapter with resources, prompts and scoped tools.

Exit criteria:

- REST, CLI and MCP produce identical application-level outcomes.
- Mutating tools enforce scope, capability, idempotency and concurrency.
- Destructive or publishing MCP operations support dry-run plans and approval.
- Job retry and locking behavior passes concurrency tests.

## Phase 8 — Production delivery

Deliverables:

- Hardened container images and Compose deployment.
- Release ZIP and sealed installer for non-container hosting.
- Migrations, 2.x upgrade orchestration, maintenance mode and rollback guidance.
- Structured logs, metrics, health checks, backups and restore drills.
- CI release workflow, SBOM, signatures, provenance and operator documentation.

Exit criteria:

- A clean system deploys reproducibly against PostgreSQL.
- Backup and restore are exercised in CI or a documented release drill.
- Images and archives are scanned, inventoried and signed.
- Installation, website assembly, API use, MCP use and upgrade are documented and
  verified from release artifacts.
