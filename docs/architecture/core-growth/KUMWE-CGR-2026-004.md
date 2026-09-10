---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-004
title: "Existing application consumers adopt canonical foundation package types"
symbols:
  - Kumwe\App\Application\Authorization\ResourceOwnershipScopeService
  - Kumwe\App\Application\Authorization\SiteGroupAdministration
  - Kumwe\App\Application\Automation\AutomationManagementService
  - Kumwe\App\Application\Presentation\Preference\PresentationPreferenceManager
  - Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService
  - Kumwe\App\BusinessIntegration\Application\IntegrationEventConsumerDispatcher
  - Kumwe\App\BusinessIntegration\Application\IntegrationOperationsService
  - Kumwe\App\BusinessIntegration\Application\ProcessWorkDispatcher
  - Kumwe\App\BusinessRecord\Application\BusinessRecordIdempotencyPurger
  - Kumwe\App\BusinessRecord\Application\PostingPeriodService
  - Kumwe\App\BusinessRecord\Application\RecordValueCodec
  - Kumwe\App\BusinessReporting\Application\ExportAttemptPublisher
  - Kumwe\App\BusinessReporting\Application\ExportGenerationService
  - Kumwe\App\BusinessReporting\Application\ExportService
  - Kumwe\App\BusinessSchema\Application\BusinessSchemaExecutor
  - Kumwe\App\BusinessSchema\Application\BusinessSchemaPlanner
  - Kumwe\App\BusinessSchema\Application\BusinessSchemaService
  - Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService
  - Kumwe\App\BusinessSecurity\Application\Approval\ApprovalService
  - Kumwe\App\BusinessSurface\Application\BusinessMutationPlanService
  - Kumwe\App\BusinessSurface\Application\BusinessOperationStatusService
  - Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog
  - Kumwe\App\BusinessSurface\Application\BusinessSurfaceService
  - Kumwe\App\BusinessSurface\Application\CustomBusinessActionExecutor
  - Kumwe\App\BusinessSurface\Application\GeneratedBusinessActionStepUp
  - Kumwe\App\BusinessSurface\Application\MutationPlanCipher
  - Kumwe\App\Content\Application\ContentModelService
  - Kumwe\App\Content\Application\TranslationGroupRepository
  - Kumwe\App\Extension\Application\Trust\TrustStore
  - Kumwe\App\Extension\Contribution\TranslationGroupDeclaration
  - Kumwe\App\Identity\Application\Administration\AccessControlService
  - Kumwe\App\Identity\Application\StepUp\TotpStepUpProvider
  - Kumwe\App\Localization\Application\MessageOverrideService
  - Kumwe\App\Localization\Application\SiteDefaultLocale
  - Kumwe\App\Media\Application\MediaService
  - Kumwe\App\Navigation\Application\NavigationService
  - Kumwe\App\Studio\Application\Host\StudioLocalizationHostPort
  - Kumwe\App\Studio\Application\Host\StudioProducerMutationBoundary
  - Kumwe\App\Studio\Application\Media\StudioMediaService
layer: application
capability_index_sha256: "580034e9b11a5921bb9b00616d61396e8f004da036f5c9a6a0fb042a793bc602"
packages_reviewed:
  - package: kumwe/transaction
    version: 0.1.2
    symbols_inspected:
      - "Kumwe\\Transaction\\Contract\\TransactionManager"
      - "Kumwe\\Transaction\\Contract\\TransactionState"
    source_inspected:
      - vendor/kumwe/transaction/src
    tests_inspected:
      - vendor/kumwe/transaction/docs/test-ownership.md
  - package: kumwe/localization
    version: 0.1.1
    symbols_inspected:
      - "Kumwe\\Localization\\Domain\\LocaleTag"
      - "Kumwe\\Localization\\Application\\DefaultLocaleProvider"
      - "Kumwe\\Localization\\Application\\Translator"
    source_inspected:
      - vendor/kumwe/localization/src
    tests_inspected:
      - vendor/kumwe/localization/docs/test-ownership.md
  - package: kumwe/secret-envelope
    version: 0.1.1
    symbols_inspected:
      - "Kumwe\\Secret\\Value\\EncryptedEnvelope"
      - "Kumwe\\Secret\\Contract\\EnvelopeCipher"
      - "Kumwe\\Secret\\Contract\\KeyProvider"
    source_inspected:
      - vendor/kumwe/secret-envelope/src
    tests_inspected:
      - vendor/kumwe/secret-envelope/docs/test-ownership.md
