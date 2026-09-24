---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-015
title: "The formula evaluation and report materialization ports, and the record, report and change-plan services that consume kumwe/computation and kumwe/canonical-json through them"
symbols:
  - Kumwe\App\BusinessDefinition\Application\FormulaEvaluation
  - Kumwe\App\BusinessReporting\Application\ReportMaterialization
  - Kumwe\App\BusinessRecord\Application\RecordRuleValidator
  - Kumwe\App\BusinessRecord\Application\RecordFieldVisibility
  - Kumwe\App\BusinessRecord\Application\BusinessRecordView
  - Kumwe\App\BusinessReporting\Application\ReportService
  - Kumwe\App\Application\Automation\ChangePlan
layer: application
capability_index_sha256: "ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826"
packages_reviewed:
  - package: kumwe/computation
    version: 0.3.3
    symbols_inspected:
      - Kumwe\Computation\Compiler
      - Kumwe\Computation\Executor
      - Kumwe\Computation\NativeAdapter
      - Kumwe\Computation\NativeCompatibility
      - Kumwe\Computation\ContractIdentity
      - Kumwe\Computation\PlanIdentity
      - Kumwe\Computation\ProgramEnvelope
      - Kumwe\Computation\DocumentBatch
      - Kumwe\Computation\ExecutionLimits
      - Kumwe\Computation\ExecutionRefused
      - Kumwe\Computation\NativeCanonicalEncoder
    source_inspected:
      - vendor/kumwe/computation/src
      - vendor/kumwe/computation/docs/native-boundary.md
      - vendor/kumwe/computation/docs/conformance.md
      - vendor/kumwe/computation/docs/public-api.md
    tests_inspected:
      - vendor/kumwe/computation/tests
      - vendor/kumwe/computation/resources/public-api/v1.json
  - package: kumwe/canonical-json
    version: 0.1.1
    symbols_inspected:
      - Kumwe\CanonicalJson\CanonicalEncoder
      - Kumwe\CanonicalJson\Limits
    source_inspected:
      - vendor/kumwe/canonical-json/src
      - vendor/kumwe/canonical-json/docs/semantics.md
      - vendor/kumwe/canonical-json/docs/ownership.md
    tests_inspected:
      - vendor/kumwe/canonical-json/tests
      - vendor/kumwe/canonical-json/resources/public-api/v1.json
  - package: kumwe/business-definition
    version: 0.1.2
    symbols_inspected:
      - Kumwe\BusinessDefinition\Expression
      - Kumwe\BusinessDefinition\ReportDefinition
      - Kumwe\BusinessDefinition\EntityTypeDefinition
    source_inspected:
      - vendor/kumwe/business-definition/src
      - vendor/kumwe/business-definition/docs/formula-profile.md
      - vendor/kumwe/business-definition/docs/boundary-review.md
    tests_inspected:
      - vendor/kumwe/business-definition/resources/public-api/v1.json
search_terms:
  - "formula evaluation"
  - "expression evaluator"
  - "condition"
  - "report materialization"
  - "group aggregate sort"
  - "decimal value"
  - "canonical json digest"
  - "change plan digest"
  - "field visibility"
  - "record rule"
required_capability: "Evaluate business conditions and formulas and materialize report rows on the native kumwe/computation engine through two App application ports, so that the record validator, the field-visibility rule, the record view, the report service and the change plan consume the package compiler, executor and canonical encoder without App keeping a PHP evaluator, decimal arithmetic or canonical encoder of its own."
consumers:
  - "src/BusinessDefinition/Infrastructure/Computation/NativeFormulaEvaluation.php"
  - "src/BusinessReporting/Infrastructure/Computation/NativeReportMaterialization.php"
  - "src/BusinessRecord/Application/BusinessRecordService.php"
  - "src/BusinessRecord/Infrastructure/Persistence/DoctrineBusinessRecordReadRepository.php"
  - "src/BusinessSchema/Infrastructure/Schema/DoctrinePhysicalSchemaGateway.php"
  - "src/BusinessSurface/Application/BusinessSurfaceService.php"
  - "src/Infrastructure/Automation/DoctrineScheduler.php"
  - "src/Kernel/ContainerFactory.php"
  - "src/Kernel/NativeComputationFactory.php"
  - "tests/Unit/BusinessDefinition/Infrastructure/Computation/NativeFormulaEvaluationConformanceTest.php"
  - "tests/Unit/BusinessReporting/Infrastructure/Computation/NativeReportMaterializationConformanceTest.php"
  - "tests/Unit/Kernel/CanonicalEncoderConformanceTest.php"
  - "tests/Unit/BusinessRecord/Application/RecordRuleValidatorTest.php"
  - "tests/Unit/BusinessRecord/Application/BusinessRecordViewTest.php"
  - "tests/Unit/BusinessReporting/ReportPolicyInferenceTest.php"
  - "tests/Unit/Application/Automation/ChangePlanTest.php"
overlap_reviewed:
  - Kumwe\Computation\Compiler
  - Kumwe\Computation\Executor
  - Kumwe\Computation\NativeAdapter
decision: approved
decided_by: "eWɘyn (KUMWE-MIG-2026-008 Phase 2 cutover, standing maintainer mandate)"
reviewer: "eWɘyn (package-boundary review against the installed kumwe/computation 0.3.3, kumwe/canonical-json 0.1.1 and kumwe/business-definition 0.1.2 sources, manifests and conformance corpora)"
decided_on: "2026-09-23"
pull_request: null
---

## Capability required

