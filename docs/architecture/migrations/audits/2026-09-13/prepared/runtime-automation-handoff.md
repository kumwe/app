---
schema: "kumwe-migration-handoff/v2"
artifact_kind: "framework_php"
migration_id: "KUMWE-MIG-2026-026"
change_set: "KUMWE-CS-2026-026"
state: "draft_pr_open"
target:
  repository: "https://github.com/kumwe/automation"
  artifact_identity: "kumwe/automation"
  canonical_namespace_or_abi: "Kumwe\\Automation"
  branch: fix/installed-example-autoload
  pull_request: https://github.com/kumwe/automation/pull/7
source:
  app:
    repository: "https://github.com/kumwe/app"
    baseline_commit: "24ecf956423c18933e824b43cea1bfb9127a79a9"
    examined_paths:
      - "src/Application/Automation/AutomationNotFound.php"
      - "src/Application/Automation/CryptographicJitterSource.php"
      - "src/Application/Automation/ExpiredJobLease.php"
      - "src/Application/Automation/FailureClassification.php"
      - "src/Application/Automation/JitterSource.php"
      - "src/Application/Automation/JobEnvelope.php"
      - "src/Application/Automation/JobExecutionClass.php"
      - "src/Application/Automation/JobHandler.php"
      - "src/Application/Automation/JobHandlerRegistry.php"
      - "src/Application/Automation/JobLease.php"
      - "src/Application/Automation/JobQueue.php"
      - "src/Application/Automation/JobStatus.php"
      - "src/Application/Automation/PermanentFailure.php"
      - "src/Application/Automation/QueueRuntimePolicy.php"
      - "src/Application/Automation/QueueRuntimePolicyCatalog.php"
      - "src/Application/Automation/RetryDecision.php"
      - "src/Application/Automation/RetryPolicy.php"
      - "src/Application/Automation/StoredJob.php"
      - "src/Application/Automation/TransientFailure.php"
      - "src/Automation/Domain/CronExpression.php"
      - "src/BusinessIntegration/Domain/QueueContributionDefinition.php"
      - "src/BusinessIntegration/Domain/ScheduleContributionDefinition.php"
      - "composer.json"
      - "docs/architecture/capability-index.md"
    old_namespace_roots:
      - "Kumwe\\App\\Application\\Automation\\"
      - "Kumwe\\App\\Automation\\Domain\\"
      - "Kumwe\\App\\BusinessIntegration\\Domain\\"
    capability_index_sha256: null
  semantic_inputs: []
  examined_dependencies:
    - "php ^8.5"
    - "kumwe/canonical-json 0.1.1"
    - "kumwe/contribution 0.1.1"
    - "kumwe/access-context 0.1.1"
    - "psr/clock ^1.0"
    - "psr/container ^2.0"
  active_related_pull_requests: []
