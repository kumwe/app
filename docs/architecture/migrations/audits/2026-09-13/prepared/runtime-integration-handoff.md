---
schema: kumwe-migration-handoff/v2
artifact_kind: framework_php
migration_id: KUMWE-MIG-2026-027
change_set: KUMWE-CS-2026-035
state: draft_pr_open
target:
  repository: https://github.com/kumwe/integration
  artifact_identity: kumwe/integration
  canonical_namespace_or_abi: Kumwe\Integration
  branch: fix/installed-consumer-readiness
  pull_request: https://github.com/kumwe/integration/pull/8
source:
  app:
    repository: https://github.com/kumwe/app
    baseline_commit: 24ecf956423c18933e824b43cea1bfb9127a79a9
    examined_paths:
    - src/BusinessIntegration/Application/EventContractRegistry.php
    - src/BusinessIntegration/Application/InboxClaimResult.php
    - src/BusinessIntegration/Application/InboxDisposition.php
    - src/BusinessIntegration/Application/InboxLease.php
    - src/BusinessIntegration/Application/InboxStore.php
    - src/BusinessIntegration/Application/OutboxLease.php
    - src/BusinessIntegration/Application/OutboxStore.php
    - src/BusinessIntegration/Application/PayloadSchemaValidator.php
    - src/BusinessIntegration/Application/ProcessManagerHandler.php
    - src/BusinessIntegration/Application/ProcessManagerStore.php
    - src/BusinessIntegration/Application/ProcessWorkLease.php
    - src/BusinessIntegration/Domain/EventSchemaDefinition.php
    - src/BusinessIntegration/Domain/ProcessInstance.php
    - src/BusinessIntegration/Domain/ProcessStatus.php
    - src/BusinessIntegration/Domain/ProcessTransition.php
    - src/BusinessIntegration/Domain/ProcessWorkItem.php
    - src/BusinessIntegration/Domain/ProcessWorkKind.php
    - src/BusinessIntegration/Domain/RecordedDomainEvent.php
    - src/BusinessIntegration/Domain/RecordedEventEnvelope.php
    - src/BusinessIntegration/Domain/RecordedIntegrationEvent.php
    - composer.json
    - docs/architecture/capability-index.md
    old_namespace_roots:
    - Kumwe\App\BusinessIntegration\Application\
    - Kumwe\App\BusinessIntegration\Domain\
    capability_index_sha256: null
  semantic_inputs: []
  examined_dependencies:
  - php ^8.5
  - kumwe/canonical-json 0.1.1
  - kumwe/contribution 0.1.1
  - kumwe/access-context 0.1.2
  - kumwe/automation 0.2.2
  - ramsey/uuid ^4.7
  - ext-mbstring *
  - psr/container ^2.0
  active_related_pull_requests: []