search_terms:
  - transaction boundary
  - afterCommit
  - language tag
  - site default locale
  - encrypted envelope
  - key rotation
required_capability: "Preserve existing App behavior while public signatures consume the exact independently verified package types replacing their retired App definitions. No new portable behavior, method, or model is introduced."
consumers:
  - src/Kernel/ContainerFactory.php
  - src/Localization/Application/SiteDefaultLocale.php
  - src/BusinessRecord/Infrastructure/Persistence/DoctrineRecordSecretRotation.php
overlap_reviewed: []
decision: approved
decided_by: "Llewellynvdm: delegated package adoption and merge instruction"
reviewer: "Codex (review delegated by Llewellynvdm)"
decided_on: "2026-09-10"
pull_request: "https://github.com/kumwe/app/pull/142"
---

## Capability required

Existing application consumers must continue accepting the same transaction, locale and encrypted-envelope values
after the owning classes move to the verified packages. This record covers signature substitutions only.

## Why existing package APIs are insufficient

The package APIs are sufficient for their portable responsibilities and are consumed directly. They do not
own the host services and persisted content or business-definition relationships whose signatures now name
those APIs. The growth gate detects the changed fully qualified parameter and return types.

## Why extending the owning package is inappropriate

Transaction orchestration, configured site defaults, stored content relationships, authorization and key
custody remain the existing host responsibilities. This change adds no algorithm or portable fallback to App.

## Why a new focused package is inappropriate

There is no new capability to extract in this change. Existing App consumers continue their established
role while the three portable foundations become dependencies. Later planned package adoptions may remove
additional consumers; this record does not give those consumers permanent ownership of portable behavior.

## App-specific responsibility

Keep current application orchestration and stored models connected to the package contracts. SiteDefaultLocale
implements the package DefaultLocaleProvider and keeps its existing database lookup, source-locale fallback
and cache behavior. Ciphertext format, key derivation labels and trusted associated-data coordinates remain
unchanged. The record does not authorize new public methods or additional reusable behavior.

## Tests proving the boundary

The retained localization settings and middleware tests, host key lifecycle and ExactValueCodec tests,
Doctrine transaction tests, and ContainerTest exercise actual App composition. Focused verification on
PHP 8.5.10 passed 76 tests and 213 assertions. Independent clean consumers verify all three exact package
release archives. The combined change is rebased onto merged sequence PR #139 at master 32d6a6f3. All pre-test local QA gates
pass, and the complete unit/architecture suites pass 3,321 tests with 68,925 assertions on PHP 8.5.10.
Hosted database and browser validation remains required.

## Decision

Approved on 2026-09-10 by Codex (review delegated by Llewellynvdm), under the user's instruction to complete
and merge this package-adoption work. The independent review compared all 39 listed source files with
App baseline 2d0a1199 and the exact released package types. After the documented namespace and cipher-type
substitutions, executable bodies are unchanged; imports and documentation name the canonical package APIs.
The only additional declaration is SiteDefaultLocale implementing the existing package DefaultLocaleProvider;
its method bodies, settings fallback and cache behavior are unchanged.

The review also verified the three released archives, public manifests, host test-ownership boundaries and
fresh no-dev consumers on PHP 8.5.10. The previously found missing CatalogueTranslator import in the test
container was corrected before this decision. Combined application QA and final PR evidence remain separate
required gates; this approval does not declare those checks complete.

Approval covers only these existing signatures consuming canonical package types and the unchanged host
responsibilities described above. It grants no approval for new public methods, new portable behavior,
future package upgrades or retaining behavior that a later package adoption must remove. Revisit this record
when a listed consumer gains behavior beyond these substitutions or moves to another extracted package.
