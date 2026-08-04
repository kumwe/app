# ADR 0004: Extension trust and installation

- Status: Accepted
- Date: 2026-08-04

## Decision

Executable extensions are trusted server-side code. Kumwe establishes provenance
through administrator authorization, package signatures, digests, compatibility
validation and an auditable staged installation. Kumwe does not claim to sandbox
arbitrary PHP code.

Production policy requires signed registry packages by default. Explicit policy
may permit unsigned local packages for development.

## Consequences

- Installer security focuses on archive safety, provenance and atomicity.
- Extension capabilities are declared and reviewed but do not replace operating
  system or container isolation.
- Cached registries eliminate runtime directory scanning.