framework_php:
  composer_package: "kumwe/automation"
  canonical_namespace: "Kumwe\\Automation"
  public_api_manifest: "resources/public-api/v1.json"
  capability_manifest: "resources/capabilities/v1.json"
  service_map: "resources/service-map/v1.json"
  extracted_symbols:
    -
      old_fqcn: "Kumwe\\App\\Automation\\Domain\\CronExpression"
      new_fqcn: "Kumwe\\Automation\\CronExpression"
      source_path: "src/Automation/Domain/CronExpression.php"
      target_path: "src/CronExpression.php"
      kind: "class"
      public_methods:
        - "__construct"
        - "__toString"
        - "next"
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\RetryPolicy"
      new_fqcn: "Kumwe\\Automation\\RetryPolicy"
      source_path: "src/Application/Automation/RetryPolicy.php"
      target_path: "src/RetryPolicy.php"
      kind: "class"
      public_methods:
        - "__construct"
        - "classify"
        - "decide"
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\RetryDecision"
      new_fqcn: "Kumwe\\Automation\\RetryDecision"
      source_path: "src/Application/Automation/RetryDecision.php"
      target_path: "src/RetryDecision.php"
      kind: "class"
      public_methods:
        - "__construct"
      public_properties:
        - "classification"
        - "delaySeconds"
        - "retryAt"
        - "shouldRetry"
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\JitterSource"
      new_fqcn: "Kumwe\\Automation\\JitterSource"
      source_path: "src/Application/Automation/JitterSource.php"
      target_path: "src/JitterSource.php"
      kind: "interface"
      public_methods:
        - "between"
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\CryptographicJitterSource"
      new_fqcn: "Kumwe\\Automation\\CryptographicJitterSource"
      source_path: "src/Application/Automation/CryptographicJitterSource.php"
      target_path: "src/CryptographicJitterSource.php"
      kind: "class"
      public_methods:
        - "between"
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\FailureClassification"
      new_fqcn: "Kumwe\\Automation\\FailureClassification"
      source_path: "src/Application/Automation/FailureClassification.php"
      target_path: "src/FailureClassification.php"
      kind: "enum"
      public_methods:
        - "cases"
        - "from"
        - "tryFrom"
      public_properties:
        - "name"
        - "value"
      public_constants:
        - "PERMANENT"
        - "TRANSIENT"
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\PermanentFailure"
      new_fqcn: "Kumwe\\Automation\\PermanentFailure"
      source_path: "src/Application/Automation/PermanentFailure.php"
      target_path: "src/PermanentFailure.php"
      kind: "class"
      public_methods: []
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\TransientFailure"
      new_fqcn: "Kumwe\\Automation\\TransientFailure"
      source_path: "src/Application/Automation/TransientFailure.php"
      target_path: "src/TransientFailure.php"
      kind: "interface"
      public_methods: []
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\JobEnvelope"
      new_fqcn: "Kumwe\\Automation\\JobEnvelope"
      source_path: "src/Application/Automation/JobEnvelope.php"
      target_path: "src/JobEnvelope.php"
      kind: "class"
      public_methods:
        - "attempts"
        - "availableAt"
        - "claim"
        - "complete"
        - "createdAt"
        - "id"
        - "isClaimableAt"
        - "lease"
        - "maximumAttempts"
        - "payload"
        - "pending"
        - "priority"
        - "queue"
        - "releaseExpiredLease"
        - "releaseForRetry"
        - "renewLease"
        - "schemaVersion"
        - "status"
        - "type"
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\JobLease"
      new_fqcn: "Kumwe\\Automation\\JobLease"
      source_path: "src/Application/Automation/JobLease.php"
      target_path: "src/JobLease.php"
      kind: "class"
      public_methods:
        - "__construct"
        - "acquiredAt"
        - "assertActiveOwner"
        - "expiresAt"
        - "isExpiredAt"
        - "owner"
        - "renew"
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\JobStatus"
      new_fqcn: "Kumwe\\Automation\\JobStatus"
      source_path: "src/Application/Automation/JobStatus.php"
      target_path: "src/JobStatus.php"
      kind: "enum"
      public_methods:
        - "cases"
        - "from"
        - "tryFrom"
      public_properties:
        - "name"
        - "value"
      public_constants:
        - "CANCELED"
        - "COMPLETED"
        - "DEAD"
        - "PENDING"
        - "RESERVED"
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\StoredJob"
      new_fqcn: "Kumwe\\Automation\\StoredJob"
      source_path: "src/Application/Automation/StoredJob.php"
      target_path: "src/StoredJob.php"
      kind: "class"
      public_methods:
        - "__construct"
      public_properties:
        - "attempts"
        - "executionClass"
        - "id"
        - "leaseToken"
        - "maximumAttempts"
        - "payload"
        - "queue"
        - "schemaVersion"
        - "type"
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\JobExecutionClass"
      new_fqcn: "Kumwe\\Automation\\JobExecutionClass"
      source_path: "src/Application/Automation/JobExecutionClass.php"
      target_path: "src/JobExecutionClass.php"
      kind: "enum"
      public_methods:
        - "cases"
        - "from"
        - "tryFrom"
      public_properties:
        - "name"
        - "value"
      public_constants:
        - "Installation"
        - "Site"
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\JobQueue"
      new_fqcn: "Kumwe\\Automation\\JobQueue"
      source_path: "src/Application/Automation/JobQueue.php"
      target_path: "src/JobQueue.php"
      kind: "interface"
      public_methods:
        - "all"
        - "cancel"
        - "claim"
        - "complete"
        - "disconnect"
        - "enqueue"
        - "fail"
        - "heartbeat"
        - "renew"
        - "retry"
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\JobHandler"
      new_fqcn: "Kumwe\\Automation\\JobHandler"
      source_path: "src/Application/Automation/JobHandler.php"
      target_path: "src/JobHandler.php"
      kind: "interface"
      public_methods:
        - "handle"
        - "type"
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\JobHandlerRegistry"
      new_fqcn: "Kumwe\\Automation\\JobHandlerRegistry"
      source_path: "src/Application/Automation/JobHandlerRegistry.php"
      target_path: "src/JobHandlerRegistry.php"
      kind: "class"
      public_methods:
        - "__construct"
        - "find"
        - "types"
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\QueueRuntimePolicy"
      new_fqcn: "Kumwe\\Automation\\QueueRuntimePolicy"
      source_path: "src/Application/Automation/QueueRuntimePolicy.php"
      target_path: "src/QueueRuntimePolicy.php"
      kind: "class"
      public_methods:
        - "__construct"
        - "toArray"
      public_properties:
        - "leaseSeconds"
        - "maximumAttempts"
        - "maximumInFlight"
        - "queue"
        - "retentionDays"
        - "runtimeGeneration"
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\QueueRuntimePolicyCatalog"
      new_fqcn: "Kumwe\\Automation\\QueueRuntimePolicyCatalog"
      source_path: "src/Application/Automation/QueueRuntimePolicyCatalog.php"
      target_path: "src/QueueRuntimePolicyCatalog.php"
      kind: "interface"
      public_methods:
        - "maximumAttempts"
        - "policies"
        - "policy"
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\ExpiredJobLease"
      new_fqcn: "Kumwe\\Automation\\ExpiredJobLease"
      source_path: "src/Application/Automation/ExpiredJobLease.php"
      target_path: "src/ExpiredJobLease.php"
      kind: "class"
      public_methods: []
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\Application\\Automation\\AutomationNotFound"
      new_fqcn: "Kumwe\\Automation\\AutomationNotFound"
      source_path: "src/Application/Automation/AutomationNotFound.php"
      target_path: "src/AutomationNotFound.php"
      kind: "class"
      public_methods: []
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\BusinessIntegration\\Domain\\QueueContributionDefinition"
      new_fqcn: "Kumwe\\Automation\\QueueContributionDefinition"
      source_path: "src/BusinessIntegration/Domain/QueueContributionDefinition.php"
      target_path: "src/QueueContributionDefinition.php"
      kind: "class"
      public_methods:
        - "__construct"
        - "fromArray"
        - "identifier"
        - "leaseSeconds"
        - "maximumAttempts"
        - "maximumInFlight"
        - "retentionDays"
        - "toArray"
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\App\\BusinessIntegration\\Domain\\ScheduleContributionDefinition"
      new_fqcn: "Kumwe\\Automation\\ScheduleContributionDefinition"
      source_path: "src/BusinessIntegration/Domain/ScheduleContributionDefinition.php"
      target_path: "src/ScheduleContributionDefinition.php"
      kind: "class"
      public_methods:
        - "__construct"
        - "cronExpression"
        - "enabled"
        - "fromArray"
        - "identifier"
        - "jobType"
        - "payload"
        - "queue"
        - "siteIdentifier"
        - "timezone"
        - "toArray"
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\Extension\\Spi\\BusinessIntegration\\Domain\\JobContributionDefinition"
      new_fqcn: "Kumwe\\Automation\\JobContributionDefinition"
      source_path: "src/Spi/BusinessIntegration/Domain/JobContributionDefinition.php"
      target_path: "src/JobContributionDefinition.php"
      kind: "class"
      public_methods:
        - "__construct"
        - "fromArray"
        - "handlerVersion"
        - "identifier"
        - "installationWide"
        - "maximumAttempts"
        - "payloadSchema"
        - "queue"
        - "schemaVersion"
        - "toArray"
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
    -
      old_fqcn: "Kumwe\\Extension\\Spi\\Application\\Automation\\JobDeclaration"
      new_fqcn: "Kumwe\\Automation\\JobDeclaration"
      source_path: "src/Spi/Application/Automation/JobDeclaration.php"
      target_path: "src/JobDeclaration.php"
      kind: "class"
      public_methods:
        - "fromManifest"
        - "schemaVersion"
        - "toArray"
        - "type"
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: "Explicit scalar projections documented in docs/public-api.md; PHP native serialization is not a durable wire or authority contract."
      compatibility: "Portable behavior is retained under the canonical namespace. Package-owned boundary and regression tests document deliberate validation and scheduling corrections."
  consumers:
    app_code:
      - "src/Application/Automation/Job/EnforceAuditRetentionHandler.php"
      - "src/Application/Automation/Job/PurgeAdministratorSessionsHandler.php"
      - "src/Application/Automation/Job/PurgeBusinessRecordIdempotencyHandler.php"
      - "src/Application/Automation/Job/PurgeIdempotencyRecordsHandler.php"
      - "src/Application/Automation/Job/PurgeStudioContentAuthoringContextsHandler.php"
      - "src/Application/Automation/Job/RebuildExtensionMapHandler.php"
      - "src/Application/Automation/Job/RecordAuditAnchorHandler.php"
      - "src/Application/Automation/Job/RotateRecordSecretsHandler.php"
      - "src/Application/Automation/Job/SynchronizeTrustRevocationsHandler.php"
      - "src/Application/Automation/Job/TransitionContentHandler.php"
      - "src/Application/Automation/Job/VerifyAuditTrailHandler.php"
      - "src/BusinessIntegration/Application/DurableOutboundAdapterDispatcher.php"
      - "src/BusinessIntegration/Application/InboxStore.php"
      - "src/BusinessIntegration/Application/IntegrationEventConsumerDispatcher.php"
      - "src/BusinessIntegration/Application/JobQueueIntegrationEventHandler.php"
      - "src/BusinessIntegration/Application/JobQueueProcessWorkHandler.php"
      - "src/BusinessIntegration/Application/OutboxDispatcher.php"
      - "src/BusinessIntegration/Application/OutboxStore.php"
      - "src/BusinessIntegration/Application/ProcessManagerStore.php"
      - "src/BusinessIntegration/Application/ProcessWorkDispatcher.php"
      - "src/BusinessIntegration/Application/ValidatedContributedJobHandler.php"
      - "src/BusinessIntegration/Domain/ScheduleContributionDefinition.php"
      - "src/BusinessIntegration/Infrastructure/ContributedQueueRuntimePolicyCatalog.php"
      - "src/BusinessIntegration/Infrastructure/ContributedScheduleSynchronizer.php"
      - "src/BusinessIntegration/Infrastructure/DoctrineInboxStore.php"
      - "src/BusinessIntegration/Infrastructure/DoctrineOutboxStore.php"
      - "src/BusinessIntegration/Infrastructure/DoctrineProcessManagerStore.php"
      - "src/BusinessIntegration/Infrastructure/RuntimeIntegrationEventTransport.php"
      - "src/BusinessReporting/Application/GenerateReportExportHandler.php"
      - "src/BusinessReporting/Infrastructure/JobQueueExportJobDispatcher.php"
      - "src/Delivery/Console/Command/QueueWorkCommand.php"
      - "src/Delivery/Http/Api/Automation/AutomationApiHandler.php"
      - "src/Extension/Contribution/CanonicalManifestInterpreter.php"
      - "src/Infrastructure/Automation/DoctrineJobQueue.php"
      - "src/Infrastructure/Automation/DoctrineQueueRuntimeOperations.php"
      - "src/Infrastructure/Automation/DoctrineScheduler.php"
      - "src/Infrastructure/Mcp/McpToolErrorVocabulary.php"
      - "src/Infrastructure/Persistence/Migration/AuditTamperEvidenceMigration.php"
      - "src/Infrastructure/Persistence/Migration/BusinessRecordIdempotencyRetentionMigration.php"
      - "src/Infrastructure/Persistence/Migration/InstallationGlobalAutomationMigration.php"
      - "src/Infrastructure/Persistence/Migration/StudioContentAuthoringContextRetentionMigration.php"
      - "src/Kernel/ContainerFactory.php"
    configuration_and_di: []
    reflection_and_string_references:
      - "Recompute same-namespace, reflected and dynamically constructed names before App adoption; exact source inventory is evidence, not a complete dynamic reference proof."
    fixtures_and_examples:
      - "examples/consumer.php"
    external:
      - "src/Manifest/ManifestContributionGraphValidator.php"
      - "src/Manifest/ManifestContributions.php"
      - "src/Spi/Application/Automation/JobHandler.php"
  dependency_injection:
    mode: "config-provider"
    provider: "Kumwe\\Automation\\ConfigProvider"
    factories:
      - "Kumwe\\Automation\\Container\\CryptographicJitterSourceFactory"
      - "Kumwe\\Automation\\Container\\RetryPolicyFactory"
      - "Kumwe\\Automation\\Container\\JobHandlerRegistryFactory"
    aliases:
      - "Kumwe\\Automation\\JitterSource -> Kumwe\\Automation\\CryptographicJitterSource"
    service_lifetimes:
      - "Kumwe\\Automation\\CryptographicJitterSource: shared"
      - "Kumwe\\Automation\\RetryPolicy: shared"
      - "Kumwe\\Automation\\JobHandlerRegistry: shared"
    configuration_keys:
      - "kumwe.automation.base_delay_seconds"
      - "kumwe.automation.maximum_delay_seconds"
      - "kumwe.automation.handlers"
    provider_absence_reason: null
