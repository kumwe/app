# Audit listener example

The smallest extension that observes a Kumwe domain event. Its signed manifest declares one
`domain_listeners` entry on the platform's `core.business_record.mutated` event, and its provider binds
that declaration to an executable `MutationAuditListener` through the canonical binding registrar. No
route, screen, permission or migration is contributed: the package exists to show the whole of what an
event observer needs and nothing else.

The listener runs inside the authoritative transaction of every business-record mutation, so it must be
fast and free of external effects. It validates that the declaration it was handed is its own and
accepts the event, then records the event's identity in a bounded in-memory ledger. A real listener
would enqueue a namespaced job or consume the committed outbox event for anything that leaves the
process; it must never use the event to bypass an application service's authorization or audit.

Install it with the other shipped examples through `bin/kumwe demo:install-examples`, or build, sign
and install it by hand as described in `docs/extensions.md`.