framework_php:
  composer_package: kumwe/integration
  canonical_namespace: Kumwe\Integration
  public_api_manifest: resources/public-api/v1.json
  capability_manifest: resources/capabilities/v1.json
  service_map: resources/service-map/v1.json
  extracted_symbols:
  - old_fqcn: Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition
    new_fqcn: Kumwe\Integration\EventSchemaDefinition
    source_path: src/BusinessIntegration/Domain/EventSchemaDefinition.php
    target_path: src/EventSchemaDefinition.php
    kind: class
    public_methods:
    - __construct
    - eventType
    - fromArray
    - identifier
    - maximumBytes
    - payloadSchema
    - schemaVersion
    - sensitivity
    - toArray
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Domain\ProcessInstance
    new_fqcn: Kumwe\Integration\ProcessInstance
    source_path: src/BusinessIntegration/Domain/ProcessInstance.php
    target_path: src/ProcessInstance.php
    kind: class
    public_methods:
    - __construct
    - actorId
    - cancel
    - cancellationBy
    - cancellationNote
    - correlationId
    - createdAt
    - id
    - organizationId
    - processType
    - siteIdentifier
    - state
    - status
    - systemIdentity
    - transition
    - updatedAt
    - version
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Domain\ProcessStatus
    new_fqcn: Kumwe\Integration\ProcessStatus
    source_path: src/BusinessIntegration/Domain/ProcessStatus.php
    target_path: src/ProcessStatus.php
    kind: enum
    public_methods:
    - cases
    - from
    - tryFrom
    public_properties:
    - name
    - value
    public_constants:
    - CANCELLED
    - COMPLETED
    - FAILED
    - RUNNING
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Domain\ProcessTransition
    new_fqcn: Kumwe\Integration\ProcessTransition
    source_path: src/BusinessIntegration/Domain/ProcessTransition.php
    target_path: src/ProcessTransition.php
    kind: class
    public_methods:
    - __construct
    - state
    - status
    - work
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Domain\ProcessWorkItem
    new_fqcn: Kumwe\Integration\ProcessWorkItem
    source_path: src/BusinessIntegration/Domain/ProcessWorkItem.php
    target_path: src/ProcessWorkItem.php
    kind: class
    public_methods:
    - __construct
    - dueAt
    - id
    - kind
    - maximumAttempts
    - name
    - payload
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Domain\ProcessWorkKind
    new_fqcn: Kumwe\Integration\ProcessWorkKind
    source_path: src/BusinessIntegration/Domain/ProcessWorkKind.php
    target_path: src/ProcessWorkKind.php
    kind: enum
    public_methods:
    - cases
    - from
    - tryFrom
    public_properties:
    - name
    - value
    public_constants:
    - COMMAND
    - COMPENSATION
    - TIMER
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Domain\RecordedDomainEvent
    new_fqcn: Kumwe\Integration\RecordedDomainEvent
    source_path: src/BusinessIntegration/Domain/RecordedDomainEvent.php
    target_path: src/RecordedDomainEvent.php
    kind: class
    public_methods: []
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Domain\RecordedEventEnvelope
    new_fqcn: Kumwe\Integration\RecordedEventEnvelope
    source_path: src/BusinessIntegration/Domain/RecordedEventEnvelope.php
    target_path: src/RecordedEventEnvelope.php
    kind: class
    public_methods:
    - __construct
    - actorId
    - aggregateId
    - aggregateType
    - aggregateVersion
    - causationId
    - correlationId
    - document
    - eventId
    - eventType
    - fromArray
    - occurredAt
    - organizationId
    - payload
    - schemaVersion
    - sensitivity
    - siteIdentifier
    - systemIdentity
    - toArray
    public_properties: []
    public_constants:
    - MAX_PAYLOAD_BYTES
    - MAX_PAYLOAD_DEPTH
    - MAX_PAYLOAD_NODES
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Domain\RecordedIntegrationEvent
    new_fqcn: Kumwe\Integration\RecordedIntegrationEvent
    source_path: src/BusinessIntegration/Domain/RecordedIntegrationEvent.php
    target_path: src/RecordedIntegrationEvent.php
    kind: class
    public_methods:
    - fromDomain
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\Extension\Spi\BusinessIntegration\Domain\ConsumerIdempotency
    new_fqcn: Kumwe\Integration\ConsumerIdempotency
    source_path: src/Spi/BusinessIntegration/Domain/ConsumerIdempotency.php
    target_path: src/ConsumerIdempotency.php
    kind: enum
    public_methods:
    - cases
    - from
    - tryFrom
    public_properties:
    - name
    - value
    public_constants:
    - AGGREGATE_VERSION
    - EVENT_ID
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainEvent
    new_fqcn: Kumwe\Integration\DomainEvent
    source_path: src/Spi/BusinessIntegration/Domain/DomainEvent.php
    target_path: src/DomainEvent.php
    kind: interface
    public_methods: []
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainListenerDeclaration
    new_fqcn: Kumwe\Integration\DomainListenerDeclaration
    source_path: src/Spi/BusinessIntegration/Domain/DomainListenerDeclaration.php
    target_path: src/DomainListenerDeclaration.php
    kind: class
    public_methods:
    - accepts
    - eventType
    - fromManifest
    - identifier
    - schemaVersions
    - sensitivityCeiling
    - toArray
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainListenerDefinition
    new_fqcn: Kumwe\Integration\DomainListenerDefinition
    source_path: src/Spi/BusinessIntegration/Domain/DomainListenerDefinition.php
    target_path: src/DomainListenerDefinition.php
    kind: class
    public_methods:
    - __construct
    - accepts
    - eventType
    - fromArray
    - handlerVersion
    - identifier
    - priority
    - schemaVersions
    - sensitivityCeiling
    - toArray
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDeclaration
    new_fqcn: Kumwe\Integration\EventConsumerDeclaration
    source_path: src/Spi/BusinessIntegration/Domain/EventConsumerDeclaration.php
    target_path: src/EventConsumerDeclaration.php
    kind: class
    public_methods:
    - accepts
    - eventType
    - fromManifest
    - identifier
    - schemaVersions
    - sensitivityCeiling
    - toArray
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition
    new_fqcn: Kumwe\Integration\EventConsumerDefinition
    source_path: src/Spi/BusinessIntegration/Domain/EventConsumerDefinition.php
    target_path: src/EventConsumerDefinition.php
    kind: class
    public_methods:
    - __construct
    - acceptsVersion
    - aggregateOrdered
    - eventType
    - fromArray
    - handlerVersion
    - idempotency
    - identifier
    - maximumAttempts
    - queue
    - schemaVersions
    - sensitivityCeiling
    - toArray
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\Extension\Spi\BusinessIntegration\Domain\EventEnvelope
    new_fqcn: Kumwe\Integration\EventEnvelope
    source_path: src/Spi/BusinessIntegration/Domain/EventEnvelope.php
    target_path: src/EventEnvelope.php
    kind: interface
    public_methods:
    - actorId
    - aggregateId
    - aggregateType
    - aggregateVersion
    - causationId
    - correlationId
    - eventId
    - eventType
    - occurredAt
    - organizationId
    - payload
    - schemaVersion
    - sensitivity
    - siteIdentifier
    - systemIdentity
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity
    new_fqcn: Kumwe\Integration\EventSensitivity
    source_path: src/Spi/BusinessIntegration/Domain/EventSensitivity.php
    target_path: src/EventSensitivity.php
    kind: enum
    public_methods:
    - allowedBy
    - cases
    - from
    - tryFrom
    public_properties:
    - name
    - value
    public_constants:
    - INTERNAL
    - PUBLIC
    - RESTRICTED
    - SECRET
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationContract
    new_fqcn: Kumwe\Integration\IntegrationContract
    source_path: src/Spi/BusinessIntegration/Domain/IntegrationContract.php
    target_path: src/IntegrationContract.php
    kind: interface
    public_methods:
    - identifier
    - toArray
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationContractValidator
    new_fqcn: Kumwe\Integration\IntegrationContractValidator
    source_path: src/Spi/BusinessIntegration/Domain/IntegrationContractValidator.php
    target_path: src/IntegrationContractValidator.php
    kind: class
    public_methods:
    - boolean
    - identifier
    - identifiers
    - integer
    - keys
    - listField
    - object
    - objectField
    - string
    - token
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent
    new_fqcn: Kumwe\Integration\IntegrationEvent
    source_path: src/Spi/BusinessIntegration/Domain/IntegrationEvent.php
    target_path: src/IntegrationEvent.php
    kind: interface
    public_methods: []
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\Extension\Spi\BusinessIntegration\Domain\WebhookContributionDefinition
    new_fqcn: Kumwe\Integration\WebhookContributionDefinition
    source_path: src/Spi/BusinessIntegration/Domain/WebhookContributionDefinition.php
    target_path: src/WebhookContributionDefinition.php
    kind: class
    public_methods:
    - __construct
    - accepts
    - eventTypes
    - fromArray
    - handlerVersion
    - idempotency
    - identifier
    - maximumAttempts
    - queue
    - schemaVersions
    - sensitivityCeiling
    - toArray
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\Extension\Spi\BusinessIntegration\Domain\WebhookDeclaration
    new_fqcn: Kumwe\Integration\WebhookDeclaration
    source_path: src/Spi/BusinessIntegration/Domain/WebhookDeclaration.php
    target_path: src/WebhookDeclaration.php
    kind: class
    public_methods:
    - accepts
    - eventTypes
    - fromManifest
    - identifier
    - schemaVersions
    - sensitivityCeiling
    - toArray
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Application\OutboxStore
    new_fqcn: Kumwe\Integration\OutboxStore
    source_path: src/BusinessIntegration/Application/OutboxStore.php
    target_path: src/OutboxStore.php
    kind: interface
    public_methods:
    - append
    - claim
    - complete
    - defer
    - fail
    - purgeExpired
    - recent
    - renew
    - replay
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Application\InboxStore
    new_fqcn: Kumwe\Integration\InboxStore
    source_path: src/BusinessIntegration/Application/InboxStore.php
    target_path: src/InboxStore.php
    kind: interface
    public_methods:
    - complete
    - fail
    - receive
    - recent
    - renew
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Application\ProcessManagerStore
    new_fqcn: Kumwe\Integration\ProcessManagerStore
    source_path: src/BusinessIntegration/Application/ProcessManagerStore.php
    target_path: src/ProcessManagerStore.php
    kind: interface
    public_methods:
    - claimWork
    - completeWork
    - create
    - failWork
    - findByCorrelation
    - load
    - recent
    - renewWork
    - save
    - work
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Application\ProcessManagerHandler
    new_fqcn: Kumwe\Integration\ProcessManagerHandler
    source_path: src/BusinessIntegration/Application/ProcessManagerHandler.php
    target_path: src/ProcessManagerHandler.php
    kind: interface
    public_methods:
    - apply
    - correlationId
    - processType
    - start
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Application\EventContractRegistry
    new_fqcn: Kumwe\Integration\EventContractRegistry
    source_path: src/BusinessIntegration/Application/EventContractRegistry.php
    target_path: src/EventContractRegistry.php
    kind: class
    public_methods:
    - __construct
    - assertEvent
    - consumer
    - consumersFor
    - replace
    - schema
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Application\PayloadSchemaValidator
    new_fqcn: Kumwe\Integration\PayloadSchemaValidator
    source_path: src/BusinessIntegration/Application/PayloadSchemaValidator.php
    target_path: src/PayloadSchemaValidator.php
    kind: class
    public_methods:
    - assertPayload
    - assertSchema
    public_properties: []
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Application\InboxDisposition
    new_fqcn: Kumwe\Integration\InboxDisposition
    source_path: src/BusinessIntegration/Application/InboxDisposition.php
    target_path: src/InboxDisposition.php
    kind: enum
    public_methods:
    - cases
    - from
    - tryFrom
    public_properties:
    - name
    - value
    public_constants:
    - BUSY
    - CLAIMED
    - DUPLICATE
    - POISON
    - REORDERED
    - UNAVAILABLE
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Application\InboxClaimResult
    new_fqcn: Kumwe\Integration\InboxClaimResult
    source_path: src/BusinessIntegration/Application/InboxClaimResult.php
    target_path: src/InboxClaimResult.php
    kind: class
    public_methods:
    - __construct
    public_properties:
    - disposition
    - lease
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Application\InboxLease
    new_fqcn: Kumwe\Integration\InboxLease
    source_path: src/BusinessIntegration/Application/InboxLease.php
    target_path: src/InboxLease.php
    kind: class
    public_methods:
    - __construct
    public_properties:
    - attempts
    - consumer
    - event
    - leaseToken
    - runtimeGeneration
    - workerId
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Application\OutboxLease
    new_fqcn: Kumwe\Integration\OutboxLease
    source_path: src/BusinessIntegration/Application/OutboxLease.php
    target_path: src/OutboxLease.php
    kind: class
    public_methods:
    - __construct
    public_properties:
    - attempts
    - event
    - leaseToken
    - maximumAttempts
    - runtimeGeneration
    - workerId
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  - old_fqcn: Kumwe\App\BusinessIntegration\Application\ProcessWorkLease
    new_fqcn: Kumwe\Integration\ProcessWorkLease
    source_path: src/BusinessIntegration/Application/ProcessWorkLease.php
    target_path: src/ProcessWorkLease.php
    kind: class
    public_methods:
    - __construct
    public_properties:
    - attempts
    - correlationId
    - leaseToken
    - organizationId
    - processId
    - processVersion
    - runtimeGeneration
    - siteIdentifier
    - work
    - workerId
    public_constants: []
    exceptions: []
    serialization_contract: Explicit scalar projections documented in docs/public-api.md; PHP native serialization
      is not a durable wire or authority contract.
    compatibility: Portable behavior is retained under the canonical namespace. Package-owned boundary and regression
      tests document deliberate validation and scheduling corrections.
  consumers:
    app_code:
    - src/BusinessIntegration/Application/BusinessRecordMutationEventPublisher.php
    - src/BusinessIntegration/Application/EventContractRegistry.php
    - src/BusinessIntegration/Application/IntegrationOperationsService.php
    - src/BusinessIntegration/Application/JobQueueIntegrationEventHandler.php
    - src/BusinessIntegration/Application/JobQueueProcessWorkHandler.php
    - src/BusinessIntegration/Application/ProcessManagerHandler.php
    - src/BusinessIntegration/Application/ProcessManagerService.php
    - src/BusinessIntegration/Application/ProcessManagerStore.php
    - src/BusinessIntegration/Application/ProcessWorkHandler.php
    - src/BusinessIntegration/Application/ProcessWorkLease.php
    - src/BusinessIntegration/Infrastructure/ContributedScheduleSynchronizer.php
    - src/BusinessIntegration/Infrastructure/DoctrineInboxStore.php
    - src/BusinessIntegration/Infrastructure/DoctrineOutboxStore.php
    - src/BusinessIntegration/Infrastructure/DoctrineProcessManagerStore.php
    - src/BusinessIntegration/Infrastructure/RuntimeIntegrationEventTransport.php
    - src/BusinessReporting/Infrastructure/DoctrineProjectionStore.php
    - src/Extension/Contribution/CanonicalManifestInterpreter.php
    - src/Extension/Contribution/CoreContributionRegistrar.php
    - src/Extension/Contribution/CoreExtensionContributions.php
    - src/Extension/Contribution/ExtensionContributionRegistrySet.php
    - src/Kernel/ContainerFactory.php
    configuration_and_di: []
    reflection_and_string_references:
    - Recompute same-namespace, reflected and dynamically constructed names before App adoption; exact source inventory
      is evidence, not a complete dynamic reference proof.
    fixtures_and_examples:
    - examples/consumer.php
    external:
    - src/Manifest/ManifestContributionGraphValidator.php
    - src/Manifest/ManifestContributions.php
    - src/Spi/BusinessIntegration/Application/DomainEventHandler.php
    - src/Spi/BusinessIntegration/Application/IntegrationEventHandler.php
    - src/Spi/BusinessIntegration/Application/IntegrationEventTransport.php
    - src/Spi/BusinessReporting/Domain/ProjectionDefinition.php
  dependency_injection:
    mode: config-provider
    provider: Kumwe\Integration\ConfigProvider
    factories:
    - Kumwe\Integration\Container\EventContractRegistryFactory
    aliases: []
    service_lifetimes:
    - 'Kumwe\Integration\EventContractRegistry: shared'
    configuration_keys:
    - kumwe.integration.schemas
    - kumwe.integration.consumers
    provider_absence_reason: null