ownership:
  responsibility: "Portable schedules, jittered retry policy, run requests and explicit job registration."
  non_responsibilities:
    - "Host trust and final authorization"
    - "Persistence, durable transactions, worker and transport lifecycle"
    - "App runtime adoption and native release publication"
  allowed_dependency_ceiling:
    - "php"
    - "kumwe/canonical-json"
    - "kumwe/contribution"
    - "kumwe/access-context"
    - "psr/clock"
    - "psr/container"
  implementation_owner: "kumwe/automation"
  next_consumer: "kumwe/app"
  public_manifests:
    -
      path: "resources/public-api/v1.json"
      sha256: "5528055c23afaea3711de77e0e66f84b0e122df520542fb61c8b8fd9f0c8cf53"
    -
      path: "resources/capabilities/v1.json"
      sha256: "4cebd820f960ccdaf3b988231e54555d75291861a5d3bfbb8ba0dcefe7694e1a"
    -
      path: "resources/service-map/v1.json"
      sha256: "4bacfb7a2eb2b2dc4046b85826bf53bda5c4ef24a23c5b9b69c2ad0bf62b05c6"
  intentionally_excluded:
    - "Cron, retry, handler dispatch and job declarations are implemented and tested here. The App retains durable queues, scheduler claims, transactions, tenant authority, extension admission and worker orchestration."
