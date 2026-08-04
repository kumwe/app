# ADR 0002: Joomla-first, Laminas and Mezzio fallback

- Status: Accepted
- Date: 2026-08-04

## Decision

Kumwe uses a maintained Joomla Framework component whenever it provides the
required capability. When Joomla has no maintained implementation or has
discontinued the package, Kumwe selects a maintained Laminas component or Mezzio
package.

Symfony and Laravel are not direct production dependencies. Kumwe-owned code is
introduced only for product policy, stable extension contracts, or an integration
boundary not supplied by the selected libraries.

## Consequences

- `joomla/renderer` is replaced by a Kumwe renderer port backed by Twig 3.
- The HTTP application uses Mezzio and Laminas Diactoros.
- Joomla DI, Event, Database, Registry, Filter, Archive and Filesystem remain
  preferred infrastructure components.
- The narrow console dispatcher and forward-only migration runner remain
  Kumwe-owned boundaries because the otherwise suitable packages would add a
  prohibited direct framework dependency chain.
- Composer policy tests reject prohibited direct dependencies.