ownership:
  responsibility: Portable event contracts, validation, delivery values and contribution definitions.
  non_responsibilities:
  - Host trust and final authorization
  - Persistence, durable transactions, worker and transport lifecycle
  - App runtime adoption and native release publication
  allowed_dependency_ceiling:
  - php
  - kumwe/canonical-json
  - kumwe/contribution
  - kumwe/access-context
  - kumwe/automation
  - ramsey/uuid
  - ext-mbstring
  - psr/container
  implementation_owner: kumwe/integration
  next_consumer: kumwe/app
  public_manifests:
  - path: resources/public-api/v1.json
    sha256: c95efa67274c4c989703db6f0f862953704ac4cdede6c515cc9cc06f20852a5c
  - path: resources/capabilities/v1.json
    sha256: dd98607038e6d72dc9a0fa5d2e1f524102f6fc20eb4fc32bfbca2f3871334cfe
  - path: resources/service-map/v1.json
    sha256: 99951ae2eadb277d96b9aa0f282634840a8e3b90a60372885a15dcb7aa5a91d2
  intentionally_excluded:
  - Event contracts, schema validation and portable integration declarations are implemented and tested here. Transport,
    outbox persistence, commit ordering, network deadlines and final authorization remain host responsibilities.
native_cpp: null
php_extension: null
tests:
  moved_or_added:
  - tools/verify-clean-consumer.php
  - tests/bootstrap.php
  - tests/container.php
  - tests/run.php
  remain_in_app_or_consumer:
  - tests/Functional/Extension/LiveSurfaceContractParityTest.php
  - tests/Integration/BusinessIntegration/BusinessIntegrationPersistenceTest.php
  - tests/Integration/BusinessIntegration/HungEndpointDeadlineIntegrationTest.php
  - tests/Integration/BusinessIntegration/OutboxInboxClaimContentionIntegrationTest.php
  - tests/Integration/BusinessIntegration/PoisonAndDeadLetterIntegrationTest.php
  - tests/Integration/BusinessIntegration/ProjectionRuntimePersistenceTest.php
  - tests/Integration/Extension/GeneratedExtensionLifecycleIntegrationTest.php
  - tests/Integration/Persistence/TransactionBoundaryEngineIntegrationTest.php
  - tests/Support/AssetInspectionDeploymentAcceptance.php
  - tests/Unit/BusinessIntegration/Application/BusinessRecordMutationEventPublisherTest.php
  - tests/Unit/BusinessIntegration/Application/DurableOutboundAdapterDeliveryTest.php
  - tests/Unit/BusinessIntegration/Application/JobQueueIntegrationEventHandlerTest.php
  - tests/Unit/BusinessIntegration/ConsumerDispatcherTest.php
  - tests/Unit/BusinessIntegration/Domain/IntegrationContributionDefinitionTest.php
  - tests/Unit/BusinessIntegration/DurableOutboundAdapterDispatcherTest.php
  - tests/Unit/BusinessIntegration/EventContractTest.php
  - tests/Unit/BusinessIntegration/Infrastructure/RuntimeIntegrationEventTransportTest.php
  - tests/Unit/BusinessIntegration/IntegrationOperationsServiceTest.php
  - tests/Unit/BusinessIntegration/OutboxDispatcherTest.php
  - tests/Unit/BusinessIntegration/ProcessCancellationWorkTest.php
  - tests/Unit/BusinessIntegration/ProcessInstanceTest.php
  - tests/Unit/BusinessIntegration/ProcessWorkDispatcherTest.php
  - tests/Unit/BusinessRecord/Application/BusinessRecordMutationPublicationTest.php
  - tests/Unit/Extension/Contribution/ExtensionBindingSurfaceTest.php
  - tests/Unit/Extension/Contribution/OwnedBindingCanonicalDriftTest.php
  split_tests:
  - Remove only library implementation assertions after verified App adoption; retain host wiring and composed behavior
    assertions.
  prohibited_duplicates:
  - App must not retain unit tests of vendor-owned implementation internals after adoption.
  corpora: []