native_cpp: null
php_extension: null
tests:
  moved_or_added:
    - "tests/bootstrap.php"
    - "tests/container.php"
    - "tests/run.php"
  remain_in_app_or_consumer:
    - "tests/Architecture/TransactionSeamBoundaryTest.php"
    - "tests/Functional/Extension/LiveSurfaceContractParityTest.php"
    - "tests/Integration/Automation/AutomationManagementIntegrationTest.php"
    - "tests/Integration/Automation/DatabaseLossRecoveryIntegrationTest.php"
    - "tests/Integration/Automation/KilledWorkerRecoveryIntegrationTest.php"
    - "tests/Integration/Automation/SchedulerOccurrenceContentionIntegrationTest.php"
    - "tests/Integration/Automation/WorkerConnectionLossKillPointIntegrationTest.php"
    - "tests/Integration/BusinessIntegration/BusinessIntegrationPersistenceTest.php"
    - "tests/Integration/BusinessIntegration/HungEndpointDeadlineIntegrationTest.php"
    - "tests/Integration/BusinessIntegration/PoisonAndDeadLetterIntegrationTest.php"
    - "tests/Integration/Extension/GeneratedExtensionLifecycleIntegrationTest.php"
    - "tests/Integration/Extension/ManifestGenerationLifecycleIntegrationTest.php"
    - "tests/Integration/Persistence/MigrationIntegrationTest.php"
    - "tests/Support/DrillDirectedJobHandler.php"
    - "tests/Support/killable-worker.php"
    - "tests/Unit/Application/Authorization/AdapterAuthorizationParityTest.php"
    - "tests/Unit/Application/Automation/AutomationManagementServiceTest.php"
    - "tests/Unit/Application/Automation/CronExpressionTest.php"
    - "tests/Unit/Application/Automation/JobEnvelopeTest.php"
    - "tests/Unit/Application/Automation/JobLeaseTest.php"
    - "tests/Unit/Application/Automation/RetryPolicyTest.php"
    - "tests/Unit/Application/Automation/WorkerTest.php"
    - "tests/Unit/BusinessIntegration/Application/DurableOutboundAdapterDeliveryTest.php"
    - "tests/Unit/BusinessIntegration/Application/JobQueueIntegrationEventHandlerTest.php"
    - "tests/Unit/BusinessIntegration/Application/ValidatedContributedJobHandlerTest.php"
    - "tests/Unit/BusinessIntegration/ConsumerDispatcherTest.php"
    - "tests/Unit/BusinessIntegration/Domain/IntegrationContributionDefinitionTest.php"
    - "tests/Unit/BusinessIntegration/DurableOutboundAdapterDispatcherTest.php"
    - "tests/Unit/BusinessIntegration/Infrastructure/RuntimeIntegrationEventTransportTest.php"
    - "tests/Unit/BusinessIntegration/OutboxDispatcherTest.php"
    - "tests/Unit/BusinessIntegration/ProcessWorkDispatcherTest.php"
    - "tests/Unit/BusinessIntegration/QueueRuntimePolicyTest.php"
    - "tests/Unit/Delivery/Console/Command/QueueWorkCommandTest.php"
    - "tests/Unit/Delivery/Http/Api/Automation/AutomationApiHandlerTest.php"
    - "tests/Unit/Extension/Contribution/ExtensionBindingSurfaceTest.php"
    - "tests/Unit/Extension/Contribution/OwnedBindingCanonicalDriftTest.php"
    - "tests/Unit/Extension/Runtime/TrustEnforcingJobHandlerTest.php"
  split_tests:
    - "Remove only library implementation assertions after verified App adoption; retain host wiring and composed behavior assertions."
  prohibited_duplicates:
    - "App must not retain unit tests of vendor-owned implementation internals after adoption."
  corpora: []
