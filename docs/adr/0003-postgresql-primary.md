# ADR 0003: PostgreSQL is the primary database

- Status: Accepted
- Date: 2026-08-04

## Decision

Kumwe 2.0 targets supported PostgreSQL releases as its production database.
Migrations start from an empty database and do not inspect Kumwe 1.x tables.

UUID v7 identifiers, UTC `timestamptz` timestamps, foreign keys, check constraints,
transactional DDL and `jsonb` are part of the application baseline.

## Consequences

- Repository tests run against PostgreSQL in CI.
- Schema design does not target MySQL/MariaDB compatibility.
- Database-specific behavior is isolated behind repository interfaces so future
  adapters remain possible without weakening the 2.0 schema.