documentation:
  charter: CHARTER.md
  readme: README.md
  public_api: docs/public-api.md
  architecture: docs/architecture.md
  integration_or_consumer: docs/integration.md
  examples:
  - examples/consumer.php
  changelog_record: CHANGELOG.md / 0.2.3
release_expectations:
  version_policy: Exact stable sibling package pins; preserve coherent released graphs until compatible successor
    releases exist.
  expected_artifact_types:
  - Composer package archive
  - GitHub source archive
  required_checks:
  - composer check
  - Final hosted package CI
  - Machine handoff and consumer schema validation
  - Full authoritative canonical manifests and migration-handoff schema validation with refusal regressions
  required_registry_or_installer: Composer
  required_external_attestation: true
next_task:
  phase_name: Review and verify the library successor release before separate App integration
  permitted_only_when:
  - Final package CI passes at the proposed head
  - Immutable package and all dependency releases are independently verified
  - Reconcile current App drift against the recorded source inventories
  consumer_repository: https://github.com/kumwe/app
  dependency_or_native_change: Install the exact independently verified successor; run Composer resolution, archive
    consumer gates and affected App integration tests before namespace removal. Published Automation 0.1.0 requires
    Contribution 0.1.0 and Access Context 0.1.0. Publish a compatible Automation successor first, then promote Integration
    Automation/Contribution/Access Context pins together. The current coherent release graph remains pinned.
  namespace_or_api_replacements:
  - Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition -> Kumwe\Integration\EventSchemaDefinition
  - Kumwe\App\BusinessIntegration\Domain\ProcessInstance -> Kumwe\Integration\ProcessInstance
  - Kumwe\App\BusinessIntegration\Domain\ProcessStatus -> Kumwe\Integration\ProcessStatus
  - Kumwe\App\BusinessIntegration\Domain\ProcessTransition -> Kumwe\Integration\ProcessTransition
  - Kumwe\App\BusinessIntegration\Domain\ProcessWorkItem -> Kumwe\Integration\ProcessWorkItem
  - Kumwe\App\BusinessIntegration\Domain\ProcessWorkKind -> Kumwe\Integration\ProcessWorkKind
  - Kumwe\App\BusinessIntegration\Domain\RecordedDomainEvent -> Kumwe\Integration\RecordedDomainEvent
  - Kumwe\App\BusinessIntegration\Domain\RecordedEventEnvelope -> Kumwe\Integration\RecordedEventEnvelope
  - Kumwe\App\BusinessIntegration\Domain\RecordedIntegrationEvent -> Kumwe\Integration\RecordedIntegrationEvent
  - Kumwe\Extension\Spi\BusinessIntegration\Domain\ConsumerIdempotency -> Kumwe\Integration\ConsumerIdempotency
  - Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainEvent -> Kumwe\Integration\DomainEvent
  - Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainListenerDeclaration -> Kumwe\Integration\DomainListenerDeclaration
  - Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainListenerDefinition -> Kumwe\Integration\DomainListenerDefinition
  - Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDeclaration -> Kumwe\Integration\EventConsumerDeclaration
  - Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition -> Kumwe\Integration\EventConsumerDefinition
  - Kumwe\Extension\Spi\BusinessIntegration\Domain\EventEnvelope -> Kumwe\Integration\EventEnvelope
  - Kumwe\Extension\Spi\BusinessIntegration\Domain\EventSensitivity -> Kumwe\Integration\EventSensitivity
  - Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationContract -> Kumwe\Integration\IntegrationContract
  - Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationContractValidator -> Kumwe\Integration\IntegrationContractValidator
  - Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent -> Kumwe\Integration\IntegrationEvent
  - Kumwe\Extension\Spi\BusinessIntegration\Domain\WebhookContributionDefinition -> Kumwe\Integration\WebhookContributionDefinition
  - Kumwe\Extension\Spi\BusinessIntegration\Domain\WebhookDeclaration -> Kumwe\Integration\WebhookDeclaration
  - Kumwe\App\BusinessIntegration\Application\OutboxStore -> Kumwe\Integration\OutboxStore
  - Kumwe\App\BusinessIntegration\Application\InboxStore -> Kumwe\Integration\InboxStore
  - Kumwe\App\BusinessIntegration\Application\ProcessManagerStore -> Kumwe\Integration\ProcessManagerStore
  - Kumwe\App\BusinessIntegration\Application\ProcessManagerHandler -> Kumwe\Integration\ProcessManagerHandler
  - Kumwe\App\BusinessIntegration\Application\EventContractRegistry -> Kumwe\Integration\EventContractRegistry
  - Kumwe\App\BusinessIntegration\Application\PayloadSchemaValidator -> Kumwe\Integration\PayloadSchemaValidator
  - Kumwe\App\BusinessIntegration\Application\InboxDisposition -> Kumwe\Integration\InboxDisposition
  - Kumwe\App\BusinessIntegration\Application\InboxClaimResult -> Kumwe\Integration\InboxClaimResult
  - Kumwe\App\BusinessIntegration\Application\InboxLease -> Kumwe\Integration\InboxLease
  - Kumwe\App\BusinessIntegration\Application\OutboxLease -> Kumwe\Integration\OutboxLease
  - Kumwe\App\BusinessIntegration\Application\ProcessWorkLease -> Kumwe\Integration\ProcessWorkLease
  files_to_update:
  - composer.json
  - composer.lock
  - src/BusinessIntegration/Application/BusinessRecordMutationEventPublisher.php
  - src/BusinessIntegration/Application/EventContractRegistry.php
  - src/BusinessIntegration/Application/IntegrationOperationsService.php
  - src/BusinessIntegration/Application/JobQueueIntegrationEventHandler.php
  - src/BusinessIntegration/Application/JobQueueProcessWorkHandler.php
  - src/BusinessIntegration/Application/ProcessManagerHandler.php
  - src/BusinessIntegration/Application/ProcessManagerService.php
  - src/BusinessIntegration/Application/ProcessManagerStore.php
  - src/BusinessIntegration/Application/ProcessWorkHandler.php
  - src/BusinessIntegration/Application/ProcessWorkLease.php
  - src/BusinessIntegration/Infrastructure/ContributedScheduleSynchronizer.php
  - src/BusinessIntegration/Infrastructure/DoctrineInboxStore.php
  - src/BusinessIntegration/Infrastructure/DoctrineOutboxStore.php
  - src/BusinessIntegration/Infrastructure/DoctrineProcessManagerStore.php
  - src/BusinessIntegration/Infrastructure/RuntimeIntegrationEventTransport.php
  - src/BusinessReporting/Infrastructure/DoctrineProjectionStore.php
  - src/Extension/Contribution/CanonicalManifestInterpreter.php
  - src/Extension/Contribution/CoreContributionRegistrar.php
  - src/Extension/Contribution/CoreExtensionContributions.php
  - src/Extension/Contribution/ExtensionContributionRegistrySet.php
  - src/Kernel/ContainerFactory.php
  files_to_remove:
  - src/BusinessIntegration/Application/EventContractRegistry.php
  - src/BusinessIntegration/Application/InboxClaimResult.php
  - src/BusinessIntegration/Application/InboxDisposition.php
  - src/BusinessIntegration/Application/InboxLease.php
  - src/BusinessIntegration/Application/InboxStore.php
  - src/BusinessIntegration/Application/OutboxLease.php
  - src/BusinessIntegration/Application/OutboxStore.php
  - src/BusinessIntegration/Application/PayloadSchemaValidator.php
  - src/BusinessIntegration/Application/ProcessManagerHandler.php
  - src/BusinessIntegration/Application/ProcessManagerStore.php
  - src/BusinessIntegration/Application/ProcessWorkLease.php
  - src/BusinessIntegration/Domain/EventSchemaDefinition.php
  - src/BusinessIntegration/Domain/ProcessInstance.php
  - src/BusinessIntegration/Domain/ProcessStatus.php
  - src/BusinessIntegration/Domain/ProcessTransition.php
  - src/BusinessIntegration/Domain/ProcessWorkItem.php
  - src/BusinessIntegration/Domain/ProcessWorkKind.php
  - src/BusinessIntegration/Domain/RecordedDomainEvent.php
  - src/BusinessIntegration/Domain/RecordedEventEnvelope.php
  - src/BusinessIntegration/Domain/RecordedIntegrationEvent.php
  tests_to_remove:
  - Implementation-owned portions only, after the package behavior suite and App integration suite pass.
  tests_to_retain_or_add:
  - tests/Functional/Extension/LiveSurfaceContractParityTest.php
  - tests/Integration/BusinessIntegration/BusinessIntegrationPersistenceTest.php
  - tests/Integration/BusinessIntegration/HungEndpointDeadlineIntegrationTest.php
  - tests/Integration/BusinessIntegration/OutboxInboxClaimContentionIntegrationTest.php
  - tests/Integration/BusinessIntegration/PoisonAndDeadLetterIntegrationTest.php
  - tests/Integration/BusinessIntegration/ProjectionRuntimePersistenceTest.php
  - tests/Integration/Extension/GeneratedExtensionLifecycleIntegrationTest.php
  - tests/Integration/Persistence/TransactionBoundaryEngineIntegrationTest.php
  - tests/Support/AssetInspectionDeploymentAcceptance.php
  - tests/Unit/BusinessIntegration/Application/BusinessRecordMutationEventPublisherTest.php
  - tests/Unit/BusinessIntegration/Application/DurableOutboundAdapterDeliveryTest.php
  - tests/Unit/BusinessIntegration/Application/JobQueueIntegrationEventHandlerTest.php
  - tests/Unit/BusinessIntegration/ConsumerDispatcherTest.php
  - tests/Unit/BusinessIntegration/Domain/IntegrationContributionDefinitionTest.php
  - tests/Unit/BusinessIntegration/DurableOutboundAdapterDispatcherTest.php
  - tests/Unit/BusinessIntegration/EventContractTest.php
  - tests/Unit/BusinessIntegration/Infrastructure/RuntimeIntegrationEventTransportTest.php
  - tests/Unit/BusinessIntegration/IntegrationOperationsServiceTest.php
  - tests/Unit/BusinessIntegration/OutboxDispatcherTest.php
  - tests/Unit/BusinessIntegration/ProcessCancellationWorkTest.php
  - tests/Unit/BusinessIntegration/ProcessInstanceTest.php
  - tests/Unit/BusinessIntegration/ProcessWorkDispatcherTest.php
  - tests/Unit/BusinessRecord/Application/BusinessRecordMutationPublicationTest.php
  - tests/Unit/Extension/Contribution/ExtensionBindingSurfaceTest.php
  - tests/Unit/Extension/Contribution/OwnedBindingCanonicalDriftTest.php
  di_or_provisioning_changes:
  - Register Kumwe\Integration\ConfigProvider and provide all explicit host ports documented in docs/integration.md.
  capability_index_changes:
  - Record ownership from the verified package capability and public API manifests.
  changelog_and_evidence_changes:
  - Record exact source, package archive and dependency identities in the external release attestation and App integration
    ledger.
  verification_commands:
  - composer check
  - Affected App integration suites
  - Complete App package governance gate
