---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-013
title: "The business record commands, the schedule occurrence key and the custom action ledger result carry the canonical idempotency key"
symbols:
  - Kumwe\App\BusinessRecord\Application\Command\ArchiveRecordCommand
  - Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand
  - Kumwe\App\BusinessRecord\Application\Command\DeleteRecordCommand
  - Kumwe\App\BusinessRecord\Application\Command\ExecuteRecordActionCommand
  - Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand
  - Kumwe\App\BusinessRecord\Application\Command\ReorderRecordLinesCommand
  - Kumwe\App\BusinessRecord\Application\Command\RestoreRecordCommand
  - Kumwe\App\BusinessRecord\Application\Command\UnrelateRecordsCommand
  - Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand
  - Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand
  - Kumwe\App\Application\Automation\ScheduleOccurrenceKey
  - Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionLedgerResult
layer: application
capability_index_sha256: "589dd45b0a8700c4f72c073df5b7b859f85627c515d700edb565a9f0e66e9cb0"
packages_reviewed:
  - package: kumwe/idempotency
    version: 0.1.3
    symbols_inspected:
      - Kumwe\Idempotency\IdempotencyKey
      - Kumwe\Idempotency\IdempotencyLedger
      - Kumwe\Idempotency\IdempotencyRecord
    source_inspected:
      - vendor/kumwe/idempotency/src
      - vendor/kumwe/idempotency/docs/release-record.md
    tests_inspected:
      - vendor/kumwe/idempotency/resources/public-api/v1.json
  - package: kumwe/extension-sdk
    version: 0.3.3
    symbols_inspected:
      - Kumwe\Extension\Spi\Application\Automation\JobDeclaration
      - Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordReader
    source_inspected:
      - vendor/kumwe/extension-sdk/src/Spi/Application
      - vendor/kumwe/extension-sdk/docs/canonical-package-migration.json
      - vendor/kumwe/extension-sdk/docs/release-record.md
    tests_inspected:
      - vendor/kumwe/extension-sdk/resources/public-api/v1.json
  - package: kumwe/business-surface-contract
    version: 0.1.4
    symbols_inspected:
      - Kumwe\BusinessSurface\Contract\Application\Custom\CustomBusinessActionCommand
      - Kumwe\BusinessSurface\Contract\Application\Custom\CustomBusinessActionResult
    source_inspected:
      - vendor/kumwe/business-surface-contract/src/Application/Custom
      - vendor/kumwe/business-surface-contract/docs/release-record.md
    tests_inspected:
      - vendor/kumwe/business-surface-contract/resources/public-api/v1.json
search_terms:
  - "idempotency key"
  - "record command"
  - "write document"
  - "schedule occurrence"
  - "action ledger result"
  - "client reference"
required_capability: "Carry the caller's idempotency key through the business record commands, the schedule occurrence key and the custom action ledger result as the canonical Kumwe\\Idempotency\\IdempotencyKey, so that the App record service, scheduler and custom action executor replay or refuse a repeated request against the ledger the idempotency package owns."
consumers:
  - "src/BusinessRecord/Application/BusinessRecordService.php"
  - "src/BusinessSurface/Application/BusinessSurfaceService.php"
  - "src/BusinessSurface/Application/Custom/CustomBusinessActionExecutor.php"
  - "src/Delivery/Http/Api/Business/BusinessRecordApiRequest.php"
  - "src/Infrastructure/Automation/DoctrineScheduler.php"
  - "tests/Unit/BusinessRecord/Application/WriteDocumentCommandTest.php"
  - "tests/Unit/BusinessRecord/Application/BusinessRecordRelationshipCoordinatorTest.php"
  - "tests/Unit/Application/Automation/ScheduleOccurrenceKeyTest.php"
  - "tests/Unit/BusinessSurface/Application/Custom/CustomBusinessActionExecutorTest.php"
  - "tests/Unit/Delivery/Http/Api/Business/BusinessRecordApiRequestTest.php"