documentation:
  charter: "CHARTER.md"
  readme: "README.md"
  public_api: "docs/public-api.md"
  architecture: "docs/architecture.md"
  integration_or_consumer: "docs/integration.md"
  examples:
    - "examples/consumer.php"
  changelog_record: "CHANGELOG.md ## 0.2.2"
release_expectations:
  version_policy: "Exact stable sibling package pins; preserve coherent released graphs until compatible successor releases exist."
  expected_artifact_types:
    - "Composer package archive"
    - "GitHub source archive"
  required_checks:
    - "composer check"
    - "Final hosted package CI"
    - "Machine handoff and consumer schema validation"
  required_registry_or_installer: "Composer"
  required_external_attestation: true
next_task:
  phase_name: "Review and verify the library successor release before separate App integration"
  permitted_only_when:
    - "Final package CI passes at the proposed head"
    - "Immutable package and all dependency releases are independently verified"
    - "Reconcile current App drift against the recorded source inventories"
  consumer_repository: "https://github.com/kumwe/app"
  dependency_or_native_change: "Install the exact independently verified successor; run Composer resolution, archive consumer gates and affected App integration tests before namespace removal."
  namespace_or_api_replacements:
    - "Kumwe\\App\\Automation\\Domain\\CronExpression -> Kumwe\\Automation\\CronExpression"
    - "Kumwe\\App\\Application\\Automation\\RetryPolicy -> Kumwe\\Automation\\RetryPolicy"
    - "Kumwe\\App\\Application\\Automation\\RetryDecision -> Kumwe\\Automation\\RetryDecision"
    - "Kumwe\\App\\Application\\Automation\\JitterSource -> Kumwe\\Automation\\JitterSource"
    - "Kumwe\\App\\Application\\Automation\\CryptographicJitterSource -> Kumwe\\Automation\\CryptographicJitterSource"
    - "Kumwe\\App\\Application\\Automation\\FailureClassification -> Kumwe\\Automation\\FailureClassification"
    - "Kumwe\\App\\Application\\Automation\\PermanentFailure -> Kumwe\\Automation\\PermanentFailure"
    - "Kumwe\\App\\Application\\Automation\\TransientFailure -> Kumwe\\Automation\\TransientFailure"
    - "Kumwe\\App\\Application\\Automation\\JobEnvelope -> Kumwe\\Automation\\JobEnvelope"
    - "Kumwe\\App\\Application\\Automation\\JobLease -> Kumwe\\Automation\\JobLease"
    - "Kumwe\\App\\Application\\Automation\\JobStatus -> Kumwe\\Automation\\JobStatus"
    - "Kumwe\\App\\Application\\Automation\\StoredJob -> Kumwe\\Automation\\StoredJob"
    - "Kumwe\\App\\Application\\Automation\\JobExecutionClass -> Kumwe\\Automation\\JobExecutionClass"
    - "Kumwe\\App\\Application\\Automation\\JobQueue -> Kumwe\\Automation\\JobQueue"
    - "Kumwe\\App\\Application\\Automation\\JobHandler -> Kumwe\\Automation\\JobHandler"
    - "Kumwe\\App\\Application\\Automation\\JobHandlerRegistry -> Kumwe\\Automation\\JobHandlerRegistry"
    - "Kumwe\\App\\Application\\Automation\\QueueRuntimePolicy -> Kumwe\\Automation\\QueueRuntimePolicy"
    - "Kumwe\\App\\Application\\Automation\\QueueRuntimePolicyCatalog -> Kumwe\\Automation\\QueueRuntimePolicyCatalog"
    - "Kumwe\\App\\Application\\Automation\\ExpiredJobLease -> Kumwe\\Automation\\ExpiredJobLease"
    - "Kumwe\\App\\Application\\Automation\\AutomationNotFound -> Kumwe\\Automation\\AutomationNotFound"
    - "Kumwe\\App\\BusinessIntegration\\Domain\\QueueContributionDefinition -> Kumwe\\Automation\\QueueContributionDefinition"
    - "Kumwe\\App\\BusinessIntegration\\Domain\\ScheduleContributionDefinition -> Kumwe\\Automation\\ScheduleContributionDefinition"
    - "Kumwe\\Extension\\Spi\\BusinessIntegration\\Domain\\JobContributionDefinition -> Kumwe\\Automation\\JobContributionDefinition"
    - "Kumwe\\Extension\\Spi\\Application\\Automation\\JobDeclaration -> Kumwe\\Automation\\JobDeclaration"
  files_to_update:
    - "composer.json"
    - "composer.lock"
    - "src/Application/Automation/Job/EnforceAuditRetentionHandler.php"
    - "src/Application/Automation/Job/PurgeAdministratorSessionsHandler.php"
    - "src/Application/Automation/Job/PurgeBusinessRecordIdempotencyHandler.php"
    - "src/Application/Automation/Job/PurgeIdempotencyRecordsHandler.php"
    - "src/Application/Automation/Job/PurgeStudioContentAuthoringContextsHandler.php"
    - "src/Application/Automation/Job/RebuildExtensionMapHandler.php"
    - "src/Application/Automation/Job/RecordAuditAnchorHandler.php"
    - "src/Application/Automation/Job/RotateRecordSecretsHandler.php"
    - "src/Application/Automation/Job/SynchronizeTrustRevocationsHandler.php"
    - "src/Application/Automation/Job/TransitionContentHandler.php"
    - "src/Application/Automation/Job/VerifyAuditTrailHandler.php"
    - "src/BusinessIntegration/Application/DurableOutboundAdapterDispatcher.php"
    - "src/BusinessIntegration/Application/InboxStore.php"
    - "src/BusinessIntegration/Application/IntegrationEventConsumerDispatcher.php"
    - "src/BusinessIntegration/Application/JobQueueIntegrationEventHandler.php"
    - "src/BusinessIntegration/Application/JobQueueProcessWorkHandler.php"
    - "src/BusinessIntegration/Application/OutboxDispatcher.php"
    - "src/BusinessIntegration/Application/OutboxStore.php"
    - "src/BusinessIntegration/Application/ProcessManagerStore.php"
    - "src/BusinessIntegration/Application/ProcessWorkDispatcher.php"
    - "src/BusinessIntegration/Application/ValidatedContributedJobHandler.php"
    - "src/BusinessIntegration/Domain/ScheduleContributionDefinition.php"
    - "src/BusinessIntegration/Infrastructure/ContributedQueueRuntimePolicyCatalog.php"
    - "src/BusinessIntegration/Infrastructure/ContributedScheduleSynchronizer.php"
    - "src/BusinessIntegration/Infrastructure/DoctrineInboxStore.php"
    - "src/BusinessIntegration/Infrastructure/DoctrineOutboxStore.php"
    - "src/BusinessIntegration/Infrastructure/DoctrineProcessManagerStore.php"
    - "src/BusinessIntegration/Infrastructure/RuntimeIntegrationEventTransport.php"
    - "src/BusinessReporting/Application/GenerateReportExportHandler.php"
    - "src/BusinessReporting/Infrastructure/JobQueueExportJobDispatcher.php"
    - "src/Delivery/Console/Command/QueueWorkCommand.php"
    - "src/Delivery/Http/Api/Automation/AutomationApiHandler.php"
    - "src/Extension/Contribution/CanonicalManifestInterpreter.php"
    - "src/Infrastructure/Automation/DoctrineJobQueue.php"
    - "src/Infrastructure/Automation/DoctrineQueueRuntimeOperations.php"
    - "src/Infrastructure/Automation/DoctrineScheduler.php"
    - "src/Infrastructure/Mcp/McpToolErrorVocabulary.php"
    - "src/Infrastructure/Persistence/Migration/AuditTamperEvidenceMigration.php"
    - "src/Infrastructure/Persistence/Migration/BusinessRecordIdempotencyRetentionMigration.php"
    - "src/Infrastructure/Persistence/Migration/InstallationGlobalAutomationMigration.php"
    - "src/Infrastructure/Persistence/Migration/StudioContentAuthoringContextRetentionMigration.php"
    - "src/Kernel/ContainerFactory.php"
  files_to_remove:
    - "src/Application/Automation/AutomationNotFound.php"
    - "src/Application/Automation/CryptographicJitterSource.php"
    - "src/Application/Automation/ExpiredJobLease.php"
    - "src/Application/Automation/FailureClassification.php"
    - "src/Application/Automation/JitterSource.php"
    - "src/Application/Automation/JobEnvelope.php"
    - "src/Application/Automation/JobExecutionClass.php"
    - "src/Application/Automation/JobHandler.php"
    - "src/Application/Automation/JobHandlerRegistry.php"
    - "src/Application/Automation/JobLease.php"
    - "src/Application/Automation/JobQueue.php"
    - "src/Application/Automation/JobStatus.php"
    - "src/Application/Automation/PermanentFailure.php"
    - "src/Application/Automation/QueueRuntimePolicy.php"
    - "src/Application/Automation/QueueRuntimePolicyCatalog.php"
    - "src/Application/Automation/RetryDecision.php"
    - "src/Application/Automation/RetryPolicy.php"
    - "src/Application/Automation/StoredJob.php"
    - "src/Application/Automation/TransientFailure.php"
    - "src/Automation/Domain/CronExpression.php"
    - "src/BusinessIntegration/Domain/QueueContributionDefinition.php"
    - "src/BusinessIntegration/Domain/ScheduleContributionDefinition.php"
  tests_to_remove:
    - "Implementation-owned portions only, after the package behavior suite and App integration suite pass."
  tests_to_retain_or_add:
    - "tests/Architecture/TransactionSeamBoundaryTest.php"
    - "tests/Functional/Extension/LiveSurfaceContractParityTest.php"
    - "tests/Integration/Automation/AutomationManagementIntegrationTest.php"
    - "tests/Integration/Automation/DatabaseLossRecoveryIntegrationTest.php"
    - "tests/Integration/Automation/KilledWorkerRecoveryIntegrationTest.php"
    - "tests/Integration/Automation/SchedulerOccurrenceContentionIntegrationTest.php"
    - "tests/Integration/Automation/WorkerConnectionLossKillPointIntegrationTest.php"
    - "tests/Integration/BusinessIntegration/BusinessIntegrationPersistenceTest.php"
    - "tests/Integration/BusinessIntegration/HungEndpointDeadlineIntegrationTest.php"
    - "tests/Integration/BusinessIntegration/PoisonAndDeadLetterIntegrationTest.php"
    - "tests/Integration/Extension/GeneratedExtensionLifecycleIntegrationTest.php"
    - "tests/Integration/Extension/ManifestGenerationLifecycleIntegrationTest.php"
    - "tests/Integration/Persistence/MigrationIntegrationTest.php"
    - "tests/Support/DrillDirectedJobHandler.php"
    - "tests/Support/killable-worker.php"
    - "tests/Unit/Application/Authorization/AdapterAuthorizationParityTest.php"
    - "tests/Unit/Application/Automation/AutomationManagementServiceTest.php"
    - "tests/Unit/Application/Automation/CronExpressionTest.php"
    - "tests/Unit/Application/Automation/JobEnvelopeTest.php"
    - "tests/Unit/Application/Automation/JobLeaseTest.php"
    - "tests/Unit/Application/Automation/RetryPolicyTest.php"
    - "tests/Unit/Application/Automation/WorkerTest.php"
    - "tests/Unit/BusinessIntegration/Application/DurableOutboundAdapterDeliveryTest.php"
    - "tests/Unit/BusinessIntegration/Application/JobQueueIntegrationEventHandlerTest.php"
    - "tests/Unit/BusinessIntegration/Application/ValidatedContributedJobHandlerTest.php"
    - "tests/Unit/BusinessIntegration/ConsumerDispatcherTest.php"
    - "tests/Unit/BusinessIntegration/Domain/IntegrationContributionDefinitionTest.php"
    - "tests/Unit/BusinessIntegration/DurableOutboundAdapterDispatcherTest.php"
    - "tests/Unit/BusinessIntegration/Infrastructure/RuntimeIntegrationEventTransportTest.php"
    - "tests/Unit/BusinessIntegration/OutboxDispatcherTest.php"
    - "tests/Unit/BusinessIntegration/ProcessWorkDispatcherTest.php"
    - "tests/Unit/BusinessIntegration/QueueRuntimePolicyTest.php"
    - "tests/Unit/Delivery/Console/Command/QueueWorkCommandTest.php"
    - "tests/Unit/Delivery/Http/Api/Automation/AutomationApiHandlerTest.php"
    - "tests/Unit/Extension/Contribution/ExtensionBindingSurfaceTest.php"
    - "tests/Unit/Extension/Contribution/OwnedBindingCanonicalDriftTest.php"
    - "tests/Unit/Extension/Runtime/TrustEnforcingJobHandlerTest.php"
  di_or_provisioning_changes:
    - "Register Kumwe\\Automation\\ConfigProvider and provide all explicit host ports documented in docs/integration.md."
  capability_index_changes:
    - "Record ownership from the verified package capability and public API manifests."
  changelog_and_evidence_changes:
    - "Record exact source, package archive and dependency identities in the external release attestation and App integration ledger."
  verification_commands:
    - "composer check"
    - "Affected App integration suites"
    - "Complete App package governance gate"