concurrency:
  likely_conflict_files:
  - App composer.json
  - App composer.lock
  - App provider configuration
  related_migrations: []
  ownership_conflicts: []
  integration_train: null
  resolution_rule: semantic-preservation
governance:
  roadmap_source_sha256: a202155ef1a65f5ab293d4f8397ebf4ac430db7f1e877c776bbe7851e6fe18d8
  roadmap_refs: []
  non_roadmap_refs:
  - NRM-2026-027
  completion_claim: false
decisions:
- Refuse empty-object required-field bypasses, nonfinite or malformed values and contradictory schemas; expose explicit
  registry composition.
- Event contracts, schema validation and portable integration declarations are implemented and tested here. Transport,
  outbox persistence, commit ordering, network deadlines and final authorization remain host responsibilities.
- Library behavior tests are package-owned. App changes, releases and external attestations are separate tasks.
- Correct malformed YAML front matter and validate complete authoritative schemas without changing runtime source
  or dependency versions.
blockers:
- Require final complete source/archive CI and independent publication verification for 0.2.3 before adoption.
---

# integration implementation handoff

## Migration/implementation summary

The 0.2.3 successor makes the shipped example use the actual consumer Composer autoloader, preserving source-checkout execution and supporting both preloaded inclusion and an explicit CLI path. The mandatory archive gate executes both installed forms and refuses a missing supplied path. Published 0.2.2 at ff42d4d52753a3fec338b930f665c4283a9f5805 remains unchanged. Actual published Automation 0.2.2 at cef19174a9c8a7a6008fe284481a235d13eec195 is selected; no App integration or package runtime API changes are included.