overlap_reviewed: []
decision: approved
decided_by: "eWɘyn (KUMWE-MIG-2026-033 adoption, standing maintainer mandate)"
reviewer: "eWɘyn (package-boundary review against the installed kumwe/idempotency 0.1.3, kumwe/extension-sdk 0.3.3 candidate and kumwe/business-surface-contract 0.1.4 sources, manifests and records)"
decided_on: "2026-09-23"
pull_request: null
---

## Capability required

A business record mutation, a scheduled occurrence and a custom business action each carry the caller's
idempotency key so that a repeated request replays the recorded outcome or is refused, never executed
twice. The ten record commands, the schedule occurrence key and the custom action ledger result are the
App values that carry that key from the API request, the console and the scheduler to the record service,
the scheduler and the custom action executor. Their public surfaces changed in one way: the key they hold
is now `Kumwe\Idempotency\IdempotencyKey`, the canonical value `kumwe/idempotency` owns, instead of the
copy extension-sdk 0.2.4 declared under `Kumwe\Extension\Spi\Application\Automation` and 0.3.3 removed.

## Why existing package APIs are insufficient

The package key is consumed directly and entirely; nothing is duplicated. The reason these twelve classes
need a record rather than a re-record is the rename tolerance of the core-growth gate: it maps each
canonical symbol to the one retired name a ledger records, and for `Kumwe\Idempotency\IdempotencyKey`
that name is the App delivery copy `KUMWE-MIG-2026-020` retired. The SDK copy `KUMWE-MIG-2026-033` retires
maps to the same canonical symbol, so the tolerance cannot also absorb it, and the twelve surfaces that
named the SDK copy read as changed. `kumwe/business-surface-contract` 0.1.4 types its
`CustomBusinessActionCommand` on the same package key, which is why the ledger result follows it, and the
SDK `JobDeclaration` and `BusinessRecordReader` ports were inspected to confirm that no SDK type still
carries an idempotency key of its own.

## Why extending the owning package is inappropriate

`kumwe/idempotency` owns the key, the ledger and the record; the commands, the occurrence key and the
ledger result are App request and outcome values that bind the key to App record, schedule and action
identities. The package's charter keeps request shapes and outcomes with the host, and a package command
vocabulary would carry App record semantics into a library every host shares.

## Why a new focused package is inappropriate

The twelve classes are App request and result values with no consumer outside App and no portable
algorithm; the portable part, the key itself, is already the package.

## App-specific responsibility

This is App composition: which requests are idempotent, what identity the key binds to, and what outcome
the ledger result records. The public surfaces changed only in the type of the key they carry; persisted
idempotency records, schedule occurrences and action ledgers are byte-identical to the train baseline
because the canonical key has the same value grammar as the removed SDK copy.

## Tests proving the boundary

- `tests/Unit/BusinessRecord/Application/WriteDocumentCommandTest.php` and
  `tests/Unit/BusinessRecord/Application/BusinessRecordRelationshipCoordinatorTest.php` construct the
  commands with the package key and pin the record service's use of it.
- `tests/Unit/Application/Automation/ScheduleOccurrenceKeyTest.php` pins the occurrence key derivation.
- `tests/Unit/BusinessSurface/Application/Custom/CustomBusinessActionExecutorTest.php` pins the ledger
  result the executor records for a repeated action.
- `tests/Unit/Delivery/Http/Api/Business/BusinessRecordApiRequestTest.php` pins that the API request
  parses the header into the package key it hands to the commands.
- The package's `resources/public-api/v1.json` and its own suite own the key grammar; no App test
  duplicates it.

## Decision

Approved on 2026-09-23 under the standing maintainer mandate as part of the KUMWE-MIG-2026-033 adoption.
The review compared the twelve classes against the installed idempotency, SDK candidate and
business-surface-contract sources and records and found that they consume the canonical key without
duplicating it and keep only App request and outcome semantics. This record is the adoption custodian's
decision and review under that mandate; it is not a human pull-request review event. Revisit when the
core-growth gate tolerates more than one retired name per canonical symbol, so that this record can be
retired in favour of the ledger mapping.