concurrency:
  likely_conflict_files:
    - "App composer.json"
    - "App composer.lock"
    - "App provider configuration"
  related_migrations: []
  ownership_conflicts: []
  integration_train: null
  resolution_rule: "semantic-preservation"
governance:
  roadmap_source_sha256: "a202155ef1a65f5ab293d4f8397ebf4ac430db7f1e877c776bbe7851e6fe18d8"
  roadmap_refs: []
  non_roadmap_refs:
    - "NRM-2026-026"
  completion_claim: false
decisions:
  - "Fix DST repeated-hour scheduling and odd retry ceilings, bound cron expressions and expose explicit PSR-11 composition."
  - "Cron, retry, handler dispatch and job declarations are implemented and tested here. The App retains durable queues, scheduler claims, transactions, tenant authority, extension admission and worker orchestration."
  - "Library behavior tests are package-owned. App changes, releases and external attestations are separate tasks."
blockers:
  - "Independent successor release verification and the final package gate remain necessary before App adoption."
---

# automation implementation handoff

## Migration/implementation summary

Repair the installed example bootstrap so a host-provided Composer autoloader works without a nested package vendor directory. The proposed 0.2.2 successor preserves the runtime API and exact dependency pins from published 0.2.1. The changelog version describes the proposed artifact; it is not a publication observation.

