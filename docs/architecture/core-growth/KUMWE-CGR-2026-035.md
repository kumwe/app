---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-035
title: "One schema recovery-evidence use case for the screen, REST and the console"
symbols:
  - Kumwe\App\BusinessSchema\Application\BusinessSchemaRecoveryEvidenceRecorder
layer: application
capability_index_sha256: "ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826"
packages_reviewed:
  - package: kumwe/business-schema
    version: v0.1.3
    symbols_inspected:
      - Kumwe\BusinessSchema\Domain\SchemaRecoveryEvidence
      - Kumwe\BusinessSchema\Domain\SchemaPlan
      - Kumwe\BusinessSchema\Domain\InvalidBusinessSchema
    source_inspected:
      - vendor/kumwe/business-schema/src/Domain/SchemaRecoveryEvidence.php
      - vendor/kumwe/business-schema/CHARTER.md
    tests_inspected:
      - vendor/kumwe/business-schema/tests
search_terms:
  - recovery evidence
  - restore drill
  - clean target proof
  - destructive schema approval evidence
required_capability: "Let REST and CLI callers file the restore-drill evidence a destructive schema plan must cite, with exactly the rules the administrator schema screen applies: the plan's source-schema binding, the four clean-target proofs, the current-password re-proof and the live environment stamps."
consumers:
  - src/BusinessSchema/Delivery/Administrator/RecordBusinessSchemaRecoveryEvidenceHandler.php
  - src/BusinessSchema/Delivery/Api/BusinessSchemaApiHandler.php
  - src/Delivery/Console/Command/BusinessSchemaEvidenceCommand.php
overlap_reviewed:
  - Kumwe\App\BusinessSchema\Application\BusinessSchemaService
decision: approved
decided_by: "Browser-to-machine parity implementation agent under standing maintainer mandate"
reviewer: "Browser-to-machine parity implementation agent (source ownership review; not human approval)"
decided_on: "2026-09-24"
pull_request: "https://github.com/kumwe/app/pull/152"
---

## Capability required

A destructive schema plan can be approved only against recovery evidence: a restore drill performed against the
schema the plan would replace. The administrator screen files it after confirming four clean-target proofs and
re-proving the operator's password, binds it to the plan's source schema checksum and stamps the live database
driver, server version and release. Machine callers could approve a destructive plan with `--evidence`, but had no
way to file the evidence itself.

## Why existing package APIs are insufficient

`kumwe/business-schema` owns the `SchemaRecoveryEvidence` document and its own validation, and is reused
unchanged. `BusinessSchemaService::recordRecoveryEvidence()` authorizes `business.schema.recover`, matches the drill
to the site, environment and verifier, and stores it; it too is reused unchanged. The screen-level rules — source
checksum lookup, proof confirmation, password re-proof and environment stamping — lived only in the administrator
handler, so a second and third copy would have been needed for REST and the console.

## Why extending the owning package is inappropriate

The password re-proof (`HighImpactCredentialGuard`) and the live environment port (`BusinessSchemaEnvironment`) are
App security and App runtime concerns; the portable package must not depend on either.

## Why a new focused package is inappropriate

There is no portable bounded context: the class composes one App service with two App ports, and exists so the
three delivery surfaces share one implementation instead of three.

## App-specific responsibility

`BusinessSchemaRecoveryEvidenceRecorder::record()` is the screen's former handler body, moved unchanged behind a
typed method. The administrator handler, `POST /api/v1/business-schema-plans/{id}/recovery-evidence` and
`bin/kumwe business-schema-evidence record` now only parse their transport and call it.

## Tests proving the boundary

- `BusinessSchemaMachineEquivalenceIntegrationTest` files evidence through REST, the console and the screen's
  handler on MariaDB and PostgreSQL, approves a destructive plan against the screen-filed evidence over REST, and
  proves a wrong password, an unconfirmed proof and a missing grant are refused on both machine surfaces.
- `BusinessRuntimeBoundaryTest` pins that the recorder carries the environment port, the credential guard and the
  screen's re-proof purpose, and that the screen delegates to it.

## Decision

Approved as App orchestration and security enforcement under the standing maintainer mandate. The ownership review
is by the implementing agent, not an independent review or human GitHub approval.