A business record is accepted only when every condition and formula its entity type declares evaluates
on the values the caller supplied; a field is shown only when its visibility condition holds; a report
returns rows grouped, aggregated, computed and sorted as its definition says; and a change plan carries a
digest that identifies the same change bytes on every host. Before the Phase 2 cutover App evaluated
formulas in its own `ExpressionEvaluator`, did report arithmetic in its own `DecimalValue`, and produced
digests with its own `CanonicalJson`. The cutover removes all three: conditions and formulas are compiled
and executed by the native engine under the `formula-draft/1` contract, report grouping, aggregation,
formulas and sorting run under `report-materialization-draft/1`, and every digest comes from the
`kumwe-canonical-json/generic-v1` encoder `kumwe/computation` binds. The seven symbols this record names
are what App still owns around that engine: two application ports that state the evaluation and
materialization capabilities in App's vocabulary, and five application services whose constructors or
factories now take those ports or the package encoder.

## Why existing package APIs are insufficient

`kumwe/computation` 0.3.3 is consumed entirely and nothing of it is duplicated. Its `Compiler` and
`Executor` ports and the `NativeAdapter` that implements them speak in `ProgramEnvelope`, `PlanIdentity`,
`DocumentBatch` and `ExecutionResult`: a caller must know the contract profile, the program version, the
generation and schema tokens of a plan identity, the correlation-token grammar, the document envelope the
engine reads, the result shape it writes and the refusal codes it returns. App's record validator,
visibility rule, record view and report service should know none of that; they ask "what does this
expression evaluate to for these fields" and "which rows does this report produce". `FormulaEvaluation`
and `ReportMaterialization` are those two questions stated on App's own `Expression` and
`ReportDefinition` types, and their native adapters in the infrastructure layer own every engine detail.
`kumwe/canonical-json` 0.1.1 owns the encoder contract that `ChangePlan` now receives, and
`kumwe/business-definition` 0.1.2 owns the definitions the ports accept; neither package evaluates
anything, and its formula profile document describes the language the engine implements, not a PHP
implementation.

## Why extending the owning package is inappropriate

`kumwe/computation` owns the native boundary: compilation, execution, plan identity, limits and refusals.
An App port that hides the boundary behind App's definition types would bind the package to
`kumwe/business-definition` and to App's document schema tokens, dependency-presence rules and refusal
wording, which the package charter keeps with the host. `kumwe/canonical-json` owns bytes and digests,
not the change-plan fields App digests. The three overlap symbols named above were reviewed for this
reason: `Compiler`, `Executor` and `NativeAdapter` are the engine boundary the App ports wrap, they share
no method name with the App ports, and the App ports carry App semantics the package must not learn.

## Why a new focused package is inappropriate

The two ports and five services have no consumer outside App and no algorithm of their own. The portable
algorithm, the engine and its contracts, is already the package; what remains is App deciding which
expressions to evaluate for which record, which fields to hide, which rows to hand to the engine and what
a change plan is made of.

## App-specific responsibility

This is host composition and orchestration. `RecordRuleValidator` and `RecordFieldVisibility` decide
which declared conditions run against which record values and how a refusal becomes a validation
violation or a hidden field; `BusinessRecordView` composes the visibility rule into the read model;
`ReportService` resolves scope and rows, decides when a report needs materialization at all and applies
the export budget; `ChangePlan` decides which fields identify a change. The public surfaces changed only
by taking the ports or the package encoder through their constructors and factories, so that
`ContainerFactory` composes them from `NativeComputationFactory`. Outside App these classes would have to
carry App record, report and automation semantics into a library every host shares.

## Tests proving the boundary

- `tests/Unit/BusinessDefinition/Infrastructure/Computation/NativeFormulaEvaluationConformanceTest.php`
  replays the package `formula-draft/1` corpus through the App port and pins that every accepted vector
  returns the corpus value and every refused vector is refused with the App wording; it proves the port
  adds nothing to the language.
- `tests/Unit/BusinessReporting/Infrastructure/Computation/NativeReportMaterializationConformanceTest.php`
  replays the `report-materialization-draft/1` corpus and pins row order and refusals.
- `tests/Unit/Kernel/CanonicalEncoderConformanceTest.php` replays the generic-v1 corpus through the
  encoder the factory shares and pins the change-plan and schedule-occurrence digests against the bytes the
  retired App encoder produced, so that no persisted digest moves.
- `tests/Unit/BusinessRecord/Application/RecordRuleValidatorTest.php`,
  `tests/Unit/BusinessRecord/Application/BusinessRecordViewTest.php`,
  `tests/Unit/BusinessReporting/ReportPolicyInferenceTest.php` and
  `tests/Unit/Application/Automation/ChangePlanTest.php` pin that the services consume the ports and the
  encoder through their constructors and that a definition-narrowed view refuses without the rule.
- `tests/Architecture/CanonicalJsonSemanticIdentityGateTest.php` pins that `Kumwe\App\Shared\Domain\CanonicalJson`
  no longer exists, and the package suites own the corpora.

## Decision

Approved on 2026-09-23 under the standing maintainer mandate as part of the KUMWE-MIG-2026-008 Phase 2
cutover. The review compared the seven classes against the installed computation, canonical-json and
business-definition sources, manifests and conformance corpora and found that they consume the package
compiler, executor and encoder without duplicating them and keep only App composition and orchestration.
This record is the cutover custodian's decision and review under that mandate; it is not a human
pull-request review event. Revisit when `kumwe/computation` next releases a contract profile that changes
the formula or report document envelope, or when `kumwe/business-definition` ships an evaluation port of
its own, so that the App ports can be retired in favour of it.
