# Growth paths

Kumwe's boundaries are designed to let a small site become a large portal without replacing its delivery surfaces or business rules. Growth should follow measured pressure, not speculative framework layers.

## Higher traffic

Run multiple immutable web containers behind a load balancer, move sessions and rate-limit counters to the configured Redis boundary, use a managed database with pooling and replicas, and separate worker queues by workload. Add read models for expensive listings and invalidate them from committed domain events. Keep writes on application services and the primary database.

## Large content models

Add content types and fields through versioned schemas. Use dedicated aggregates or an ORM-backed bounded component when relationship management warrants it; use DBAL projections for search and reporting. Keep revisions, workflow, permissions, and audit behavior consistent across types.

## Search and discovery

Treat the relational database as the source of truth. Build a replaceable search projection through the outbox and workers. A search outage must not prevent authoritative content reads or writes, and a full index must be reproducible from durable state.

## Media and files

Introduce storage adapters for object storage, CDN delivery, image transformations, and malware scanning. Store metadata and authorization decisions in the database; keep binary storage behind a service contract. Never allow extensions or templates to construct unvalidated filesystem paths.

## Multiple teams or tenants

Prefer explicit site or tenant identifiers in domain and authorization models before adding database-level tenancy. Define isolation, deployment, extension, and backup boundaries first. Do not infer tenancy from host headers alone. Separate databases may be appropriate when regulatory or operational isolation outweighs shared-schema efficiency.

## External workflows and AI

Add durable integration events and webhooks through an outbox. Give external systems and MCP clients the smallest capability set, require optimistic versions and idempotency on writes, and retain actor-attributed audit records. Publishing, deletion, permission changes, and extension activation should remain explicit high-impact operations.

## Architectural change checklist

Any substantial growth change must record:

- the measured constraint and expected capacity;
- the domain and data owner;
- consistency, retry, and failure semantics;
- authorization and audit effects;
- migration and rollback procedure;
- database-matrix and deployment tests;
- observability and recovery signals;
- the stable interface preserved for extensions and delivery adapters.

Add or update this architecture guide when a decision changes an invariant. Do not add temporary progress reports or implementation status to public documentation.