## Public API and responsibility

Cron, retry, handler dispatch and job declarations are implemented and tested here. The App retains durable queues, scheduler claims, transactions, tenant authority, extension admission and worker orchestration. Every exported member is recorded in resources/public-api/v1.json and documented in docs/public-api.md. The current surface contains 28 types. 24 types have recorded extraction provenance; package-native composition is identified separately.

## Capability reuse/semantic input review

The implementation consumes the exact canonical dependency contracts recorded in composer.json. Install the exact independently verified successor; run Composer resolution, archive consumer gates and affected App integration tests before namespace removal.

## Consumer inventory

The machine record lists actual source mappings, known consumer paths and concrete namespace replacements. resources/migration/consumer-inventory.json and resources/migration/source-map.json retain source digests where present. Dynamic references and same-namespace names must be searched again during adoption; the inventory does not imply that App has already switched ownership.

## Test ownership

The package archive gate executes the original installed example in fresh PHP processes with explicit and preloaded host autoloaders. It also checks missing explicit paths both before and after preload. These bootstrap regressions belong to the package distribution gate.

Package tests own portable values, validation, service behavior, explicit construction and malformed-input regressions. The machine record identifies the source suites to split. Host persistence, transactions, authority, transport and operational integration stay in App. After verified adoption, remove duplicate library implementation assertions from App together with their legacy source.

## Next-task execution notes

The selected runtime dependencies are kumwe/canonical-json 0.1.1, kumwe/contribution 0.1.1, kumwe/access-context 0.1.2.
Access Context 0.1.2 was observed at source 132c3cd7c229ceda4398e19140d1477512c27ebf.
Run the package dependency-readiness gate before selecting the coordinated consumer graph.
Independent release attestations remain external and are not inferred from these version pins.

Independent successor release verification and the final package gate remain necessary before App adoption. Install the exact independently verified successor; run Composer resolution, archive consumer gates and affected App integration tests before namespace removal. Run final source and clean archive gates before admitting the package; then update the App dependency lock, replace namespaces, retain host adapters and remove only the inventoried portable legacy implementations.

## Drift check

Reconcile the recorded source commit and per-file source digests with the current App before adoption. Recompute all public manifest hashes together. Keep actual release observations and final tested commit identities outside the tested source tree to avoid self-referential evidence.

## Validation recipe and observed local results

Run composer check with the documented PHP runtime and extensions. The review added and exercised the boundary regressions described in CHANGELOG.md. A final gate pass, remote CI status and immutable release verification are distinct observations; neither a proposed version nor this handoff attests publication. See docs/integration.md and the package check scripts for the exact archive and runtime recipe.