Refuse empty-object required-field bypasses, nonfinite or malformed values and contradictory schemas; expose explicit registry composition. [PR #8](https://github.com/kumwe/integration/pull/8) contains this successor. The changelog version describes the proposed artifact; it is not a publication observation.

## Public API and responsibility

Event contracts, schema validation and portable integration declarations are implemented and tested here. Transport, outbox persistence, commit ordering, network deadlines and final authorization remain host responsibilities. Every exported member is recorded in resources/public-api/v1.json and documented in docs/public-api.md. The current surface contains 35 types. 33 types have recorded extraction provenance; package-native composition is identified separately.

## Capability reuse/semantic input review

The implementation consumes the exact canonical dependency contracts recorded in composer.json. Install the exact independently verified successor; run Composer resolution, archive consumer gates and affected App integration tests before namespace removal. Published Automation 0.2.2 requires Contribution 0.1.1 and Access Context 0.1.2. Those compatible releases are selected together in the current exact dependency graph.

## Consumer inventory

The machine record lists actual source mappings, known consumer paths and concrete namespace replacements. resources/migration/consumer-inventory.json and resources/migration/source-map.json retain source digests where present. Dynamic references and same-namespace names must be searched again during adoption; the inventory does not imply that App has already switched ownership.

## Test ownership

Package tests own portable values, validation, service behavior, explicit construction and malformed-input regressions. The machine record identifies the source suites to split. Host persistence, transactions, authority, transport and operational integration stay in App. After verified adoption, remove duplicate library implementation assertions from App together with their legacy source.

## Next-task execution notes

The selected production dependency tuple is:

- kumwe/canonical-json 0.1.1
- kumwe/contribution 0.1.1
- kumwe/access-context 0.1.2
- kumwe/automation 0.2.2

Published dependency identities and independent archive consumers must be verified before adoption.
The package gate enforces agreement between Composer constraints and the dependency evidence coordinates.

Independent successor release verification and the final package gate remain necessary before App adoption. Install the exact independently verified successor; run Composer resolution, archive consumer gates and affected App integration tests before namespace removal. Published Automation 0.2.2 requires Contribution 0.1.1 and Access Context 0.1.2. Those compatible releases are selected together in the current exact dependency graph. Run final source and clean archive gates before admitting the package; then update the App dependency lock, replace namespaces, retain host adapters and remove only the inventoried portable legacy implementations.

## Drift check

Reconcile the recorded source commit and per-file source digests with the current App before adoption. Recompute all public manifest hashes together. Keep actual release observations and final tested commit identities outside the tested source tree to avoid self-referential evidence.

## Validation recipe and observed local results

Run composer check with the documented PHP runtime and extensions. The review added and exercised the boundary regressions described in CHANGELOG.md. A final gate pass, remote CI status and immutable release verification are distinct observations; neither a proposed version nor this handoff attests publication. See docs/integration.md and the package check scripts for the exact archive and runtime recipe.

