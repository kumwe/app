<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel;

use Doctrine\DBAL\Connection;
use Joomla\DI\Container;
use Joomla\Event\Dispatcher;
use Joomla\Event\DispatcherInterface;
use Kumwe\CMS\Application\Automation\AutomationManagementService;
use Kumwe\CMS\Application\Automation\CryptographicJitterSource;
use Kumwe\CMS\Infrastructure\Automation\DoctrineJobQueue;
use Kumwe\CMS\Infrastructure\Automation\DoctrineQueueRuntimeOperations;
use Kumwe\CMS\Infrastructure\Automation\DoctrineScheduler;
use Kumwe\CMS\Application\Automation\Job\EnforceAuditRetentionHandler;
use Kumwe\CMS\Application\Automation\Job\PurgeAdministratorSessionsHandler;
use Kumwe\CMS\Application\Automation\Job\PurgeBusinessRecordIdempotencyHandler;
use Kumwe\CMS\Application\Automation\Job\PurgeIdempotencyRecordsHandler;
use Kumwe\CMS\Application\Automation\Job\RecordAuditAnchorHandler;
use Kumwe\CMS\Application\Automation\Job\RotateRecordSecretsHandler;
use Kumwe\CMS\Application\Automation\Job\RebuildExtensionMapHandler;
use Kumwe\CMS\Application\Automation\Job\SynchronizeTrustRevocationsHandler;
use Kumwe\CMS\Application\Automation\Job\VerifyAuditTrailHandler;
use Kumwe\CMS\Application\Automation\Job\ScheduleRepository;
use Kumwe\CMS\Application\Automation\Job\TransitionContentHandler;
use Kumwe\CMS\Application\Automation\JobHandlerRegistry;
use Kumwe\CMS\Application\Automation\JobHandler;
use Kumwe\CMS\Application\Automation\GlobalJobPrincipals;
use Kumwe\CMS\Application\Automation\JobExecutionScope;
use Kumwe\CMS\Application\Automation\JobQueue;
use Kumwe\CMS\Application\Automation\QueueRuntimeOperations;
use Kumwe\CMS\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\CMS\Application\Automation\JitterSource;
use Kumwe\CMS\Application\Automation\RetryPolicy;
use Kumwe\CMS\Application\Automation\IdempotencyPurger;
use Kumwe\CMS\Application\Idempotency\IdempotencyLedger;
use Kumwe\CMS\Application\Idempotency\SecretOnceIdempotencyLedger;
use Kumwe\CMS\Application\Automation\Scheduler;
use Kumwe\CMS\Application\Automation\Worker;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\CMS\Application\Authorization\CompositeResourceOwnershipReferences;
use Kumwe\CMS\Application\Authorization\DenyByDefaultAuthorizationGateway;
use Kumwe\CMS\Application\Authorization\MembershipContextValidator;
use Kumwe\CMS\Application\Authorization\ResourceOwnershipReferences;
use Kumwe\CMS\Application\Authorization\ResourceOwnershipScopePolicy;
use Kumwe\CMS\Application\Authorization\ResourceOwnershipScopeService;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnership;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SiteGroupAdministration;
use Kumwe\CMS\Application\Authorization\SiteGroupRegistry;
use Kumwe\CMS\Application\Authorization\SiteGroupWriter;
use Kumwe\CMS\Application\Authorization\StructuredLogAuthorizationDecisionRecorder;
use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\Application\Authorization\SystemPrincipal;
use Kumwe\CMS\Application\Persistence\TransactionManager;
use Kumwe\CMS\Application\Presentation\Preference\PresentationAccessGroupRepository;
use Kumwe\CMS\Application\Security\HighImpactCredentialGuard;
use Kumwe\CMS\Application\Operations\ExpiredMigrationLockRecovery;
use Kumwe\CMS\Application\Operations\MigrationLockRecoveryService;
use Kumwe\CMS\Administrator\Content\ContentFormDataMapper;
use Kumwe\CMS\Administrator\Content\ContentFormPresenter;
use Kumwe\CMS\Administrator\Content\ContentModelFormMapper;
use Kumwe\CMS\Administrator\Content\ContentModelFormPresenter;
use Kumwe\CMS\Administrator\Automation\AutomationJobFormRegistry;
use Kumwe\CMS\Administrator\Automation\ContributedJobFormCompiler;
use Kumwe\CMS\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorContentEditorHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorContentListHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorContentModelsHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorAccessControlHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorBusinessSecurityHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorAutomationHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorCreateContentHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorDashboardHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorDashboardPreferencesHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorExtensionActionHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorExtensionsHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorInterfaceStandardHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorLoginHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorLogoutHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorMediaHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorNavigationHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorRestoreContentHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorSettingsHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorTransitionContentHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorTrashContentHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorWordingHandler;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorUpdateContentHandler;
use Kumwe\CMS\Administrator\Http\Middleware\AdministratorCsrfMiddleware;
use Kumwe\CMS\Administrator\Http\Middleware\AdministratorAuthorizationMiddleware;
use Kumwe\CMS\Administrator\Http\Middleware\AdministratorSessionMiddleware;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\CMS\Administrator\Presentation\SitePresentationFormMapper;
use Kumwe\CMS\Audit\Application\AuditAnchorWriter;
use Kumwe\CMS\Audit\Application\AuditArchiveStorage;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Application\AuditRetentionService;
use Kumwe\CMS\Audit\Application\AuditTrailExporter;
use Kumwe\CMS\Audit\Application\AuditTrailVerifier;
use Kumwe\CMS\Audit\Infrastructure\Persistence\DoctrineAuditAnchorWriter;
use Kumwe\CMS\Audit\Infrastructure\Persistence\DoctrineAuditRecorder;
use Kumwe\CMS\Audit\Infrastructure\Persistence\DoctrineAuditRetentionService;
use Kumwe\CMS\Audit\Infrastructure\Persistence\DoctrineAuditTrailExporter;
use Kumwe\CMS\Audit\Infrastructure\Persistence\DoctrineAuditTrailVerifier;
use Kumwe\CMS\Audit\Infrastructure\Storage\FilesystemAuditArchiveStorage;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionCompatibilityAnalyzer;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionContractAdmission;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\BusinessDefinition\Application\PackageDefinitionSynchronizer;
use Kumwe\CMS\BusinessDefinition\Administrator\BusinessDefinitionFormMapper;
use Kumwe\CMS\BusinessDefinition\Delivery\Api\BusinessDefinitionApiHandler;
use Kumwe\CMS\BusinessDefinition\Delivery\Api\BusinessDefinitionApiPresenter;
use Kumwe\CMS\BusinessDefinition\Delivery\Administrator\BusinessDefinitionsHandler;
use Kumwe\CMS\BusinessDefinition\Infrastructure\Persistence\DoctrineBusinessDefinitionRepository;
use Kumwe\CMS\BusinessDefinition\Infrastructure\Persistence\DoctrinePackageDefinitionSynchronizer;
use Kumwe\CMS\BusinessDefinition\Infrastructure\Persistence\DoctrinePersistedFieldTypeDefinitionResolver;
use Kumwe\CMS\BusinessRecord\Application\BusinessNumberSequenceAllocator;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordIdempotencyPurger;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordIdempotencyRepository;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordReadRepository;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordRevisionRepository;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordService;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordWriteRepository;
use Kumwe\CMS\BusinessRecord\Application\InstalledBusinessRecordDefinitionResolver;
use Kumwe\CMS\BusinessRecord\Application\MoneyConversionPipeline;
use Kumwe\CMS\BusinessRecord\Application\MoneyRateProviderCatalog;
use Kumwe\CMS\BusinessRecord\Application\PostingPeriodCalendar;
use Kumwe\CMS\BusinessRecord\Application\PostingPeriodLock;
use Kumwe\CMS\BusinessRecord\Application\PostingPeriodRepository;
use Kumwe\CMS\BusinessRecord\Application\PostingPeriodService;
use Kumwe\CMS\BusinessRecord\Domain\MoneyConverter;
use Kumwe\CMS\BusinessRecord\Application\UnitConversionPipeline;
use Kumwe\CMS\BusinessRecord\Application\UnitConversionProviderCatalog;
use Kumwe\CMS\BusinessRecord\Domain\QuantityConverter;
use Kumwe\CMS\BusinessRecord\Infrastructure\RuntimeMoneyRateProviderCatalog;
use Kumwe\CMS\BusinessRecord\Infrastructure\RuntimeUnitConversionProviderCatalog;
use Kumwe\CMS\BusinessRecord\Application\RecordCursorCodec;
use Kumwe\CMS\BusinessRecord\Application\RecordFingerprint;
use Kumwe\CMS\BusinessRecord\Application\RecordRuleValidator;
use Kumwe\CMS\BusinessRecord\Application\RecordValueCodec;
use Kumwe\CMS\BusinessRecord\Application\RecordSecretRotation;
use Kumwe\CMS\BusinessRecord\Application\SecretCipher;
use Kumwe\CMS\BusinessRecord\Application\SecretKeyProvider;
use Kumwe\CMS\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessNumberSequenceAllocator;
use Kumwe\CMS\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordIdempotencyRepository;
use Kumwe\CMS\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordMutationFence;
use Kumwe\CMS\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordQueryCompiler;
use Kumwe\CMS\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordReadRepository;
use Kumwe\CMS\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordRevisionRepository;
use Kumwe\CMS\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordWriteRepository;
use Kumwe\CMS\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessSchemaRecordRepinGateway;
use Kumwe\CMS\BusinessRecord\Infrastructure\Persistence\DoctrinePostingPeriodRepository;
use Kumwe\CMS\BusinessRecord\Infrastructure\Persistence\DoctrineRecordSecretRotation;
use Kumwe\CMS\BusinessRecord\Infrastructure\Security\ConfiguredSecretKeyRings;
use Kumwe\CMS\BusinessRecord\Infrastructure\Security\KeyRingSecretCipher;
use Kumwe\CMS\BusinessRecord\Infrastructure\Security\KeyRingSecretKeyProvider;
use Kumwe\CMS\BusinessIntegration\Application\BusinessRecordMutationEventPublisher;
use Kumwe\CMS\BusinessIntegration\Application\DurableOutboundAdapterDispatcher;
use Kumwe\CMS\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\CMS\BusinessIntegration\Application\InboxStore;
use Kumwe\CMS\BusinessIntegration\Application\IntegrationEventConsumerDispatcher;
use Kumwe\CMS\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\CMS\BusinessIntegration\Application\IntegrationOperationsService;
use Kumwe\CMS\BusinessIntegration\Application\JobQueueProcessWorkHandler;
use Kumwe\CMS\BusinessIntegration\Application\OutboxDispatcher;
use Kumwe\CMS\BusinessIntegration\Application\OutboxStore;
use Kumwe\CMS\BusinessIntegration\Application\ScheduleRuntimeSynchronizer;
use Kumwe\CMS\BusinessIntegration\Application\ProcessManagerService;
use Kumwe\CMS\BusinessIntegration\Application\ProcessManagerStore;
use Kumwe\CMS\BusinessIntegration\Application\ProcessWorkDispatcher;
use Kumwe\CMS\BusinessIntegration\Application\TrustedRuntimeGenerationGuard;
use Kumwe\CMS\BusinessIntegration\Application\ValidatedContributedJobHandler;
use Kumwe\CMS\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\JobContributionDefinition;
use Kumwe\CMS\BusinessIntegration\Infrastructure\DoctrineInboxStore;
use Kumwe\CMS\BusinessIntegration\Infrastructure\DoctrineOutboxStore;
use Kumwe\CMS\BusinessIntegration\Infrastructure\DoctrineProcessManagerStore;
use Kumwe\CMS\BusinessIntegration\Infrastructure\ContributedScheduleSynchronizer;
use Kumwe\CMS\BusinessIntegration\Infrastructure\ContributedQueueRuntimePolicyCatalog;
use Kumwe\CMS\BusinessIntegration\Infrastructure\ExtensionRuntimeGenerationGuard;
use Kumwe\CMS\BusinessIntegration\Infrastructure\RuntimeIntegrationEventTransport;
use Kumwe\CMS\BusinessReporting\Application\ConsolidatedGroupReportScope;
use Kumwe\CMS\BusinessReporting\Application\ExportArtifactRepository;
use Kumwe\CMS\BusinessReporting\Application\ExportArtifactStorage;
use Kumwe\CMS\BusinessReporting\Application\ExportExecutionContextResolver;
use Kumwe\CMS\BusinessReporting\Application\ExportGenerationService;
use Kumwe\CMS\BusinessReporting\Application\ExportJobDispatcher;
use Kumwe\CMS\BusinessReporting\Application\ExportPolicySnapshotProvider;
use Kumwe\CMS\BusinessReporting\Application\ExportQueueProducerContextProvider;
use Kumwe\CMS\BusinessReporting\Application\ExportService;
use Kumwe\CMS\BusinessReporting\Application\GenerateReportExportHandler;
use Kumwe\CMS\BusinessReporting\Application\ProjectionRuntime;
use Kumwe\CMS\BusinessReporting\Application\RecordExportReportProvider;
use Kumwe\CMS\BusinessReporting\Application\ReportCsvEncoder;
use Kumwe\CMS\BusinessReporting\Application\ReportDefinitionRegistry;
use Kumwe\CMS\BusinessReporting\Application\ReportService;
use Kumwe\CMS\BusinessReporting\Application\ReportScopeResolver;
use Kumwe\CMS\BusinessReporting\Delivery\Administrator\AdministratorReportHandler;
use Kumwe\CMS\BusinessReporting\Delivery\Api\ReportApiHandler;
use Kumwe\CMS\BusinessReporting\Delivery\Api\ReportApiPresenter;
use Kumwe\CMS\BusinessReporting\Delivery\Console\ReportCommand;
use Kumwe\CMS\BusinessReporting\Delivery\Portal\PortalReportHandler;
use Kumwe\CMS\BusinessReporting\Domain\ReportDefinition;
use Kumwe\CMS\BusinessReporting\Infrastructure\BusinessRecordExportPolicySnapshotProvider;
use Kumwe\CMS\BusinessReporting\Infrastructure\BusinessRecordReportScopeResolver;
use Kumwe\CMS\BusinessReporting\Infrastructure\BusinessRecordServiceReportReader;
use Kumwe\CMS\BusinessReporting\Infrastructure\DoctrineExportArtifactRepository;
use Kumwe\CMS\BusinessReporting\Infrastructure\DoctrineProjectionRuntime;
use Kumwe\CMS\BusinessReporting\Infrastructure\FilesystemExportArtifactStorage;
use Kumwe\CMS\BusinessReporting\Infrastructure\JobQueueExportJobDispatcher;
use Kumwe\CMS\BusinessReporting\Infrastructure\LiveExportExecutionContextResolver;
use Kumwe\CMS\BusinessReporting\Infrastructure\SystemExportQueueProducerContextProvider;
use Kumwe\CMS\BusinessSurface\Application\BusinessApprovalSurfaceService;
use Kumwe\CMS\BusinessSurface\Application\BusinessMutationPlanService;
use Kumwe\CMS\BusinessSurface\Application\BusinessOperationStatusRepository;
use Kumwe\CMS\BusinessSurface\Application\BusinessOperationStatusService;
use Kumwe\CMS\BusinessSurface\Application\BusinessRecordProjector;
use Kumwe\CMS\BusinessSurface\Application\BusinessRecordQueryFactory;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\CMS\BusinessSurface\Application\CustomBusinessActionExecutor;
use Kumwe\CMS\BusinessSurface\Application\FieldModelPresenter;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessSurfaceDispatcher;
use Kumwe\CMS\BusinessSurface\Application\GeneratedBusinessActionStepUp;
use Kumwe\CMS\BusinessSurface\Application\MutationPlanCipher;
use Kumwe\CMS\BusinessSurface\Delivery\Administrator\AdministratorBusinessSurfaceHandler;
use Kumwe\CMS\BusinessSurface\Delivery\Browser\BusinessCustomViewPresenter;
use Kumwe\CMS\BusinessSurface\Delivery\Browser\BusinessFormInputMapper;
use Kumwe\CMS\BusinessSurface\Delivery\Browser\BusinessDocumentPresenter;
use Kumwe\CMS\BusinessSurface\Delivery\Browser\GeneratedBusinessBrowserController;
use Kumwe\CMS\BusinessSurface\Delivery\Portal\GeneratedBusinessPortalNavigationVisibility;
use Kumwe\CMS\BusinessSurface\Delivery\Portal\PortalBusinessSurfaceHandler;
use Kumwe\CMS\BusinessSurface\Infrastructure\Persistence\DoctrineBusinessOperationStatusRepository;
use Kumwe\CMS\BusinessSurface\Infrastructure\Security\KeyRingMutationPlanCipher;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresentationRegistry;
use Kumwe\CMS\BusinessSurface\Presentation\Field\RegistryFieldModelPresenter;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalRepository;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalQueryRepository;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalQueryService;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalService;
use Kumwe\CMS\BusinessSecurity\Application\Approval\StepUpProofConsumer;
use Kumwe\CMS\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationRepository;
use Kumwe\CMS\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService;
use Kumwe\CMS\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\CMS\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\CMS\BusinessSecurity\Infrastructure\Persistence\DoctrineApprovalRepository;
use Kumwe\CMS\BusinessSecurity\Infrastructure\Persistence\DoctrineApprovalQueryRepository;
use Kumwe\CMS\BusinessSecurity\Infrastructure\Persistence\DoctrineBusinessRecordAccessController;
use Kumwe\CMS\BusinessSecurity\Infrastructure\Persistence\DoctrineBusinessSecurityAdministrationRepository;
use Kumwe\CMS\BusinessSecurity\Infrastructure\Persistence\DoctrineMembershipDirectory;
use Kumwe\CMS\BusinessSecurity\Infrastructure\Persistence\DoctrineStepUpProofConsumer;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaEnvironment;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaExecutionLock;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaExecutionStateGuard;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaExecutor;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaLifecycleManager;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaLifecycleObserver;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaPlanner;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaPlanRepository;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaRecordRepinGateway;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaRecoveryEvidenceRepository;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\BusinessSchema\Application\DefinitionPhysicalSchemaCompiler;
use Kumwe\CMS\BusinessSchema\Application\PhysicalSchemaGateway;
use Kumwe\CMS\BusinessSchema\Application\PublishedDefinitionSchemaObserver;
use Kumwe\CMS\BusinessSchema\Delivery\Api\BusinessSchemaApiHandler;
use Kumwe\CMS\BusinessSchema\Delivery\Api\BusinessSchemaApiPresenter;
use Kumwe\CMS\Delivery\Http\Api\Business\BusinessApiResponder;
use Kumwe\CMS\Delivery\Http\Api\Business\BusinessApprovalApiHandler;
use Kumwe\CMS\Delivery\Http\Api\Business\BusinessApprovalApiPresenter;
use Kumwe\CMS\Delivery\Http\Api\Business\BusinessDefinitionDiscoveryApiHandler;
use Kumwe\CMS\Delivery\Http\Api\Business\BusinessOperationStatusApiHandler;
use Kumwe\CMS\Delivery\Http\Api\Business\BusinessRecordApiHandler;
use Kumwe\CMS\Delivery\Http\Api\Business\BusinessRecordApiPresenter;
use Kumwe\CMS\Delivery\Http\Api\Business\BusinessRecordApiResponder;
use Kumwe\CMS\Delivery\Http\Api\Business\PostingPeriodApiHandler;
use Kumwe\CMS\BusinessSchema\Delivery\Administrator\ApproveBusinessSchemaPlanHandler;
use Kumwe\CMS\BusinessSchema\Delivery\Administrator\BusinessSchemaPlansHandler;
use Kumwe\CMS\BusinessSchema\Delivery\Administrator\CreateBusinessSchemaPlanHandler;
use Kumwe\CMS\BusinessSchema\Delivery\Administrator\CreateBusinessSchemaPurgePlanHandler;
use Kumwe\CMS\BusinessSchema\Delivery\Administrator\ExecuteBusinessSchemaPlanHandler;
use Kumwe\CMS\BusinessSchema\Delivery\Administrator\RecordBusinessSchemaRecoveryEvidenceHandler;
use Kumwe\CMS\BusinessSchema\Delivery\Administrator\RecoverBusinessSchemaPlanHandler;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalNameCompiler;
use Kumwe\CMS\BusinessSchema\Infrastructure\Execution\ConfiguredBusinessSchemaEnvironment;
use Kumwe\CMS\BusinessSchema\Infrastructure\Execution\DoctrineBusinessSchemaExecutionLock;
use Kumwe\CMS\BusinessSchema\Infrastructure\Execution\DoctrineBusinessSchemaExecutionStateGuard;
use Kumwe\CMS\BusinessSchema\Infrastructure\Persistence\DoctrineBusinessSchemaInstallationRepository;
use Kumwe\CMS\BusinessSchema\Infrastructure\Persistence\DoctrineBusinessSchemaPlanRepository;
use Kumwe\CMS\BusinessSchema\Infrastructure\Persistence\DoctrineBusinessSchemaRecoveryEvidenceRepository;
use Kumwe\CMS\BusinessSchema\Infrastructure\Schema\CanonicalDefinitionPhysicalSchemaCompiler;
use Kumwe\CMS\BusinessSchema\Infrastructure\Schema\DoctrinePhysicalSchemaGateway;
use Kumwe\CMS\Content\Application\ContentRepository;
use Kumwe\CMS\Content\Application\ContentModelRepository;
use Kumwe\CMS\Content\Application\ContentModelService;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Application\TranslationGroupRepository;
use Kumwe\CMS\Content\Domain\JsonSchemaValidator;
use Kumwe\CMS\Content\Domain\SchemaCompatibilityChecker;
use Kumwe\CMS\Content\Infrastructure\Persistence\DoctrineContentModelRepository;
use Kumwe\CMS\Content\Infrastructure\Persistence\DoctrineContentRepository;
use Kumwe\CMS\Content\Infrastructure\Persistence\DoctrineTranslationGroupRepository;
use Kumwe\CMS\Content\Presentation\TranslationGroupPresenter;
use Kumwe\CMS\Demo\Application\DemoProfileLedger;
use Kumwe\CMS\Demo\Application\DemoProfileReconciler;
use Kumwe\CMS\Demo\Application\VdmBusinessManifestProjector;
use Kumwe\CMS\Demo\Application\VdmBusinessOperationGuard;
use Kumwe\CMS\Demo\Infrastructure\DemoAccessProvisioner;
use Kumwe\CMS\Demo\Infrastructure\DemoExampleExtensionInstaller;
use Kumwe\CMS\Demo\Infrastructure\DemoContentProfileInstaller;
use Kumwe\CMS\Demo\Infrastructure\DemoBusinessProfileExporter;
use Kumwe\CMS\Demo\Infrastructure\DemoProfileExporter;
use Kumwe\CMS\Demo\Infrastructure\DemoProfileInstaller;
use Kumwe\CMS\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\CMS\Demo\Infrastructure\Persistence\DoctrineDemoProfileLedger;
use Kumwe\CMS\Demo\Infrastructure\VdmBusinessDemoInstaller;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Extension\Application\Install\ExtensionInstallReconciler;
use Kumwe\CMS\Extension\Application\Migration\ExtensionMigrationRunner;
use Kumwe\CMS\Extension\Application\Package\ArchiveContentReader;
use Kumwe\CMS\Extension\Application\Package\ArchiveReader;
use Kumwe\CMS\Extension\Application\Package\ExtensionActivationAdmission;
use Kumwe\CMS\Extension\Application\Package\PackageAdmissionScanner;
use Kumwe\CMS\Extension\Application\Package\PackageCodeConformance;
use Kumwe\CMS\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\CMS\Extension\Application\Trust\ExtensionArtifactVerifier;
use Kumwe\CMS\Extension\Application\Trust\RevocationFeedSource;
use Kumwe\CMS\Extension\Application\Trust\RevocationFeedStateStore;
use Kumwe\CMS\Extension\Application\Trust\RevocationFeedSynchronizer;
use Kumwe\CMS\Extension\Application\Trust\RevocationListVerifier;
use Kumwe\CMS\Extension\Application\Trust\TrustKeySignatureVerifier;
use Kumwe\CMS\Extension\Application\Trust\TrustRuntimeInvalidator;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\CMS\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\CMS\Extension\Infrastructure\Package\ZipArchiveContentReader;
use Kumwe\CMS\Extension\Infrastructure\Package\ZipArchiveReader;
use Kumwe\CMS\Extension\Infrastructure\ExtensionRegistryFenceAllocator;
use Kumwe\CMS\Extension\Infrastructure\RedisLockedExtensionManager;
use Kumwe\CMS\Extension\Infrastructure\Trust\DoctrineRevocationFeedStateStore;
use Kumwe\CMS\Extension\Infrastructure\Trust\DoctrineTrustStoreRepository;
use Kumwe\CMS\Extension\Infrastructure\Trust\FilesystemExtensionArtifactVerifier;
use Kumwe\CMS\Extension\Infrastructure\Trust\SodiumRevocationListVerifier;
use Kumwe\CMS\Extension\Infrastructure\Trust\SodiumTrustKeySignatureVerifier;
use Kumwe\CMS\Extension\Infrastructure\Trust\StreamRevocationFeedSource;
use Kumwe\CMS\Extension\Development\ComponentScaffolder;
use Kumwe\CMS\Extension\Development\DeterministicPackageBuilder;
use Kumwe\CMS\Extension\Development\PackageInspector;
use Kumwe\CMS\Extension\Development\PackageSigner;
use Kumwe\CMS\Extension\Development\ProtectedSigningKeyReader;
use Kumwe\CMS\Extension\Development\StaticConformanceRunner;
use Kumwe\CMS\Extension\Runtime\ActiveExtensionSet;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\AdministratorViewRegistry;
use Kumwe\CMS\Extension\Contribution\ContributionDefinition;
use Kumwe\CMS\Portal\Contribution\PortalNavigationRegistry;
use Kumwe\CMS\Portal\Contribution\PortalTemplateRegistry;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeLoader;
use Kumwe\CMS\Extension\Runtime\ExtensionEventRegistrar;
use Kumwe\CMS\Extension\Runtime\JoomlaExtensionEventRegistrar;
use Kumwe\CMS\Extension\Runtime\LocalRuntimeReadinessProbe;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Extension\Runtime\RuntimeArtifactDigester;
use Kumwe\CMS\Extension\Runtime\RuntimeIdentity;
use Kumwe\CMS\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\CMS\Extension\Runtime\RuntimePublicationKeyRing;
use Kumwe\CMS\Delivery\Console\Command\CreateAccessTokenCommand;
use Kumwe\CMS\Delivery\Console\Command\CreateAdministratorCommand;
use Kumwe\CMS\Delivery\Console\Command\VerifyAuditTrailCommand;
use Kumwe\CMS\Delivery\Console\Command\DemoAccessCommand;
use Kumwe\CMS\Delivery\Console\Command\DemoExamplesCommand;
use Kumwe\CMS\Delivery\Console\Command\DemoExportCommand;
use Kumwe\CMS\Delivery\Console\Command\DemoInstallCommand;
use Kumwe\CMS\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\CMS\Delivery\Console\Command\ActivateExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\BuildExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\DisableExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\ExportAuditTrailCommand;
use Kumwe\CMS\Delivery\Console\Command\RotateRecordSecretsCommand;
use Kumwe\CMS\Delivery\Console\Command\HealthCheckCommand;
use Kumwe\CMS\Delivery\Console\Command\InstallExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\InspectExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\IntegrationWorkCommand;
use Kumwe\CMS\Delivery\Console\Command\ListExtensionsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageAutomationCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageIntegrationsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageAccessCommand;
use Kumwe\CMS\Delivery\Console\Command\RecoverCredentialsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageContentCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageBusinessDefinitionsCommand;
use Kumwe\CMS\Delivery\Console\Command\BusinessConsoleFailureMapper;
use Kumwe\CMS\Delivery\Console\Command\BusinessRecordConsolePresenter;
use Kumwe\CMS\Delivery\Console\Command\ManageBusinessRecordsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageBusinessSchemaCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageContentModelsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManagePostingPeriodsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageNavigationCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageSettingsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageTrustStoreCommand;
use Kumwe\CMS\Delivery\Console\Command\McpServeCommand;
use Kumwe\CMS\Delivery\Console\Command\MigrateCommand;
use Kumwe\CMS\Delivery\Console\Command\MaterializeExtensionRuntimeCommand;
use Kumwe\CMS\Delivery\Console\Command\WatchExtensionRuntimeCommand;
use Kumwe\CMS\Delivery\Console\Command\MigrationStatusCommand;
use Kumwe\CMS\Delivery\Console\Command\QueueWorkCommand;
use Kumwe\CMS\Delivery\Console\Command\RecoverMigrationLockCommand;
use Kumwe\CMS\Delivery\Console\Command\ScheduleRunCommand;
use Kumwe\CMS\Delivery\Console\Command\ScaffoldExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\SignExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\RunExtensionConformanceCommand;
use Kumwe\CMS\Delivery\Console\Command\UninstallExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\RecoverAdministratorThemeCommand;
use Kumwe\CMS\Delivery\Console\ConsoleApplication;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Delivery\Console\StreamOutput;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\PersistentIdempotencyMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\HttpMutationPreauthorizer;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\SecretOnceIdempotencyMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Content\ContentApiResponder;
use Kumwe\CMS\Delivery\Http\Api\Content\ContentCollectionHandler;
use Kumwe\CMS\Delivery\Http\Api\Content\ContentItemHandler;
use Kumwe\CMS\Delivery\Http\Api\Content\ContentModelApiHandler;
use Kumwe\CMS\Delivery\Http\Api\Content\ContentRestoreHandler;
use Kumwe\CMS\Delivery\Http\Api\Content\ContentTransitionHandler;
use Kumwe\CMS\Delivery\Http\Api\Extension\ExtensionApiHandler;
use Kumwe\CMS\Delivery\Http\Api\Extension\TrustStoreApiHandler;
use Kumwe\CMS\Delivery\Http\Api\Extension\TrustLifecycleMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Automation\AutomationApiHandler;
use Kumwe\CMS\Delivery\Http\Api\Identity\AccessControlApiHandler;
use Kumwe\CMS\Delivery\Http\Api\Navigation\MenuCollectionHandler;
use Kumwe\CMS\Delivery\Http\Api\Navigation\MenuItemCollectionHandler;
use Kumwe\CMS\Delivery\Http\Api\Navigation\MenuItemResourceHandler;
use Kumwe\CMS\Delivery\Http\Api\Navigation\MenuResourceHandler;
use Kumwe\CMS\Delivery\Http\Api\Navigation\NavigationApiResponder;
use Kumwe\CMS\Delivery\Http\Api\Plan\PlanPreviewHandler;
use Kumwe\CMS\Delivery\Http\Api\Plan\SafePlanFactory;
use Kumwe\CMS\Delivery\Http\Api\Site\SiteSettingsApiHandler;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Delivery\Http\Mcp\McpHttpHandler;
use Kumwe\CMS\Http\Handler\ApiIndexHandler;
use Kumwe\CMS\Http\Handler\HomePageHandler;
use Kumwe\CMS\Http\Handler\ExtensionAssetHandler;
use Kumwe\CMS\Http\Handler\LivenessHandler;
use Kumwe\CMS\Http\Handler\MediaAssetHandler;
use Kumwe\CMS\Http\Handler\MetricsHandler;
use Kumwe\CMS\Http\Handler\NotFoundHandler;
use Kumwe\CMS\Http\Handler\PublishedContentHandler;
use Kumwe\CMS\Http\Handler\ReadinessHandler;
use Kumwe\CMS\Http\Handler\RobotsHandler;
use Kumwe\CMS\Http\Middleware\BodyLimitMiddleware;
use Kumwe\CMS\Http\Middleware\BearerAuthenticationMiddleware;
use Kumwe\CMS\Http\Middleware\MetricsMiddleware;
use Kumwe\CMS\Http\Middleware\ProblemDetailsMiddleware;
use Kumwe\CMS\Http\Middleware\RequestIdMiddleware;
use Kumwe\CMS\Http\Middleware\SecurityHeadersMiddleware;
use Kumwe\CMS\Http\Middleware\TrustedHostMiddleware;
use Kumwe\CMS\Http\Middleware\TrustedProxyMiddleware;
use Kumwe\CMS\Http\Security\TrustedHostMatcher;
use Kumwe\CMS\Http\Security\TrustedProxyMatcher;
use Kumwe\CMS\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\CMS\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\CMS\Identity\Application\Administration\TokenDelegationPreauthorizer;
use Kumwe\CMS\Identity\Application\Administration\TokenRotationPreauthorizer;
use Kumwe\CMS\Identity\Application\Administration\AccessControlRepository;
use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Identity\Application\Administration\AccessTokenQuotaPolicy;
use Kumwe\CMS\Identity\Application\Administration\FixedAccessTokenQuotaPolicy;
use Kumwe\CMS\Identity\Application\Security\PasswordHasher;
use Kumwe\CMS\Identity\Application\StepUp\StepUpAttemptThrottle;
use Kumwe\CMS\Identity\Application\StepUp\AdministratorStepUpProvider;
use Kumwe\CMS\Identity\Application\StepUp\AuthorizationStepUpProofAdapter;
use Kumwe\CMS\Identity\Application\StepUp\StepUpCredentialStore;
use Kumwe\CMS\Identity\Application\StepUp\StepUpProofStore;
use Kumwe\CMS\Identity\Application\StepUp\StepUpProvider;
use Kumwe\CMS\Identity\Application\StepUp\StepUpRandomSource;
use Kumwe\CMS\Identity\Application\StepUp\StepUpRecoveryCodeHasher;
use Kumwe\CMS\Identity\Application\StepUp\StepUpSecretCipher;
use Kumwe\CMS\Identity\Application\StepUp\TotpAlgorithm;
use Kumwe\CMS\Identity\Application\StepUp\TotpStepUpProvider;
use Kumwe\CMS\Identity\Infrastructure\Administration\DoctrineAccessControlRepository;
use Kumwe\CMS\Identity\Infrastructure\Administration\DoctrineAdministratorIdentityGateway;
use Kumwe\CMS\Identity\Infrastructure\Administration\DoctrineAdministratorSessionStore;
use Kumwe\CMS\Identity\Infrastructure\Administration\RedisAuthenticationRateLimiter;
use Kumwe\CMS\Identity\Infrastructure\Authentication\DoctrineAccessTokenVerifier;
use Kumwe\CMS\Identity\Infrastructure\Security\NativePasswordHasher;
use Kumwe\CMS\Identity\Infrastructure\StepUp\AuthenticationRateLimiterStepUpThrottle;
use Kumwe\CMS\Identity\Infrastructure\StepUp\DoctrineStepUpCredentialStore;
use Kumwe\CMS\Identity\Infrastructure\StepUp\DoctrineStepUpProofStore;
use Kumwe\CMS\Identity\Infrastructure\StepUp\NativeStepUpRandomSource;
use Kumwe\CMS\Identity\Infrastructure\StepUp\Rfc6238Totp;
use Kumwe\CMS\Identity\Infrastructure\StepUp\SodiumStepUpRecoveryCodeHasher;
use Kumwe\CMS\Identity\Infrastructure\StepUp\SodiumStepUpSecretCipher;
use Kumwe\CMS\Infrastructure\Observability\CorrelationContext;
use Kumwe\CMS\Infrastructure\Observability\LogContextProcessor;
use Kumwe\CMS\Infrastructure\Observability\LogRedactionProcessor;
use Kumwe\CMS\Infrastructure\Observability\MetricCatalog;
use Kumwe\CMS\Infrastructure\Observability\MetricRecorder;
use Kumwe\CMS\Infrastructure\Observability\MetricsAccessPolicy;
use Kumwe\CMS\Infrastructure\Observability\NullMetricRecorder;
use Kumwe\CMS\Infrastructure\Observability\ObservabilityContract;
use Kumwe\CMS\Infrastructure\Observability\PrometheusExposition;
use Kumwe\CMS\Infrastructure\Observability\RedisMetricRecorder;
use Kumwe\CMS\Infrastructure\Observability\RuntimeMetricCollector;
use Kumwe\CMS\Infrastructure\Automation\DoctrineIdempotencyPurger;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineIdempotencyLedger;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineSecretOnceIdempotencyLedger;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationLock;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationPlan;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationRepository;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationRunner;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ApplicationAuthorizationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ApplicationAuthorizationMigrationRecovery;
use Kumwe\CMS\Infrastructure\Persistence\Migration\AuthorizationRecoveryIntegrationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessDefinitionCatalogMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessIntegrationSdkMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessNumberSequenceMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessRecordHistoryWindowMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessRecordIdempotencyRetentionMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessSecurityPortalMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\BusinessTransactionalRuntimeMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationCompatibilityMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationPortabilityMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ContentModelIdentifierCollationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ContentModelRuntimeMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DatabaseDrivenPresentationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DemoProfileProvenanceMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DocumentContentTypesMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DynamicSiteContentMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ExtensionContributionCatalogMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\IdempotencyLeaseNullabilityMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\IsolateThemeSurfacesMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DoctrineMigrationLock;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DoctrineMigrationRepository;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DoctrineNonTransactionalMigrationRecovery;
use Kumwe\CMS\Infrastructure\Persistence\Migration\IndexNameIsolationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\JobRecoveryMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\InstallationGlobalAutomationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\InterfacePresentationPreferenceMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\AuditTamperEvidenceMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\RecordEncryptionKeyRingMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\InterfaceMessageOverrideMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ResourceOwnershipScopeMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MultilingualContentMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\PeriodPostingLockMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\TranslationGroupSiteOwnershipMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\CredentialLifecycleMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ExtensionSupplyChainMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MenuPresentationBindingMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\NonTransactionalMigrationRecovery;
use Kumwe\CMS\Infrastructure\Persistence\Migration\NumberSequenceIdentityMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ResourceOwnershipPortabilityMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\SiteAutomationContextMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\TokenAndTrustLifecycleMigration;
use Kumwe\CMS\Infrastructure\Persistence\ReadinessProbe;
use Kumwe\CMS\Infrastructure\Security\DoctrineHighImpactCredentialGuard;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Redis\RedisConnectionFactory;
use Kumwe\CMS\Infrastructure\Redis\RedisRuntime;
use Kumwe\CMS\Infrastructure\Mcp\BusinessMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\CMS\Infrastructure\Mcp\McpMutationGuard;
use Kumwe\CMS\Infrastructure\Mcp\ReportMcpHandlers;
use Kumwe\CMS\Infrastructure\Authorization\DoctrineGrantScopeOwnershipReferences;
use Kumwe\CMS\Infrastructure\Authorization\DoctrineResourceSiteOwnership;
use Kumwe\CMS\Infrastructure\Authorization\DoctrineResourceSiteOwnershipWriter;
use Kumwe\CMS\Infrastructure\Authorization\DoctrineSiteGroupRegistry;
use Kumwe\CMS\Infrastructure\Authorization\DoctrineSiteGroupWriter;
use Kumwe\CMS\Infrastructure\Presentation\Persistence\DoctrinePresentationAccessGroupRepository;
use Kumwe\CMS\Infrastructure\Time\SystemClock;
use Kumwe\CMS\OpenApi\Application\OpenApiContractCache;
use Kumwe\CMS\OpenApi\Application\OpenApiComponentClaimAdmission;
use Kumwe\CMS\OpenApi\Application\OpenApiContractCompiler;
use Kumwe\CMS\OpenApi\Application\OpenApiContractService;
use Kumwe\CMS\OpenApi\Application\OpenApiExtensionActivationAdmission;
use Kumwe\CMS\OpenApi\Delivery\Http\OpenApiHandler;
use Kumwe\CMS\OpenApi\Infrastructure\FilesystemOpenApiContractCache;
use Kumwe\CMS\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\CMS\Kernel\Configuration\ConfigurationFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Site\Application\PublicPageLocator;
use Kumwe\CMS\Site\Application\SiteSettings;
use Kumwe\CMS\Site\Infrastructure\Persistence\DoctrineSiteSettings;
use Kumwe\CMS\Site\Infrastructure\Persistence\CachedSiteSettings;
use Kumwe\CMS\Navigation\Application\NavigationRepository;
use Kumwe\CMS\Navigation\Application\NavigationService;
use Kumwe\CMS\Navigation\Application\PublicNavigation;
use Kumwe\CMS\Navigation\Infrastructure\Persistence\DoctrineNavigationRepository;
use Kumwe\CMS\Media\Application\MediaService;
use Kumwe\CMS\Media\Application\MediaStorage;
use Kumwe\CMS\Media\Infrastructure\FilesystemMediaStorage;
use Kumwe\CMS\Presentation\Application\ThemeActivationGuard;
use Kumwe\CMS\Application\Presentation\ThemePackageValidator;
use Kumwe\CMS\Presentation\Application\ThemeMutationAuthorizer;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardComposer;
use Kumwe\CMS\Application\Presentation\Dashboard\DashboardPreferenceService;
use Kumwe\CMS\Delivery\Http\Dashboard\DashboardPreferenceFormDecoder;
use Kumwe\CMS\Delivery\Http\Dashboard\DashboardPreferenceQueryDecoder;
use Kumwe\CMS\Application\Presentation\Preference\PresentationPreferenceManager;
use Kumwe\CMS\Application\Presentation\Preference\PresentationPreferencePolicy;
use Kumwe\CMS\Application\Presentation\Preference\PresentationPreferenceRepository;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceResolver;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardPreferenceFormPresenter;
use Kumwe\CMS\Application\Presentation\Preference\RegisteredPresentationPreferencePolicy;
use Kumwe\CMS\Presentation\Infrastructure\DoctrineThemeActivationGuard;
use Kumwe\CMS\Presentation\Infrastructure\DoctrineAdministratorThemeRecovery;
use Kumwe\CMS\Presentation\Infrastructure\ConsoleAdministratorThemeRecovery;
use Kumwe\CMS\Presentation\Application\AdministratorThemeRecovery;
use Kumwe\CMS\Presentation\Infrastructure\DoctrineThemeMutationAuthorizer;
use Kumwe\CMS\Presentation\Infrastructure\TwigThemePackageValidator;
use Kumwe\CMS\Infrastructure\Presentation\Persistence\DoctrinePresentationPreferenceRepository;
use Kumwe\CMS\Presentation\Asset\ViteAssetManifest;
use Kumwe\CMS\Presentation\ContentLayoutCatalog;
use Kumwe\CMS\Presentation\ContentPresenter;
use Kumwe\CMS\Presentation\SiteRenderer;
use Kumwe\CMS\Presentation\RichTextFormatter;
use Kumwe\CMS\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\CMS\Presentation\Twig\IsolatedTwigEnvironmentFactory;
use Kumwe\CMS\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\CMS\Presentation\Twig\SiteTwigEnvironment;
use Kumwe\CMS\Portal\Application\DefaultPortalExecutionContextFactory;
use Kumwe\CMS\Portal\Application\MembershipPortalContextResolver;
use Kumwe\CMS\Portal\Application\MembershipPortalSessionIdentityLoader;
use Kumwe\CMS\Portal\Application\PortalAuthenticator;
use Kumwe\CMS\Portal\Application\PortalContextResolver;
use Kumwe\CMS\Portal\Application\PortalExecutionContextFactory;
use Kumwe\CMS\Portal\Application\PortalPrincipalLoader;
use Kumwe\CMS\Portal\Application\PortalSessionIdentityLoader;
use Kumwe\CMS\Portal\Application\PortalSessionStore;
use Kumwe\CMS\Portal\Application\SharedIdentityPortalAuthenticator;
use Kumwe\CMS\Portal\Http\Handler\PortalHomeHandler;
use Kumwe\CMS\Portal\Http\Handler\PortalDashboardPreferencesHandler;
use Kumwe\CMS\Portal\Http\Handler\PortalApprovalHandler;
use Kumwe\CMS\Portal\Http\Handler\PortalLoginHandler;
use Kumwe\CMS\Portal\Http\Handler\PortalLogoutHandler;
use Kumwe\CMS\Portal\Http\Handler\PortalSecurityHandler;
use Kumwe\CMS\Portal\Http\Middleware\PortalAuthorizationMiddleware;
use Kumwe\CMS\Portal\Http\Middleware\PortalCsrfMiddleware;
use Kumwe\CMS\Portal\Http\Middleware\PortalSessionMiddleware;
use Kumwe\CMS\Portal\Infrastructure\Identity\DoctrinePortalPrincipalLoader;
use Kumwe\CMS\Portal\Infrastructure\Session\DoctrinePortalSessionStore;
use Kumwe\CMS\Portal\Presentation\PortalRenderer;
use Kumwe\CMS\Portal\Presentation\Twig\PortalTwigEnvironmentFactory;
use Kumwe\CMS\Localization\Application\ActiveLocale;
use Kumwe\CMS\Localization\Application\CatalogueTranslator;
use Kumwe\CMS\Localization\Application\LocaleNegotiator;
use Kumwe\CMS\Localization\Application\MessageCatalogueRepository;
use Kumwe\CMS\Localization\Application\MessageOverrideRepository;
use Kumwe\CMS\Localization\Application\MessageOverrideService;
use Kumwe\CMS\Localization\Application\MessageOverrideStore;
use Kumwe\CMS\Localization\Application\MessagePatternFormatter;
use Kumwe\CMS\Localization\Application\MessagePatternValidator;
use Kumwe\CMS\Localization\Application\SiteDefaultLocale;
use Kumwe\CMS\Localization\Application\SupportedLocales;
use Kumwe\CMS\Localization\Application\Translator;
use Kumwe\CMS\Localization\Http\Middleware\LocaleNegotiationMiddleware;
use Kumwe\CMS\Localization\Http\Middleware\TranslationScopeMiddleware;
use Kumwe\CMS\Localization\Infrastructure\ArrayMessageOverrideRepository;
use Kumwe\CMS\Localization\Infrastructure\CompiledMessageCatalogueRepository;
use Kumwe\CMS\Localization\Infrastructure\DoctrineMessageOverrideRepository;
use Kumwe\CMS\Localization\Infrastructure\IntlMessagePatternFormatter;
use Kumwe\CMS\Localization\Presentation\TranslationTwigExtension;
use Kumwe\CMS\Workflow\Domain\Workflow;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\Emitter\EmitterStack;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Laminas\Stratigility\MiddlewarePipe;
use Laminas\Stratigility\MiddlewarePipeInterface;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Mezzio\Application;
use Mezzio\MiddlewareContainer;
use Mezzio\MiddlewareFactory;
use Mezzio\MiddlewareFactoryInterface;
use Mezzio\Response\ServerRequestErrorResponseGenerator;
use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\ImplicitHeadMiddleware;
use Mezzio\Router\Middleware\ImplicitOptionsMiddleware;
use Mezzio\Router\Middleware\MethodNotAllowedMiddleware;
use Mezzio\Router\Middleware\RouteMiddleware;
use Mezzio\Router\RouteCollector;
use Mezzio\Router\RouteCollectorInterface;
use Mezzio\Router\Route;
use Mezzio\Router\RouterInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Redis;

/**
 * Composition root that wires every Kumwe service a process can boot.
 *
 * This is the only place service construction happens. The HTTP front controller, the console entry
 * points and the test kernel all obtain their container from here, so there is exactly one
 * description of how persistence, extensions, delivery and automation fit together and no second
 * service locator to keep in step. The factory takes no constructor arguments and accepts no
 * caller-supplied authority: the kernel proof it mints per build is what binds the authorization
 * gateway and the system principals to services this class composed, and it never leaves the method
 * that created it.
 *
 * @since  2.0.0
 */
final class ContainerFactory
{
    /**
     * Compose the container for a normal boot, with the installed extension runtime loaded.
     *
     * Console services are registered too, so one container serves the front controller, the queue
     * worker and the CLI. Reach for `createRecovery()` instead when the caller must stay isolated
     * from installed extension code.
     *
     * @param   Environment  $environment  Allow-listed process and dotenv variables to configure from.
     *
     * @return  Container  Container with every shared service registered and the extension set active.
     *
     * @throws  \InvalidArgumentException  When a configuration variable is missing or malformed.
     * @throws  \ValueError  When `APP_ENV` names no known runtime or no trusted host is configured.
     * @throws  RuntimeException  When a trusted compiled runtime map is present but fails to load.
     *
     * @since   2.0.0
     */
    public function create(Environment $environment): Container
    {
        return $this->build($environment, true, true);
    }

    /**
     * Builds recovery surfaces without executing any installed extension code.
     *
     * `public/index.php` sends the health probes and the extension trust-key endpoints here, so an
     * operator can observe and re-key an installation whose compiled runtime map is missing,
     * untrusted or unloadable. Core services are wired exactly as in a normal boot; only the
     * extension providers are left unexecuted, which is why this cannot raise a map-loading failure.
     *
     * @param   Environment  $environment  Allow-listed process and dotenv variables to configure from.
     *
     * @return  Container  Container with core services only and an empty active extension set.
     *
     * @throws  \InvalidArgumentException  When a configuration variable is missing or malformed.
     * @throws  \ValueError  When `APP_ENV` names no known runtime or no trusted host is configured.
     *
     * @since   2.0.0
     */
    public function createRecovery(Environment $environment): Container
    {
        return $this->build($environment, true, false);
    }

    /**
     * Wire one container for the requested surface and extension policy.
     *
     * The kernel proof minted here is the object identity that `DenyByDefaultAuthorizationGateway`
     * and `SystemPrincipal` compare against. It is created inside this method, handed only to the
     * registrars that must issue trusted execution contexts, and never shared into the container, so
     * no extension or delivery adapter can obtain it and forge an authorized context.
     *
     * @param   Environment  $environment  Allow-listed process and dotenv variables to configure from.
     * @param   bool         $console      Whether to register the console commands, job handlers and worker.
     * @param   bool         $loadRuntime  Whether installed extension providers may execute during the build.
     *
     * @return  Container  Container with every registrar applied, ready to resolve services.
     *
     * @throws  \InvalidArgumentException  When a configuration variable is missing or malformed.
     * @throws  \ValueError  When `APP_ENV` names no known runtime or no trusted host is configured.
     * @throws  RuntimeException  When a trusted compiled runtime map is present but fails to load.
     *
     * @since   2.0.0
     */
    private function build(Environment $environment, bool $console, bool $loadRuntime): Container
    {
        // The proof never crosses the production composition boundary. In-process PHP
        // extensions execute with the same process authority as core and are trusted code;
        // integrations that need isolation must use an out-of-process delivery adapter.
        $kernelProof = new \stdClass();
        $configuration = (new ConfigurationFactory())->create($environment);
        $container = new Container();
        $root = dirname(__DIR__, 2);

        $container->share(Container::class, $container, true);
        $container->alias(ContainerInterface::class, Container::class);
        $container->share(ApplicationConfiguration::class, $configuration, true);
        $container->share(ClockInterface::class, new SystemClock(), true);
        $container->share(AutomationJobFormRegistry::class, static fn (
            Container $container,
        ): AutomationJobFormRegistry => AutomationJobFormRegistry::core(
            self::service($container, Translator::class),
        ), true);
        $container->share(JitterSource::class, new CryptographicJitterSource(), true);
        $container->share(RetryPolicy::class, static fn (Container $container): RetryPolicy => new RetryPolicy(
            self::service($container, ClockInterface::class),
            self::service($container, JitterSource::class),
        ), true);
        $container->share(Dispatcher::class, new Dispatcher(), true);
        $container->alias(DispatcherInterface::class, Dispatcher::class);

        $this->registerObservability($container, $configuration, $root, $console);
        $this->registerLogging($container, $configuration);
        $this->registerPersistence($container, $configuration, $root, $kernelProof, $loadRuntime);
        $this->registerExtensions($container, $configuration, $root, $kernelProof, $loadRuntime);
        $routeCacheFile = self::routeCacheFile(
            $root,
            $configuration->release,
            $loadRuntime,
            self::service($container, RuntimeMaterializationState::class),
        );
        $container->share('config', [
            'debug' => $configuration->debug,
            'router' => [
                'detect_duplicates' => true,
                'fastroute' => [
                    'cache_enabled' => $configuration->isProduction(),
                    'cache_file' => $routeCacheFile,
                ],
            ],
        ], true);
        $this->registerBusinessSurfaces($container, $root, $kernelProof);
        $this->registerMcp($container, $root);
        $this->registerHttp($container, $configuration, $root, $routeCacheFile, $loadRuntime);
        if ($console) {
            $this->registerConsole($container, $kernelProof);
        }

        return $container;
    }

    /**
     * Name a route cache for the exact immutable graph this kernel registers.
     *
     * Recovery deliberately omits portal and extension routes, so it must never share a dispatcher
     * with the full kernel. The full cache also follows the release and trusted runtime publication:
     * the runtime watcher reloads PHP-FPM without clearing its cache volume after activation, and a
     * fixed filename would otherwise keep routes from the superseded generation. Hashing the identity
     * keeps deployment-provided release values out of the filesystem path. New prefixes also retire
     * the legacy shared `routes.php` cache on upgrade.
     *
     * @param   string                       $root             Absolute repository root.
     * @param   string                       $release          Immutable application release identifier.
     * @param   bool                         $loadRuntime      Whether the full extension runtime is loaded.
     * @param   RuntimeMaterializationState  $materialization  Exact local runtime publication inspected at boot.
     *
     * @return  string  Absolute cache file unique to the kernel's route graph.
     *
     * @since   2.0.0
     */
    private static function routeCacheFile(
        string $root,
        string $release,
        bool $loadRuntime,
        RuntimeMaterializationState $materialization,
    ): string {
        $surface = $loadRuntime ? 'runtime' : 'recovery';
        $identity = [$surface, $release];
        if ($loadRuntime) {
            $identity[] = $materialization->trusted ? 'trusted' : 'untrusted';
            $identity[] = (string) $materialization->generation;
            $identity[] = $materialization->publicationChecksum;
        }

        return sprintf(
            '%s/storage/cache/routes-%s-%s.php',
            $root,
            $surface,
            hash('sha256', implode("\0", $identity)),
        );
    }

    /**
     * Register the declared observability contract and everything composed from it.
     *
     * `config/observability.php` is loaded exactly here and nowhere else, which is what makes it the
     * single source of truth rather than a second one: the logger's format, level and redaction, the
     * metric catalogue's forbidden labels, and the exposition endpoint's exposure all read the same
     * instance. A declaration the runtime cannot honour raises during composition, so the failure is a
     * boot error an operator sees rather than a silent divergence they do not.
     *
     * @param   Container                 $container      Container being composed.
     * @param   ApplicationConfiguration  $configuration  Boot configuration for release and metric exposure.
     * @param   string                    $root           Absolute repository root the contract is loaded from.
     * @param   bool                      $console        Whether this process is a console rather than a request.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the declared contract is missing or malformed.
     *
     * @since   2.0.0
     */
    private function registerObservability(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
        bool $console,
    ): void {
        $contract = ObservabilityContract::load($root);
        $surface = $console ? 'console' : 'http';
        $correlation = new CorrelationContext();
        $container->share(ObservabilityContract::class, $contract, true);
        $container->share(CorrelationContext::class, $correlation, true);
        // Both processors are invokable, which the container would otherwise read as a factory.
        $redaction = new LogRedactionProcessor($contract);
        $stamp = new LogContextProcessor($contract, $correlation, $configuration->release, $surface);
        $container->share(LogRedactionProcessor::class, static fn (): LogRedactionProcessor => $redaction, true);
        $container->share(LogContextProcessor::class, static fn (): LogContextProcessor => $stamp, true);
        $catalog = MetricCatalog::create($contract, $configuration->release, $surface);
        $container->share(MetricCatalog::class, $catalog, true);
        $container->share(PrometheusExposition::class, new PrometheusExposition(), true);
        $container->share(MetricsAccessPolicy::class, new MetricsAccessPolicy(
            $configuration->metricsEnabled ?? $contract->metricsEnabled,
            $contract->metricsPublic,
            $configuration->metricsToken,
        ), true);
        $enabled = $configuration->metricsEnabled ?? $contract->metricsEnabled;
        $container->share(MetricRecorder::class, static fn (Container $container): MetricRecorder =>
            $enabled
                ? new RedisMetricRecorder(self::service($container, RedisRuntime::class), $catalog)
                : new NullMetricRecorder(), true);
        $container->share(RuntimeMetricCollector::class, static fn (
            Container $container,
        ): RuntimeMetricCollector => new RuntimeMetricCollector(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
            new LocalRuntimeReadinessProbe(self::service($container, ExtensionRuntimeMapCompiler::class)),
            $configuration->release,
            $surface,
        ), true);
    }

    /**
     * Register the Monolog logger and its PSR-3 alias, wired to the declared logging contract.
     *
     * Records go to the destination the contract names — `php://stderr`, so the container runtime owns
     * log shipping rather than the application — formatted as the JSON the contract declares, stamped
     * with the required context, and passed through the redaction the contract lists. The emission
     * level is the contract's `default_level`, raised to debug by a debug deployment and overridden
     * outright by `KUMWE_LOG_LEVEL`, which is what lets an operator quieten or open up the log stream
     * without also widening the response detail the debug flag controls.
     *
     * Processors run context-first and redaction-last, so nothing a processor adds can bypass the
     * redaction list. Stack traces are switched off in the formatter as well as stripped by the
     * processor, because a trace holds frame arguments and frame arguments hold secrets.
     *
     * @param   Container                 $container      Container being composed.
     * @param   ApplicationConfiguration  $configuration  Boot configuration for debug, release and log level.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerLogging(Container $container, ApplicationConfiguration $configuration): void
    {
        $container->share(Logger::class, static function (Container $container) use ($configuration): Logger {
            $contract = self::service($container, ObservabilityContract::class);
            $handler = new StreamHandler(
                $contract->logDestination,
                self::logLevel($configuration->logLevel ?? (
                    $configuration->debug ? 'debug' : $contract->logDefaultLevel
                )),
            );
            $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true, true, false));
            $logger = new Logger('kumwe');
            $logger->pushHandler($handler);
            $logger->pushProcessor(self::service($container, LogRedactionProcessor::class));
            $logger->pushProcessor(self::service($container, LogContextProcessor::class));

            return $logger;
        }, true);
        $container->alias(LoggerInterface::class, Logger::class);
    }

    /**
     * Resolve a contract level name onto the Monolog level it selects.
     *
     * The mapping is explicit rather than delegated to `Level::fromName()` so the contract's
     * lower-case vocabulary is the only spelling this kernel accepts, and so an unknown name is a
     * refusal at composition rather than a level quietly resolving to something else.
     *
     * @param   string  $name  Level name from the contract or the `KUMWE_LOG_LEVEL` override.
     *
     * @return  Level  The selected Monolog level.
     *
     * @throws  \InvalidArgumentException  When the name is not one the contract declares.
     *
     * @since   2.0.0
     */
    private static function logLevel(string $name): Level
    {
        return match ($name) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => throw new \InvalidArgumentException('The configured log level is not a known level.'),
        };
    }

    /**
     * Register the storage, authorization and domain-service half of the graph.
     *
     * Every entry is a lazy shared factory, so composing a container opens no database or Redis
     * connection: the first `get()` for a service does. The kernel proof reaches the authorization
     * gateway, the access-token verifier and the identity, session and scheduling stores from here,
     * which is what makes the execution contexts they issue trustworthy.
     *
     * @param   Container                 $container      Container being composed.
     * @param   ApplicationConfiguration  $configuration  Boot configuration for credentials, limits and secrets.
     * @param   string                    $root           Absolute path of the repository root.
     * @param   \stdClass                 $kernelProof    Composition-root capability the gateway is bound to.
     * @param   bool                      $portalEnabled  Whether to register ordinary-user portal services.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerPersistence(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
        object $kernelProof,
        bool $portalEnabled,
    ): void {
        $provenance = $kernelProof;
        $databaseConfiguration = $configuration->database;
        $container->share(Connection::class, static fn (): Connection =>
            (new DoctrineConnectionFactory($databaseConfiguration))->create(), true);
        $container->share(TableNames::class, static fn (Container $container): TableNames => new TableNames(
            self::service($container, Connection::class),
            $databaseConfiguration->tablePrefix,
        ), true);
        $container->share(TransactionManager::class, static fn (Container $container): TransactionManager =>
            new DoctrineTransactionManager(self::service($container, Connection::class)), true);
        $redisConfiguration = $configuration->redis;
        $container->share(Redis::class, static fn (): Redis =>
            (new RedisConnectionFactory($redisConfiguration))->create(), true);
        $container->share(RedisRuntime::class, static fn (Container $container): RedisRuntime =>
            new RedisRuntime(self::service($container, Redis::class)), true);
        $container->share(AuthenticationRateLimiter::class, static fn (
            Container $container,
        ): AuthenticationRateLimiter => new RedisAuthenticationRateLimiter(
            self::service($container, RedisRuntime::class),
        ), true);
        $container->share(PasswordHasher::class, new NativePasswordHasher(), true);
        $container->share(HighImpactCredentialGuard::class, static fn (
            Container $container,
        ): HighImpactCredentialGuard => new DoctrineHighImpactCredentialGuard(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, PasswordHasher::class),
            self::service($container, AuthenticationRateLimiter::class),
        ), true);
        $container->share(AccessTokenQuotaPolicy::class, new FixedAccessTokenQuotaPolicy(), true);
        $container->share(Workflow::class, new Workflow(), true);
        $authorizationPolicies = new AuthorizationPolicyRegistry();
        $container->share(AuthorizationPolicyRegistry::class, $authorizationPolicies, true);
        $container->share(AuthorizationGateway::class, static fn (Container $container): AuthorizationGateway =>
            new DenyByDefaultAuthorizationGateway(
                $provenance,
                self::service($container, AuthorizationPolicyRegistry::class),
                self::service($container, MembershipContextValidator::class),
                self::service($container, ResourceSiteOwnership::class),
                new StructuredLogAuthorizationDecisionRecorder(self::service($container, LoggerInterface::class)),
            ), true);
        $container->share(ResourceSiteOwnership::class, static fn (Container $container): ResourceSiteOwnership =>
            new DoctrineResourceSiteOwnership(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, SiteGroupRegistry::class),
            ), true);
        // Business-group ownership scope: declared groups, the frozen per-category scope table, the
        // guarded scope-change operations and the consolidated read capability.
        $container->share(DoctrineSiteGroupRegistry::class, static fn (
            Container $container,
        ): DoctrineSiteGroupRegistry => new DoctrineSiteGroupRegistry(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->alias(SiteGroupRegistry::class, DoctrineSiteGroupRegistry::class);
        $container->share(SiteGroupWriter::class, static fn (Container $container): SiteGroupWriter =>
            new DoctrineSiteGroupWriter(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, DoctrineSiteGroupRegistry::class),
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(ResourceOwnershipScopePolicy::class, new ResourceOwnershipScopePolicy(), true);
        $container->share(ResourceOwnershipReferences::class, static fn (
            Container $container,
        ): ResourceOwnershipReferences => new CompositeResourceOwnershipReferences([
            new DoctrineGrantScopeOwnershipReferences(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ),
        ]), true);
        $container->share(SiteGroupAdministration::class, static fn (
            Container $container,
        ): SiteGroupAdministration => new SiteGroupAdministration(
            self::service($container, AuthorizationGateway::class),
            self::service($container, SiteGroupRegistry::class),
            self::service($container, SiteGroupWriter::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(ResourceOwnershipScopeService::class, static fn (
            Container $container,
        ): ResourceOwnershipScopeService => new ResourceOwnershipScopeService(
            self::service($container, AuthorizationGateway::class),
            self::service($container, ResourceSiteOwnership::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
            self::service($container, ResourceOwnershipScopePolicy::class),
            self::service($container, ResourceOwnershipReferences::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(ConsolidatedGroupReportScope::class, static fn (
            Container $container,
        ): ConsolidatedGroupReportScope => new ConsolidatedGroupReportScope(
            self::service($container, AuthorizationGateway::class),
            self::service($container, SiteGroupRegistry::class),
        ), true);
        $container->share(ResourceSiteOwnershipWriter::class, static fn (
            Container $container,
        ): ResourceSiteOwnershipWriter => new DoctrineResourceSiteOwnershipWriter(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(MembershipDirectory::class, static fn (Container $container): MembershipDirectory =>
            new DoctrineMembershipDirectory(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->alias(MembershipContextValidator::class, MembershipDirectory::class);
        $container->share(AuthorizationStepUpProofAdapter::class, new AuthorizationStepUpProofAdapter(), true);
        $container->share(StepUpSecretCipher::class, new SodiumStepUpSecretCipher(
            hash_hkdf('sha256', $configuration->secret, 32, 'kumwe-step-up-encryption-v1'),
        ), true);
        $container->share(StepUpRecoveryCodeHasher::class, new SodiumStepUpRecoveryCodeHasher(
            hash_hkdf('sha256', $configuration->secret, 32, 'kumwe-step-up-recovery-v1'),
        ), true);
        $container->share(StepUpRandomSource::class, new NativeStepUpRandomSource(), true);
        $container->share(TotpAlgorithm::class, new Rfc6238Totp(), true);
        $container->share(StepUpAttemptThrottle::class, static fn (
            Container $container,
        ): StepUpAttemptThrottle => new AuthenticationRateLimiterStepUpThrottle(
            self::service($container, AuthenticationRateLimiter::class),
            hash_hkdf('sha256', $configuration->secret, 32, 'kumwe-step-up-throttle-v1'),
        ), true);
        $container->share(StepUpCredentialStore::class, static fn (
            Container $container,
        ): StepUpCredentialStore => new DoctrineStepUpCredentialStore(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(StepUpProofStore::class, static fn (
            Container $container,
        ): StepUpProofStore => new DoctrineStepUpProofStore(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(PortalPrincipalLoader::class, static fn (
            Container $container,
        ): PortalPrincipalLoader => new DoctrinePortalPrincipalLoader(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            $provenance,
        ), true);
        if ($portalEnabled) {
            $container->share(PortalAuthenticator::class, static fn (
                Container $container,
            ): PortalAuthenticator => new SharedIdentityPortalAuthenticator(
                self::service($container, AdministratorIdentityGateway::class),
                self::service($container, PortalPrincipalLoader::class),
            ), true);
            $container->share(PortalContextResolver::class, static fn (
                Container $container,
            ): PortalContextResolver => new MembershipPortalContextResolver(
                self::service($container, MembershipDirectory::class),
                SiteContext::fromString($configuration->publicSite),
            ), true);
            $container->share(PortalSessionIdentityLoader::class, static fn (
                Container $container,
            ): PortalSessionIdentityLoader => new MembershipPortalSessionIdentityLoader(
                self::service($container, PortalPrincipalLoader::class),
                self::service($container, MembershipDirectory::class),
            ), true);
            $container->share(DoctrinePortalSessionStore::class, static fn (
                Container $container,
            ): DoctrinePortalSessionStore => new DoctrinePortalSessionStore(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, PortalSessionIdentityLoader::class),
                self::service($container, ClockInterface::class),
                self::service($container, ResourceSiteOwnershipWriter::class),
                self::service($container, TransactionManager::class),
                hash_hkdf('sha256', $configuration->secret, 32, 'kumwe-portal-session-binding-v1'),
                new \DateInterval('PT' . $configuration->administratorSessionSeconds . 'S'),
            ), true);
            $container->alias(PortalSessionStore::class, DoctrinePortalSessionStore::class);
            $container->share(PortalExecutionContextFactory::class, new DefaultPortalExecutionContextFactory(), true);
            $container->share(StepUpProvider::class, static fn (Container $container): StepUpProvider =>
                new TotpStepUpProvider(
                    self::service($container, StepUpCredentialStore::class),
                    self::service($container, StepUpSecretCipher::class),
                    self::service($container, StepUpRecoveryCodeHasher::class),
                    self::service($container, StepUpRandomSource::class),
                    self::service($container, TotpAlgorithm::class),
                    self::service($container, StepUpAttemptThrottle::class),
                    self::service($container, DoctrinePortalSessionStore::class),
                    self::service($container, StepUpProofStore::class),
                    self::service($container, TransactionManager::class),
                    self::service($container, AuditRecorder::class),
                    self::service($container, ClockInterface::class),
                ), true);
        }
        $container->share(ApprovalRepository::class, static fn (Container $container): ApprovalRepository =>
            new DoctrineApprovalRepository(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(ApprovalQueryRepository::class, static fn (
            Container $container,
        ): ApprovalQueryRepository => new DoctrineApprovalQueryRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(ApprovalQueryService::class, static fn (
            Container $container,
        ): ApprovalQueryService => new ApprovalQueryService(
            self::service($container, ApprovalQueryRepository::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, MembershipDirectory::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(StepUpProofConsumer::class, static fn (
            Container $container,
        ): StepUpProofConsumer => new DoctrineStepUpProofConsumer(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(BusinessSecurityAdministrationRepository::class, static fn (
            Container $container,
        ): BusinessSecurityAdministrationRepository => new DoctrineBusinessSecurityAdministrationRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
        ), true);
        $container->share(BusinessSecurityAdministrationService::class, static fn (
            Container $container,
        ): BusinessSecurityAdministrationService => new BusinessSecurityAdministrationService(
            self::service($container, BusinessSecurityAdministrationRepository::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, AuthorizationPolicyRegistry::class),
            self::service($container, MembershipDirectory::class),
            self::service($container, StepUpProofConsumer::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(ApprovalService::class, static fn (Container $container): ApprovalService =>
            new ApprovalService(
                self::service($container, ApprovalRepository::class),
                self::service($container, StepUpProofConsumer::class),
                self::service($container, MembershipDirectory::class),
                self::service($container, TransactionManager::class),
                self::service($container, AuthorizationGateway::class),
                self::service($container, ResourceSiteOwnershipWriter::class),
                self::service($container, AuditRecorder::class),
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(MigrationRepository::class, static fn (Container $container): MigrationRepository =>
            new DoctrineMigrationRepository(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(
            ApplicationAuthorizationMigrationRecovery::class,
            static fn (Container $container): ApplicationAuthorizationMigrationRecovery =>
                new ApplicationAuthorizationMigrationRecovery(
                    self::service($container, Connection::class),
                    self::service($container, TableNames::class),
                ),
            true,
        );
        $container->share(
            NonTransactionalMigrationRecovery::class,
            static fn (Container $container): NonTransactionalMigrationRecovery =>
                new DoctrineNonTransactionalMigrationRecovery(
                    self::service($container, Connection::class),
                    self::service($container, TableNames::class),
                    self::service($container, ApplicationAuthorizationMigrationRecovery::class),
                ),
            true,
        );
        $container->share(MigrationLock::class, static fn (Container $container): MigrationLock =>
            new DoctrineMigrationLock(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(ExpiredMigrationLockRecovery::class, static function (
            Container $container,
        ): ExpiredMigrationLockRecovery {
            $lock = self::service($container, MigrationLock::class);
            if (!$lock instanceof ExpiredMigrationLockRecovery) {
                throw new \RuntimeException('The migration lock has no expired-owner recovery implementation.');
            }

            return $lock;
        }, true);
        $container->share(MigrationLockRecoveryService::class, static fn (
            Container $container,
        ): MigrationLockRecoveryService => new MigrationLockRecoveryService(
            self::service($container, ExpiredMigrationLockRecovery::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(AccessTokenVerifier::class, static fn (Container $container): AccessTokenVerifier =>
            new DoctrineAccessTokenVerifier(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, ClockInterface::class),
                $provenance,
            ), true);
        $container->share(TrustStoreRepository::class, static fn (Container $container): TrustStoreRepository =>
            new DoctrineTrustStoreRepository(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(TrustKeySignatureVerifier::class, new SodiumTrustKeySignatureVerifier(), true);
        $container->share(AdministratorIdentityGateway::class, static fn (
            Container $container,
        ): AdministratorIdentityGateway => new DoctrineAdministratorIdentityGateway(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, PasswordHasher::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            self::service($container, AuthenticationRateLimiter::class),
            self::service($container, AuditRecorder::class),
            self::service($container, AccessTokenQuotaPolicy::class),
            $configuration->secret,
            self::service($container, AuthorizationGateway::class),
            self::service($container, AuthorizationPolicyRegistry::class),
            self::service($container, TokenDelegationPreauthorizer::class),
            self::service($container, TokenRotationPreauthorizer::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
            $provenance,
        ), true);
        $container->share(DoctrineAdministratorSessionStore::class, static fn (
            Container $container,
        ): DoctrineAdministratorSessionStore => new DoctrineAdministratorSessionStore(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
            $configuration->secret,
            self::service($container, AuthorizationGateway::class),
            self::service($container, TransactionManager::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
            $provenance,
            $configuration->administratorSessionSeconds,
            self::service($container, MembershipDirectory::class),
        ), true);
        $container->alias(AdministratorSessionStore::class, DoctrineAdministratorSessionStore::class);
        $container->share(AdministratorStepUpProvider::class, static fn (
            Container $container,
        ): AdministratorStepUpProvider => new TotpStepUpProvider(
            self::service($container, StepUpCredentialStore::class),
            self::service($container, StepUpSecretCipher::class),
            self::service($container, StepUpRecoveryCodeHasher::class),
            self::service($container, StepUpRandomSource::class),
            self::service($container, TotpAlgorithm::class),
            self::service($container, StepUpAttemptThrottle::class),
            self::service($container, DoctrineAdministratorSessionStore::class),
            self::service($container, StepUpProofStore::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(AuditRecorder::class, static fn (Container $container): AuditRecorder =>
            new DoctrineAuditRecorder(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(AuditArchiveStorage::class, static fn (): AuditArchiveStorage =>
            new FilesystemAuditArchiveStorage($root . '/storage/private/audit-archives'), true);
        $container->share(AuditAnchorWriter::class, static fn (Container $container): AuditAnchorWriter =>
            new DoctrineAuditAnchorWriter(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, AuditRecorder::class),
                self::service($container, ClockInterface::class),
                self::service($container, AuthorizationGateway::class),
            ), true);
        $container->share(AuditTrailVerifier::class, static fn (Container $container): AuditTrailVerifier =>
            new DoctrineAuditTrailVerifier(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, AuthorizationGateway::class),
            ), true);
        $container->share(AuditTrailExporter::class, static fn (Container $container): AuditTrailExporter =>
            new DoctrineAuditTrailExporter(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, AuditArchiveStorage::class),
                self::service($container, AuditRecorder::class),
                self::service($container, ClockInterface::class),
                self::service($container, AuthorizationGateway::class),
            ), true);
        $container->share(AuditRetentionService::class, static fn (Container $container): AuditRetentionService =>
            new DoctrineAuditRetentionService(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, AuditTrailExporter::class),
                self::service($container, AuditRecorder::class),
                self::service($container, ClockInterface::class),
                self::service($container, AuthorizationGateway::class),
            ), true);
        $container->share(ContentRepository::class, static fn (Container $container): ContentRepository =>
            new DoctrineContentRepository(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(TranslationGroupRepository::class, static fn (
            Container $container,
        ): TranslationGroupRepository => new DoctrineTranslationGroupRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(BusinessDefinitionRepository::class, static fn (
            Container $container,
        ): BusinessDefinitionRepository => new DoctrineBusinessDefinitionRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(BusinessSchemaPlanRepository::class, static fn (
            Container $container,
        ): BusinessSchemaPlanRepository => new DoctrineBusinessSchemaPlanRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(BusinessSchemaInstallationRepository::class, static fn (
            Container $container,
        ): BusinessSchemaInstallationRepository => new DoctrineBusinessSchemaInstallationRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(BusinessSchemaRecoveryEvidenceRepository::class, static fn (
            Container $container,
        ): BusinessSchemaRecoveryEvidenceRepository => new DoctrineBusinessSchemaRecoveryEvidenceRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(PhysicalSchemaGateway::class, static fn (
            Container $container,
        ): PhysicalSchemaGateway => new DoctrinePhysicalSchemaGateway(
            self::service($container, Connection::class),
        ), true);
        $container->share(BusinessSchemaExecutionLock::class, static fn (
            Container $container,
        ): BusinessSchemaExecutionLock => new DoctrineBusinessSchemaExecutionLock(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(BusinessSchemaExecutionStateGuard::class, static fn (
            Container $container,
        ): BusinessSchemaExecutionStateGuard => new DoctrineBusinessSchemaExecutionStateGuard(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(BusinessSchemaEnvironment::class, new ConfiguredBusinessSchemaEnvironment(
            $databaseConfiguration->driver,
            $databaseConfiguration->serverVersion,
            $configuration->release,
        ), true);
        $container->share(
            BusinessDefinitionCompatibilityAnalyzer::class,
            new BusinessDefinitionCompatibilityAnalyzer(),
            true,
        );
        $container->share(ContentModelRepository::class, static fn (Container $container): ContentModelRepository =>
            new DoctrineContentModelRepository(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(JsonSchemaValidator::class, new JsonSchemaValidator(), true);
        $container->share(SchemaCompatibilityChecker::class, new SchemaCompatibilityChecker(), true);
        $container->share(ContentModelService::class, static fn (Container $container): ContentModelService =>
            new ContentModelService(
                self::service($container, ContentModelRepository::class),
                self::service($container, JsonSchemaValidator::class),
                self::service($container, SchemaCompatibilityChecker::class),
                self::service($container, AuthorizationGateway::class),
                self::service($container, ResourceSiteOwnershipWriter::class),
                self::service($container, AuditRecorder::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(ContentService::class, static fn (Container $container): ContentService =>
            new ContentService(
                self::service($container, ContentRepository::class),
                self::service($container, AuditRecorder::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
                self::service($container, Workflow::class),
                self::service($container, AuthorizationGateway::class),
                self::service($container, ResourceSiteOwnershipWriter::class),
                self::service($container, ContentModelRepository::class),
                self::service($container, JsonSchemaValidator::class),
                self::service($container, TranslationGroupRepository::class),
                self::service($container, ExtensionContributionRegistrySet::class)->contentTranslationGroups(),
            ), true);
        $container->share(MediaStorage::class, new FilesystemMediaStorage(
            $root . '/storage/media',
            $root . '/resources/media',
        ), true);
        $container->share(MediaService::class, static fn (Container $container): MediaService => new MediaService(
            self::service($container, MediaStorage::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            $configuration->maxBodyBytes,
        ), true);
        $container->share(NavigationRepository::class, static fn (Container $container): NavigationRepository =>
            new DoctrineNavigationRepository(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
            ), true);
        $container->share(PublicNavigation::class, static fn (Container $container): PublicNavigation =>
            new PublicNavigation(
                self::service($container, NavigationRepository::class),
                self::service($container, ResourceSiteOwnership::class),
                SiteContext::fromString($configuration->publicSite),
            ), true);
        $container->share(NavigationService::class, static fn (Container $container): NavigationService =>
            new NavigationService(
                self::service($container, NavigationRepository::class),
                self::service($container, AuditRecorder::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
                self::service($container, AuthorizationGateway::class),
                self::service($container, ResourceSiteOwnershipWriter::class),
                self::service($container, ContentService::class),
            ), true);
        $container->share(AccessControlRepository::class, static fn (
            Container $container,
        ): AccessControlRepository => new DoctrineAccessControlRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(TokenDelegationPreauthorizer::class, static fn (
            Container $container,
        ): TokenDelegationPreauthorizer => new TokenDelegationPreauthorizer(
            self::service($container, AccessControlRepository::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(TokenRotationPreauthorizer::class, static fn (
            Container $container,
        ): TokenRotationPreauthorizer => new TokenRotationPreauthorizer(
            self::service($container, AccessControlRepository::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, TokenDelegationPreauthorizer::class),
        ), true);
        $container->share(AccessControlService::class, static fn (Container $container): AccessControlService =>
            new AccessControlService(
                self::service($container, AccessControlRepository::class),
                self::service($container, PasswordHasher::class),
                self::service($container, TransactionManager::class),
                self::service($container, AuditRecorder::class),
                self::service($container, ClockInterface::class),
                self::service($container, AuthorizationGateway::class),
                self::service($container, ResourceSiteOwnershipWriter::class),
                self::service($container, HighImpactCredentialGuard::class),
                self::service($container, StepUpCredentialStore::class),
                self::service($container, AdministratorSessionStore::class),
            ), true);
        $container->share(DoctrineSiteSettings::class, static fn (
            Container $container,
        ): DoctrineSiteSettings => new DoctrineSiteSettings(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, ContentService::class),
        ), true);
        $container->share(SiteSettings::class, static fn (Container $container): SiteSettings =>
            new CachedSiteSettings(
                self::service($container, DoctrineSiteSettings::class),
                self::service($container, RedisRuntime::class),
                self::service($container, LoggerInterface::class),
            ), true);
        $container->share(PublicPageLocator::class, static fn (Container $container): PublicPageLocator =>
            new PublicPageLocator(
                self::service($container, ContentService::class),
                self::service($container, SiteSettings::class),
                self::service($container, PublicNavigation::class),
                SiteContext::fromString($configuration->publicSite),
            ), true);
        $container->share(TranslationGroupPresenter::class, static fn (
            Container $container,
        ): TranslationGroupPresenter => new TranslationGroupPresenter(
            self::service($container, TranslationGroupRepository::class),
            self::service($container, ContentService::class),
            self::service($container, PublicPageLocator::class),
            self::service($container, ActiveLocale::class),
            self::service($container, ClockInterface::class),
            SiteContext::fromString($configuration->publicSite),
        ), true);
        $container->share(JobExecutionScope::class, new JobExecutionScope(), true);
        $container->share(JobQueue::class, static fn (Container $container): JobQueue =>
            new DoctrineJobQueue(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
                $configuration->release,
                self::service($container, AuthorizationGateway::class),
                self::service($container, ResourceSiteOwnershipWriter::class),
                self::service($container, JobExecutionScope::class),
                self::service($container, QueueRuntimePolicyCatalog::class),
            ), true);
        $container->share(DoctrineScheduler::class, static fn (
            Container $container,
        ): DoctrineScheduler => new DoctrineScheduler(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, ResourceSiteOwnership::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
            SystemPrincipal::issue($provenance, SystemIdentity::Scheduler),
            self::service($container, JobExecutionScope::class),
            self::service($container, ScheduleRuntimeSynchronizer::class),
            self::service($container, QueueRuntimePolicyCatalog::class),
        ), true);
        $container->alias(Scheduler::class, DoctrineScheduler::class);
        $container->alias(ScheduleRepository::class, DoctrineScheduler::class);
        $container->share(AutomationManagementService::class, static fn (
            Container $container,
        ): AutomationManagementService => new AutomationManagementService(
            self::service($container, ScheduleRepository::class),
            self::service($container, JobQueue::class),
            self::service($container, JobHandlerRegistry::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, JobExecutionScope::class),
            self::service($container, QueueRuntimeOperations::class),
        ), true);
        $container->share(MigrationPlan::class, static fn (Container $container): MigrationPlan =>
            new MigrationPlan(
                [
                    new CoreSchemaMigration(self::service($container, TableNames::class)),
                    new ApplicationAuthorizationMigration(self::service($container, TableNames::class)),
                    new JobRecoveryMigration(self::service($container, TableNames::class)),
                    new IdempotencyLeaseNullabilityMigration(self::service($container, TableNames::class)),
                    new AuthorizationRecoveryIntegrationMigration(self::service($container, TableNames::class)),
                    new TokenAndTrustLifecycleMigration(
                        self::service($container, TableNames::class),
                        $root . '/extensions',
                    ),
                    new SiteAutomationContextMigration(self::service($container, TableNames::class)),
                    new IsolateThemeSurfacesMigration(self::service($container, TableNames::class)),
                    new InstallationGlobalAutomationMigration(self::service($container, TableNames::class)),
                    new ContentModelRuntimeMigration(self::service($container, TableNames::class)),
                    new DynamicSiteContentMigration(self::service($container, TableNames::class)),
                    new DatabaseDrivenPresentationMigration(self::service($container, TableNames::class)),
                    new ExtensionContributionCatalogMigration(self::service($container, TableNames::class)),
                    new BusinessDefinitionCatalogMigration(self::service($container, TableNames::class)),
                    new BusinessTransactionalRuntimeMigration(self::service($container, TableNames::class)),
                    new BusinessRecordIdempotencyRetentionMigration(self::service($container, TableNames::class)),
                    new BusinessSecurityPortalMigration(self::service($container, TableNames::class)),
                    new BusinessIntegrationSdkMigration(self::service($container, TableNames::class)),
                    new DemoProfileProvenanceMigration(self::service($container, TableNames::class)),
                    new ContentModelIdentifierCollationMigration(self::service($container, TableNames::class)),
                    new InterfacePresentationPreferenceMigration(self::service($container, TableNames::class)),
                    new DocumentContentTypesMigration(self::service($container, TableNames::class)),
                    new MenuPresentationBindingMigration(self::service($container, TableNames::class)),
                    new AuditTamperEvidenceMigration(self::service($container, TableNames::class)),
                    new RecordEncryptionKeyRingMigration(self::service($container, TableNames::class)),
                    new CredentialLifecycleMigration(self::service($container, TableNames::class)),
                    new BusinessNumberSequenceMigration(self::service($container, TableNames::class)),
                    new ExtensionSupplyChainMigration(self::service($container, TableNames::class)),
                    new ResourceOwnershipPortabilityMigration(self::service($container, TableNames::class)),
                    new BusinessRecordHistoryWindowMigration(self::service($container, TableNames::class)),
                    new ResourceOwnershipScopeMigration(self::service($container, TableNames::class)),
                    new InterfaceMessageOverrideMigration(self::service($container, TableNames::class)),
                    new ConstraintNameIsolationCompatibilityMigration(
                        self::service($container, TableNames::class),
                    ),
                    new MultilingualContentMigration(self::service($container, TableNames::class)),
                    new TranslationGroupSiteOwnershipMigration(self::service($container, TableNames::class)),
                    new ConstraintNameIsolationPortabilityMigration(self::service($container, TableNames::class)),
                    new PeriodPostingLockMigration(self::service($container, TableNames::class)),
                    new NumberSequenceIdentityMigration(self::service($container, TableNames::class)),
                    new IndexNameIsolationMigration(self::service($container, TableNames::class)),
                ],
                [
                    // Previously distributed builds used a DBAL-equivalent static-analysis rewrite, then
                    // included a later ownership backfill here. The immutable source is restored;
                    // AuthorizationRecoveryIntegrationMigration owns and verifies the idempotent postcondition.
                    JobRecoveryMigration::ID => [
                        '5e55e74ae3027ecc5d4843e045cf19a3e07d0b7be1f2ce556807bb67eda61947',
                        '4d7fc30104c21bda0c00947fb82bce1333daa0d542e7292ee4e96bbda1c83b5d',
                    ],
                    // Existing databases keep the checksum of the immutable published rename. Fresh
                    // databases run the corrected compatibility implementation in that same plan slot.
                    ConstraintNameIsolationCompatibilityMigration::ID => [
                        ConstraintNameIsolationCompatibilityMigration::PUBLISHED_CHECKSUM,
                    ],
                ],
            ), true);
        $container->share(MigrationRunner::class, static fn (Container $container): MigrationRunner =>
            new MigrationRunner(
                database: self::service($container, Connection::class),
                repository: self::service($container, MigrationRepository::class),
                lock: self::service($container, MigrationLock::class),
                transactions: self::service($container, TransactionManager::class),
                plan: self::service($container, MigrationPlan::class),
                authorization: self::service($container, AuthorizationGateway::class),
                nonTransactionalRecovery: self::service($container, NonTransactionalMigrationRecovery::class),
            ), true);
        $container->share(ReadinessProbe::class, static fn (Container $container): ReadinessProbe =>
            new ReadinessProbe(
                database: self::service($container, Connection::class),
                logger: self::service($container, LoggerInterface::class),
                tables: self::service($container, TableNames::class),
                migrations: self::service($container, MigrationRepository::class),
                plan: self::service($container, MigrationPlan::class),
                recovery: self::service($container, NonTransactionalMigrationRecovery::class),
                redis: self::service($container, RedisRuntime::class),
                trust: self::service($container, TrustStore::class),
                runtime: self::service($container, ExtensionRuntimeMapCompiler::class),
                materialization: self::service($container, RuntimeMaterializationState::class),
            ), true);
    }

    /**
     * Register the presentation, routing and PSR-15 runner services, then share the application.
     *
     * Twig environments are built through `IsolatedTwigEnvironmentFactory` so a site template and an
     * administrator template can never read each other's files. The shared `Application` is
     * registered last because its factory pipes the middleware and declares every route, and so must
     * see the middleware and handlers this method registers on the way.
     *
     * @param   Container                 $container       Container being composed.
     * @param   ApplicationConfiguration  $configuration   Boot configuration for base URL, site and caching.
     * @param   string                    $root            Absolute path of the repository root.
     * @param   string                    $routeCacheFile  Kernel-specific FastRoute cache path.
     * @param   bool                      $portalEnabled   Whether to register the ordinary-user portal runtime.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerHttp(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
        string $routeCacheFile,
        bool $portalEnabled,
    ): void {
        $container->share(
            ViteAssetManifest::class,
            new ViteAssetManifest($root . '/public/assets/build/.vite/manifest.json'),
            true,
        );
        $container->share(ContentFormPresenter::class, new ContentFormPresenter(), true);
        $container->share(ContentFormDataMapper::class, new ContentFormDataMapper(), true);
        $container->share(ContentModelFormMapper::class, new ContentModelFormMapper(), true);
        $container->share(ContentModelFormPresenter::class, new ContentModelFormPresenter(), true);
        $container->share(SitePresentationFormMapper::class, static fn (
            Container $container,
        ): SitePresentationFormMapper => new SitePresentationFormMapper(
            self::service($container, Translator::class),
        ), true);
        $container->share(RichTextFormatter::class, new RichTextFormatter(), true);
        $container->share(ContentPresenter::class, static fn (Container $container): ContentPresenter =>
            new ContentPresenter(self::service($container, RichTextFormatter::class)), true);
        $container->share(ResponseFactoryInterface::class, new ResponseFactory(), true);
        $container->share(StreamFactoryInterface::class, new StreamFactory(), true);
        // Interface translation. Registered ahead of the Twig environments because every one of them
        // receives the same translation extension, and ahead of the pipeline because the locale is
        // negotiated before anything renders. ADR 0002 fixes the shape: XLIFF authored, compiled to
        // plain PHP, formatted by ICU, resolved through core, extension, site and organization.
        $container->share(SupportedLocales::class, new SupportedLocales(), true);
        $container->share(MessagePatternFormatter::class, new IntlMessagePatternFormatter(), true);
        $container->alias(MessagePatternValidator::class, MessagePatternFormatter::class);
        $container->share(MessageCatalogueRepository::class, static fn (
            Container $container,
        ): MessageCatalogueRepository => new CompiledMessageCatalogueRepository(
            $root . '/resources/localization/compiled',
            self::service($container, ActiveExtensionSet::class)->catalogueDirectories(),
        ), true);
        $container->share(DoctrineMessageOverrideRepository::class, static fn (
            Container $container,
        ): DoctrineMessageOverrideRepository => new DoctrineMessageOverrideRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->alias(MessageOverrideRepository::class, DoctrineMessageOverrideRepository::class);
        $container->alias(MessageOverrideStore::class, DoctrineMessageOverrideRepository::class);
        $container->share(MessageOverrideService::class, static fn (
            Container $container,
        ): MessageOverrideService => new MessageOverrideService(
            self::service($container, MessageOverrideStore::class),
            self::service($container, MessageCatalogueRepository::class),
            self::service($container, SupportedLocales::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, TransactionManager::class),
            self::service($container, MessagePatternValidator::class),
            self::service($container, Translator::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(ActiveLocale::class, static fn (Container $container): ActiveLocale =>
            new ActiveLocale(self::service($container, SupportedLocales::class)), true);
        $container->share(SiteDefaultLocale::class, static fn (Container $container): SiteDefaultLocale =>
            new SiteDefaultLocale(
                self::service($container, SiteSettings::class),
                self::service($container, SupportedLocales::class),
            ), true);
        $container->share(LocaleNegotiator::class, static fn (Container $container): LocaleNegotiator =>
            new LocaleNegotiator(
                self::service($container, SupportedLocales::class),
                self::service($container, SiteDefaultLocale::class),
            ), true);
        $container->share(Translator::class, static fn (Container $container): Translator =>
            new CatalogueTranslator(
                self::service($container, MessageCatalogueRepository::class),
                self::service($container, MessageOverrideRepository::class),
                self::service($container, MessagePatternFormatter::class),
                self::service($container, ActiveLocale::class),
                self::service($container, SupportedLocales::class),
            ), true);
        $container->share(TranslationTwigExtension::class, static fn (
            Container $container,
        ): TranslationTwigExtension => new TranslationTwigExtension(
            self::service($container, Translator::class),
            self::service($container, ActiveLocale::class),
        ), true);
        $container->share(LocaleNegotiationMiddleware::class, static fn (
            Container $container,
        ): LocaleNegotiationMiddleware => new LocaleNegotiationMiddleware(
            self::service($container, LocaleNegotiator::class),
            self::service($container, ActiveLocale::class),
            $configuration->publicSite,
        ), true);
        $container->share(TranslationScopeMiddleware::class, static fn (
            Container $container,
        ): TranslationScopeMiddleware => new TranslationScopeMiddleware(
            self::service($container, ActiveLocale::class),
        ), true);
        $container->share(IsolatedTwigEnvironmentFactory::class, static fn (
            Container $container,
        ): IsolatedTwigEnvironmentFactory => new IsolatedTwigEnvironmentFactory(
            self::service($container, ActiveExtensionSet::class),
            $root . '/templates',
            $root . '/storage/cache/twig',
            $configuration->isProduction(),
            self::service($container, TranslationTwigExtension::class),
        ), true);
        $container->share(SiteTwigEnvironment::class, static fn (
            Container $container,
        ): SiteTwigEnvironment => self::service($container, IsolatedTwigEnvironmentFactory::class)->site(
            SiteContext::fromString($configuration->publicSite),
        ), true);
        $container->share(AdministratorTwigEnvironment::class, static fn (
            Container $container,
        ): AdministratorTwigEnvironment => self::service(
            $container,
            IsolatedTwigEnvironmentFactory::class,
        )->administrator(), true);
        $container->share(RecoveryAdministratorTwigEnvironment::class, static fn (
            Container $container,
        ): RecoveryAdministratorTwigEnvironment => self::service(
            $container,
            IsolatedTwigEnvironmentFactory::class,
        )->recoveryAdministrator(), true);
        $container->share(SiteRenderer::class, static fn (Container $container): SiteRenderer =>
            new SiteRenderer(
                self::service($container, SiteTwigEnvironment::class),
                self::service($container, ViteAssetManifest::class),
                $configuration->baseUrl,
            ), true);
        $container->share(RecoveryAdministratorRenderer::class, static fn (
            Container $container,
        ): RecoveryAdministratorRenderer => new RecoveryAdministratorRenderer(
            self::service($container, RecoveryAdministratorTwigEnvironment::class),
        ), true);
        $container->share(AdministratorRenderer::class, static fn (Container $container): AdministratorRenderer =>
            new AdministratorRenderer(
                self::service($container, AdministratorTwigEnvironment::class),
                self::service($container, RecoveryAdministratorRenderer::class),
                self::service($container, AdministratorNavigationRegistry::class),
                self::service($container, ViteAssetManifest::class),
                self::service($container, AdministratorViewRegistry::class),
            ), true);
        if ($portalEnabled) {
            $container->share(
                PortalTwigEnvironmentFactory::class,
                static fn (Container $container): PortalTwigEnvironmentFactory => new PortalTwigEnvironmentFactory(
                    $configuration->isProduction(),
                    self::service($container, TranslationTwigExtension::class),
                ),
                true,
            );
            $container->share(PortalRenderer::class, static fn (Container $container): PortalRenderer =>
                new PortalRenderer(
                    self::service($container, PortalTwigEnvironmentFactory::class)->create(
                        $root . '/templates',
                        self::service($container, ActiveExtensionSet::class)->portalTemplatePaths(),
                        $root . '/storage/cache/twig/portal',
                    ),
                    self::service($container, PortalNavigationRegistry::class),
                    self::service($container, PortalTemplateRegistry::class),
                    new GeneratedBusinessPortalNavigationVisibility(
                        self::service($container, BusinessSurfaceCatalog::class),
                        self::service($container, PortalExecutionContextFactory::class),
                        self::service($container, ReportService::class),
                    ),
                    self::service($container, ViteAssetManifest::class),
                ), true);
        }
        $container->share(RouterInterface::class, static fn (): RouterInterface =>
            new FastRouteRouter(null, null, [
                'cache_enabled' => $configuration->isProduction(),
                'cache_file' => $routeCacheFile,
            ]), true);
        $container->share(RouteCollector::class, static fn (Container $container): RouteCollector =>
            new RouteCollector(self::service($container, RouterInterface::class), true), true);
        $container->alias(RouteCollectorInterface::class, RouteCollector::class);
        $container->share(MiddlewareContainer::class, static fn (Container $container): MiddlewareContainer =>
            new MiddlewareContainer($container), true);
        $container->share(MiddlewareFactory::class, static fn (Container $container): MiddlewareFactory =>
            new MiddlewareFactory(self::service($container, MiddlewareContainer::class)), true);
        $container->alias(MiddlewareFactoryInterface::class, MiddlewareFactory::class);
        $container->share(MiddlewarePipeInterface::class, new MiddlewarePipe(), true);
        $container->share(EmitterInterface::class, static function (): EmitterInterface {
            $emitter = new EmitterStack();
            $emitter->push(new SapiEmitter());

            return $emitter;
        }, true);
        $container->share(ServerRequestErrorResponseGenerator::class, static function (
            Container $container,
        ): ServerRequestErrorResponseGenerator {
            return new ServerRequestErrorResponseGenerator(
                self::service($container, ResponseFactoryInterface::class),
                false,
            );
        }, true);
        $container->share(RequestHandlerRunnerInterface::class, static function (
            Container $container,
        ): RequestHandlerRunnerInterface {
            return new RequestHandlerRunner(
                self::service($container, MiddlewarePipeInterface::class),
                self::service($container, EmitterInterface::class),
                static fn () => ServerRequestFactory::fromGlobals(),
                self::service($container, ServerRequestErrorResponseGenerator::class),
            );
        }, true);

        $this->registerMiddleware($container, $configuration, $portalEnabled);
        $this->registerHandlers($container, $configuration, $root, $portalEnabled);
        $container->share(Application::class, function (Container $container) use ($portalEnabled): Application {
            $application = new Application(
                self::service($container, MiddlewareFactoryInterface::class),
                self::service($container, MiddlewarePipeInterface::class),
                self::service($container, RouteCollectorInterface::class),
                self::service($container, RequestHandlerRunnerInterface::class),
            );
            $this->configureApplication($application, $container, $portalEnabled);

            return $application;
        }, true);
    }

    /**
     * Register the extension, trust and business-schema graph, then materialise the active set.
     *
     * This is the one registrar that does work eagerly. It inspects the locally compiled runtime map
     * and, when the caller allows it and the map is trusted, executes each extension provider with a
     * fixed allow-list of core services rather than the container itself. A missing, untrusted or
     * unverified map degrades to an empty `ActiveExtensionSet` instead of failing the boot, so a
     * damaged installation still answers on its recovery surfaces.
     *
     * @param   Container                 $container      Container being composed.
     * @param   ApplicationConfiguration  $configuration  Boot configuration for signing keys and identities.
     * @param   string                    $root           Absolute path of the repository root.
     * @param   object                    $kernelProof    Private provenance for worker integration contexts.
     * @param   bool                      $loadRuntime    Whether providers named by the map may execute.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a trusted map is loaded but its structure or an entry is invalid.
     *
     * @since   2.0.0
     */
    private function registerExtensions(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
        object $kernelProof,
        bool $loadRuntime,
    ): void {
        $mapFile = $root . '/storage/cache/extensions.json';
        $extensionRoot = $root . '/extensions';
        $publicAssetRoot = $root . '/public/assets/extensions';
        $keyRing = new RuntimePublicationKeyRing(
            $configuration->runtimeSigningKeyId,
            $configuration->runtimeSigningKey,
            $configuration->runtimePreviousSigningKeys,
        );
        $schemaObservers = new DeferredBusinessSchemaObserver(
            static fn (): PublishedDefinitionSchemaObserver => self::service(
                $container,
                PublishedDefinitionSchemaObserver::class,
            ),
            static fn (): BusinessSchemaLifecycleObserver => self::service(
                $container,
                BusinessSchemaLifecycleObserver::class,
            ),
        );
        $coreOpenApiJson = file_get_contents($root . '/api/openapi/kumwe-v1.json');
        if ($coreOpenApiJson === false) {
            throw new RuntimeException('The checked-in core OpenAPI contract cannot be read.');
        }
        $coreOpenApi = self::decodeOpenApiObject($coreOpenApiJson);
        $componentClaims = new OpenApiComponentClaimAdmission($coreOpenApi);
        $container->share(OpenApiComponentClaimAdmission::class, $componentClaims, true);
        $container->share(BusinessDefinitionContractAdmission::class, $componentClaims, true);
        $container->share(
            ExtensionActivationAdmission::class,
            new OpenApiExtensionActivationAdmission(
                $componentClaims,
                static fn (): BusinessDefinitionRepository => self::service(
                    $container,
                    BusinessDefinitionRepository::class,
                ),
            ),
            true,
        );
        $container->share(ArchiveReader::class, new ZipArchiveReader(), true);
        $container->share(ArchiveContentReader::class, new ZipArchiveContentReader(), true);
        $container->share(PackageSafetyPolicy::class, new PackageSafetyPolicy(), true);
        $container->share(PackageCodeConformance::class, new PackageCodeConformance(), true);
        $container->share(PackageAdmissionScanner::class, static fn (
            Container $container,
        ): PackageAdmissionScanner => new PackageAdmissionScanner(
            self::service($container, ArchiveContentReader::class),
            self::service($container, PackageCodeConformance::class),
            $configuration->packageConformanceAdmission,
        ), true);
        $container->share(ComponentScaffolder::class, new ComponentScaffolder(), true);
        $container->share(PackageInspector::class, static fn (Container $container): PackageInspector =>
            new PackageInspector(
                self::service($container, ArchiveReader::class),
                self::service($container, PackageSafetyPolicy::class),
            ), true);
        $container->share(DeterministicPackageBuilder::class, static fn (
            Container $container,
        ): DeterministicPackageBuilder => new DeterministicPackageBuilder(
            self::service($container, PackageInspector::class),
        ), true);
        $container->share(ProtectedSigningKeyReader::class, new ProtectedSigningKeyReader(), true);
        $container->share(PackageSigner::class, static fn (Container $container): PackageSigner =>
            new PackageSigner(
                self::service($container, ProtectedSigningKeyReader::class),
                self::service($container, PackageInspector::class),
            ), true);
        $container->share(StaticConformanceRunner::class, static fn (
            Container $container,
        ): StaticConformanceRunner => new StaticConformanceRunner(
            self::service($container, PackageInspector::class),
        ), true);
        $container->share(ExtensionMigrationRunner::class, static fn (
            Container $container,
        ): ExtensionMigrationRunner => new ExtensionMigrationRunner(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(PackageDefinitionSynchronizer::class, static fn (
            Container $container,
        ): PackageDefinitionSynchronizer => new DoctrinePackageDefinitionSynchronizer(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, BusinessDefinitionCompatibilityAnalyzer::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            $schemaObservers,
            $schemaObservers,
        ), true);
        $container->share(ExtensionRuntimeMapCompiler::class, static fn (
            Container $container,
        ): ExtensionRuntimeMapCompiler => new ExtensionRuntimeMapCompiler(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            $mapFile,
            $extensionRoot,
            $publicAssetRoot,
            self::service($container, ClockInterface::class),
            new RuntimeIdentity(
                $configuration->deploymentId,
                $configuration->replicaId,
                $configuration->processId,
                $configuration->instanceId,
            ),
            $keyRing,
            new RuntimeArtifactDigester(),
            3_600,
            300,
            self::service($container, LoggerInterface::class),
        ), true);
        $container->share(TrustRuntimeInvalidator::class, static fn (Container $container): TrustRuntimeInvalidator =>
            self::service($container, ExtensionRuntimeMapCompiler::class), true);
        $container->share(ExtensionArtifactVerifier::class, new FilesystemExtensionArtifactVerifier(
            $extensionRoot,
        ), true);
        $container->share(TrustStore::class, static fn (Container $container): TrustStore => new TrustStore(
            self::service($container, TrustStoreRepository::class),
            self::service($container, TrustKeySignatureVerifier::class),
            self::service($container, ExtensionArtifactVerifier::class),
            self::service($container, TrustRuntimeInvalidator::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, AuthorizationGateway::class),
            $configuration->allowUnsignedLocalExtensions,
            self::service($container, PackageDefinitionSynchronizer::class),
        ), true);
        $container->share(RevocationListVerifier::class, new SodiumRevocationListVerifier(), true);
        $container->share(RevocationFeedSource::class, new StreamRevocationFeedSource(), true);
        $container->share(RevocationFeedStateStore::class, static fn (
            Container $container,
        ): RevocationFeedStateStore => new DoctrineRevocationFeedStateStore(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(RevocationFeedSynchronizer::class, static fn (
            Container $container,
        ): RevocationFeedSynchronizer => new RevocationFeedSynchronizer(
            $configuration->revocationFeed,
            self::service($container, RevocationFeedSource::class),
            self::service($container, RevocationListVerifier::class),
            self::service($container, RevocationFeedStateStore::class),
            self::service($container, TrustStore::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, LoggerInterface::class),
        ), true);
        $contributionRegistries = new ExtensionContributionRegistrySet(
            self::service($container, TrustStore::class),
            authorizationPolicies: self::service($container, AuthorizationPolicyRegistry::class),
        );
        $container->share(ExtensionContributionRegistrySet::class, $contributionRegistries, true);
        $container->share(DoctrinePresentationPreferenceRepository::class, static fn (
            Container $container,
        ): DoctrinePresentationPreferenceRepository => new DoctrinePresentationPreferenceRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->alias(
            PresentationPreferenceRepository::class,
            DoctrinePresentationPreferenceRepository::class,
        );
        $container->share(DoctrinePresentationAccessGroupRepository::class, static fn (
            Container $container,
        ): DoctrinePresentationAccessGroupRepository => new DoctrinePresentationAccessGroupRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->alias(
            PresentationAccessGroupRepository::class,
            DoctrinePresentationAccessGroupRepository::class,
        );
        $container->share(
            PresentationPreferencePolicy::class,
            new RegisteredPresentationPreferencePolicy($contributionRegistries->interfaceSurfaces()),
            true,
        );
        $container->share(PresentationPreferenceResolver::class, static fn (
            Container $container,
        ): PresentationPreferenceResolver => new PresentationPreferenceResolver(
            self::service($container, PresentationPreferenceRepository::class),
            self::service($container, PresentationPreferencePolicy::class),
        ), true);
        $container->share(DashboardComposer::class, static fn (Container $container): DashboardComposer =>
            new DashboardComposer(
                self::service($container, PresentationPreferenceResolver::class),
                self::service($container, PresentationAccessGroupRepository::class),
            ), true);
        $container->share(PresentationPreferenceManager::class, static fn (
            Container $container,
        ): PresentationPreferenceManager => new PresentationPreferenceManager(
            self::service($container, PresentationPreferenceRepository::class),
            self::service($container, PresentationPreferencePolicy::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, TransactionManager::class),
            self::service($container, MembershipContextValidator::class),
            self::service($container, PresentationAccessGroupRepository::class),
        ), true);
        $container->share(DashboardPreferenceService::class, static fn (
            Container $container,
        ): DashboardPreferenceService => new DashboardPreferenceService(
            self::service($container, PresentationPreferenceManager::class),
            self::service($container, PresentationAccessGroupRepository::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(
            DashboardPreferenceFormPresenter::class,
            new DashboardPreferenceFormPresenter(),
            true,
        );
        $container->share(DashboardPreferenceFormDecoder::class, new DashboardPreferenceFormDecoder(), true);
        $container->share(DashboardPreferenceQueryDecoder::class, new DashboardPreferenceQueryDecoder(), true);
        $eventContracts = $contributionRegistries->validateIntegrationContributions();
        $container->share(EventContractRegistry::class, $eventContracts, true);
        $container->share(OutboxStore::class, static fn (Container $container): OutboxStore =>
            new DoctrineOutboxStore(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
                self::service($container, EventContractRegistry::class),
            ), true);
        $container->share(InboxStore::class, static fn (Container $container): InboxStore =>
            new DoctrineInboxStore(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
                self::service($container, EventContractRegistry::class),
                self::service($container, QueueRuntimePolicyCatalog::class),
            ), true);
        $container->share(ProcessManagerStore::class, static fn (Container $container): ProcessManagerStore =>
            new DoctrineProcessManagerStore(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(ProcessManagerService::class, static fn (
            Container $container,
        ): ProcessManagerService => new ProcessManagerService(
            self::service($container, ProcessManagerStore::class),
            self::service($container, EventContractRegistry::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(IntegrationOperationsService::class, static fn (
            Container $container,
        ): IntegrationOperationsService => new IntegrationOperationsService(
            self::service($container, OutboxStore::class),
            self::service($container, InboxStore::class),
            self::service($container, ProcessManagerStore::class),
            self::service($container, ProcessManagerService::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, ProjectionRuntime::class),
        ), true);
        $container->share(BusinessRecordMutationEventPublisher::class, static fn (
            Container $container,
        ): BusinessRecordMutationEventPublisher => new BusinessRecordMutationEventPublisher(
            self::service($container, EventContractRegistry::class),
            self::service($container, ExtensionContributionRegistrySet::class),
            self::service($container, OutboxStore::class),
        ), true);
        $container->share(FieldTypeRegistry::class, $contributionRegistries->fieldTypes(), true);
        $container->share(DoctrinePersistedFieldTypeDefinitionResolver::class, static fn (
            Container $container,
        ): DoctrinePersistedFieldTypeDefinitionResolver => new DoctrinePersistedFieldTypeDefinitionResolver(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, FieldTypeRegistry::class),
        ), true);
        $container->share(
            PhysicalNameCompiler::class,
            new PhysicalNameCompiler($configuration->database->tablePrefix),
            true,
        );
        $container->share(DefinitionPhysicalSchemaCompiler::class, static fn (
            Container $container,
        ): DefinitionPhysicalSchemaCompiler => new CanonicalDefinitionPhysicalSchemaCompiler(
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, DoctrinePersistedFieldTypeDefinitionResolver::class),
            self::service($container, PhysicalNameCompiler::class),
        ), true);
        $container->share(BusinessSchemaPlanner::class, static fn (
            Container $container,
        ): BusinessSchemaPlanner => new BusinessSchemaPlanner(
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, DefinitionPhysicalSchemaCompiler::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
            self::service($container, BusinessSchemaPlanRepository::class),
            self::service($container, PhysicalSchemaGateway::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, AuditRecorder::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->alias(PublishedDefinitionSchemaObserver::class, BusinessSchemaPlanner::class);
        $container->share(BusinessSchemaRecordRepinGateway::class, static fn (
            Container $container,
        ): BusinessSchemaRecordRepinGateway => new DoctrineBusinessSchemaRecordRepinGateway(
            self::service($container, Connection::class),
            self::service($container, RecordValueCodec::class),
            self::service($container, RecordRuleValidator::class),
        ), true);
        $container->share(BusinessSchemaExecutor::class, static fn (
            Container $container,
        ): BusinessSchemaExecutor => new BusinessSchemaExecutor(
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, DefinitionPhysicalSchemaCompiler::class),
            self::service($container, BusinessSchemaPlanRepository::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
            self::service($container, BusinessSchemaRecoveryEvidenceRepository::class),
            self::service($container, BusinessSchemaExecutionLock::class),
            self::service($container, BusinessSchemaExecutionStateGuard::class),
            self::service($container, PhysicalSchemaGateway::class),
            self::service($container, BusinessSchemaRecordRepinGateway::class),
            self::service($container, BusinessSchemaEnvironment::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, AuditRecorder::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(BusinessSchemaLifecycleManager::class, static fn (
            Container $container,
        ): BusinessSchemaLifecycleManager => new BusinessSchemaLifecycleManager(
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, DefinitionPhysicalSchemaCompiler::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
            self::service($container, BusinessSchemaPlanRepository::class),
            self::service($container, PhysicalSchemaGateway::class),
        ), true);
        $container->alias(BusinessSchemaLifecycleObserver::class, BusinessSchemaLifecycleManager::class);
        $container->share(BusinessSchemaService::class, static fn (
            Container $container,
        ): BusinessSchemaService => new BusinessSchemaService(
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, BusinessSchemaPlanner::class),
            self::service($container, BusinessSchemaExecutor::class),
            self::service($container, BusinessSchemaPlanRepository::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
            self::service($container, BusinessSchemaRecoveryEvidenceRepository::class),
            self::service($container, BusinessSchemaEnvironment::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, AuditRecorder::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
        ), true);
        $keyRings = new ConfiguredSecretKeyRings(
            $configuration->secret,
            $configuration->recordEncryption->legacySecret,
            $configuration->recordEncryption->activeKeyId,
            $configuration->recordEncryption->activeKey,
            $configuration->recordEncryption->previousKeys,
        );
        $recordFingerprintKey = hash_hmac(
            'sha256',
            'kumwe:business-record:fingerprint:v1',
            $configuration->secret,
            true,
        );
        $recordCursorKey = hash_hmac(
            'sha256',
            'kumwe:business-record:cursor:v1',
            $configuration->secret,
            true,
        );
        $container->share(SecretKeyProvider::class, new KeyRingSecretKeyProvider($keyRings->records()), true);
        $container->share(SecretCipher::class, static fn (
            Container $container,
        ): SecretCipher => new KeyRingSecretCipher(
            self::service($container, SecretKeyProvider::class),
        ), true);
        $container->share(
            MutationPlanCipher::class,
            new KeyRingMutationPlanCipher(new KeyRingSecretKeyProvider($keyRings->mutationPlans())),
            true,
        );
        $container->share(RecordFingerprint::class, new RecordFingerprint($recordFingerprintKey), true);
        $container->share(RecordCursorCodec::class, new RecordCursorCodec($recordCursorKey), true);
        $container->share(RecordValueCodec::class, static fn (
            Container $container,
        ): RecordValueCodec => new RecordValueCodec(
            self::service($container, SecretCipher::class),
            self::service($container, FieldTypeRegistry::class),
        ), true);
        $container->share(RecordRuleValidator::class, static fn (
            Container $container,
        ): RecordRuleValidator => new RecordRuleValidator(
            self::service($container, RecordValueCodec::class),
        ), true);
        $container->share(BusinessRecordDefinitionResolver::class, static fn (
            Container $container,
        ): BusinessRecordDefinitionResolver => new InstalledBusinessRecordDefinitionResolver(
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
        ), true);
        $container->share(DoctrineBusinessRecordQueryCompiler::class, static fn (
            Container $container,
        ): DoctrineBusinessRecordQueryCompiler => new DoctrineBusinessRecordQueryCompiler(
            self::service($container, Connection::class),
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
            self::service($container, RecordValueCodec::class),
            self::service($container, RecordCursorCodec::class),
            self::service($container, BusinessRecordMutationFence::class),
        ), true);
        $container->share(BusinessRecordWriteRepository::class, static fn (
            Container $container,
        ): BusinessRecordWriteRepository => new DoctrineBusinessRecordWriteRepository(
            self::service($container, Connection::class),
            self::service($container, RecordValueCodec::class),
        ), true);
        $container->share(BusinessRecordReadRepository::class, static fn (
            Container $container,
        ): BusinessRecordReadRepository => new DoctrineBusinessRecordReadRepository(
            self::service($container, Connection::class),
            self::service($container, RecordValueCodec::class),
            self::service($container, RecordRuleValidator::class),
            self::service($container, DoctrineBusinessRecordQueryCompiler::class),
            self::service($container, RecordCursorCodec::class),
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
            self::service($container, BusinessRecordMutationFence::class),
        ), true);
        $container->share(BusinessRecordRevisionRepository::class, static fn (
            Container $container,
        ): BusinessRecordRevisionRepository => new DoctrineBusinessRecordRevisionRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, DoctrineBusinessRecordQueryCompiler::class),
        ), true);
        $container->share(BusinessRecordIdempotencyRepository::class, static fn (
            Container $container,
        ): BusinessRecordIdempotencyRepository => new DoctrineBusinessRecordIdempotencyRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, RecordFingerprint::class),
        ), true);
        $container->share(BusinessRecordMutationFence::class, static fn (
            Container $container,
        ): BusinessRecordMutationFence => new DoctrineBusinessRecordMutationFence(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(RecordSecretRotation::class, static fn (
            Container $container,
        ): RecordSecretRotation => new DoctrineRecordSecretRotation(
            self::service($container, Connection::class),
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, BusinessSchemaInstallationRepository::class),
            self::service($container, BusinessRecordMutationFence::class),
            self::service($container, SecretCipher::class),
            self::service($container, SecretKeyProvider::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(BusinessRecordIdempotencyPurger::class, static fn (
            Container $container,
        ): BusinessRecordIdempotencyPurger => new BusinessRecordIdempotencyPurger(
            self::service($container, BusinessRecordIdempotencyRepository::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(BusinessRecordAccessController::class, static fn (
            Container $container,
        ): BusinessRecordAccessController => new DoctrineBusinessRecordAccessController(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, MembershipDirectory::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(BusinessNumberSequenceAllocator::class, static fn (
            Container $container,
        ): BusinessNumberSequenceAllocator => new DoctrineBusinessNumberSequenceAllocator(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->share(DoctrinePostingPeriodRepository::class, static fn (
            Container $container,
        ): DoctrinePostingPeriodRepository => new DoctrinePostingPeriodRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
        ), true);
        $container->alias(PostingPeriodRepository::class, DoctrinePostingPeriodRepository::class);
        $container->alias(PostingPeriodCalendar::class, DoctrinePostingPeriodRepository::class);
        $container->share(PostingPeriodLock::class, static fn (
            Container $container,
        ): PostingPeriodLock => new PostingPeriodLock(
            self::service($container, PostingPeriodRepository::class),
            self::service($container, RecordValueCodec::class),
        ), true);
        $container->share(PostingPeriodService::class, static fn (
            Container $container,
        ): PostingPeriodService => new PostingPeriodService(
            self::service($container, PostingPeriodRepository::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(BusinessRecordService::class, static fn (
            Container $container,
        ): BusinessRecordService => new BusinessRecordService(
            self::service($container, BusinessRecordWriteRepository::class),
            self::service($container, BusinessRecordReadRepository::class),
            self::service($container, BusinessRecordRevisionRepository::class),
            self::service($container, BusinessRecordIdempotencyRepository::class),
            self::service($container, BusinessRecordMutationFence::class),
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, BusinessNumberSequenceAllocator::class),
            self::service($container, RecordValueCodec::class),
            self::service($container, RecordRuleValidator::class),
            self::service($container, BusinessRecordAccessController::class),
            self::service($container, ApprovalService::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, RecordFingerprint::class),
            self::service($container, ClockInterface::class),
            self::service($container, PostingPeriodLock::class),
            self::service($container, PostingPeriodCalendar::class),
            self::service($container, BusinessRecordMutationEventPublisher::class),
            $configuration->idempotencyReplay,
        ), true);
        $container->share(
            BusinessDefinitionValidator::class,
            new BusinessDefinitionValidator($contributionRegistries->fieldTypes()),
            true,
        );
        $container->share(BusinessDefinitionService::class, static fn (
            Container $container,
        ): BusinessDefinitionService => new BusinessDefinitionService(
            self::service($container, BusinessDefinitionRepository::class),
            self::service($container, BusinessDefinitionValidator::class),
            self::service($container, BusinessDefinitionCompatibilityAnalyzer::class),
            self::service($container, BusinessDefinitionContractAdmission::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, ResourceSiteOwnershipWriter::class),
            self::service($container, AuditRecorder::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            self::service($container, PublishedDefinitionSchemaObserver::class),
        ), true);
        $container->share(
            AdministratorNavigationRegistry::class,
            $contributionRegistries->navigation(),
            true,
        );
        $container->share(AdministratorViewRegistry::class, $contributionRegistries->views(), true);
        $container->share(PortalNavigationRegistry::class, $contributionRegistries->portalNavigation(), true);
        $container->share(PortalTemplateRegistry::class, $contributionRegistries->portalTemplates(), true);
        $container->share(ThemeActivationGuard::class, static fn (
            Container $container,
        ): ThemeActivationGuard => new DoctrineThemeActivationGuard(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, PasswordHasher::class),
            self::service($container, AuthenticationRateLimiter::class),
        ), true);
        $container->share(DoctrineThemeMutationAuthorizer::class, static fn (
            Container $container,
        ): DoctrineThemeMutationAuthorizer => new DoctrineThemeMutationAuthorizer(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->alias(ThemeMutationAuthorizer::class, DoctrineThemeMutationAuthorizer::class);
        $container->share(ThemePackageValidator::class, static fn (
            Container $container,
        ): ThemePackageValidator => new TwigThemePackageValidator(
            $root . '/templates',
            self::service($container, TranslationTwigExtension::class),
        ), true);
        $container->share(ExtensionRegistryFenceAllocator::class, static fn (
            Container $container,
        ): ExtensionRegistryFenceAllocator => new ExtensionRegistryFenceAllocator(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(ExtensionManager::class, static fn (Container $container): ExtensionManager =>
            new RedisLockedExtensionManager(
                new DoctrineExtensionManager(
                    self::service($container, Connection::class),
                    self::service($container, TableNames::class),
                    $extensionRoot,
                    $publicAssetRoot,
                    self::service($container, ArchiveReader::class),
                    self::service($container, PackageSafetyPolicy::class),
                    self::service($container, ExtensionMigrationRunner::class),
                    self::service($container, ExtensionRuntimeMapCompiler::class),
                    self::service($container, TransactionManager::class),
                    self::service($container, AuditRecorder::class),
                    self::service($container, ClockInterface::class),
                    self::service($container, DispatcherInterface::class),
                    self::service($container, ThemeActivationGuard::class),
                    self::service($container, ThemePackageValidator::class),
                    self::service($container, ThemeMutationAuthorizer::class),
                    self::service($container, TrustStore::class),
                    self::service($container, AuthorizationGateway::class),
                    self::service($container, ResourceSiteOwnershipWriter::class),
                    self::service($container, PackageDefinitionSynchronizer::class),
                    self::service($container, ExtensionActivationAdmission::class),
                    self::service($container, PackageAdmissionScanner::class),
                ),
                self::service($container, RedisRuntime::class),
                self::service($container, AuthorizationGateway::class),
                self::service($container, ExtensionRegistryFenceAllocator::class),
                self::service($container, TrustStore::class),
            ), true);
        $container->alias(ExtensionInstallReconciler::class, ExtensionManager::class);
        $compiler = self::service($container, ExtensionRuntimeMapCompiler::class);
        $materialization = $compiler->inspectLocal();
        $container->share(RuntimeMaterializationState::class, $materialization, true);
        $active = $loadRuntime
            && $materialization->trusted
            && $materialization->publication !== null
            ? (new ExtensionRuntimeLoader(
                $materialization->publication,
                $extensionRoot,
                $keyRing,
                self::service($container, TrustStore::class),
            ))->load([
                BusinessRecordService::class => self::service($container, BusinessRecordService::class),
                ContentService::class => self::service($container, ContentService::class),
                ExtensionEventRegistrar::class => new JoomlaExtensionEventRegistrar(
                    self::service($container, DispatcherInterface::class),
                ),
                NavigationService::class => self::service($container, NavigationService::class),
                SiteSettings::class => self::service($container, SiteSettings::class),
            ], $contributionRegistries)
            : new ActiveExtensionSet($contributionRegistries, self::service($container, TrustStore::class));
        $container->share(ActiveExtensionSet::class, $active, true);
        $container->share(QueueRuntimePolicyCatalog::class, new ContributedQueueRuntimePolicyCatalog(
            $contributionRegistries,
            $materialization,
        ), true);
        $container->share(QueueRuntimeOperations::class, static fn (
            Container $container,
        ): QueueRuntimeOperations => new DoctrineQueueRuntimeOperations(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, QueueRuntimePolicyCatalog::class),
        ), true);
        $contributionRegistries->validateIntegrationContributions();
        $eventSchemas = self::contributionDefinitions(
            $contributionRegistries->eventSchemas()->definitions(),
            EventSchemaDefinition::class,
        );
        $eventConsumers = self::contributionDefinitions(
            $contributionRegistries->eventConsumers()->definitions(),
            EventConsumerDefinition::class,
        );
        $jobs = self::contributionDefinitions(
            $contributionRegistries->jobs()->definitions(),
            JobContributionDefinition::class,
        );
        $eventContracts->replace($eventSchemas, $eventConsumers);
        self::service($container, JobExecutionScope::class)->replace($jobs);
        (new ContributedJobFormCompiler())->compile(
            $jobs,
            self::service($container, AutomationJobFormRegistry::class),
        );
        $container->share(ScheduleRuntimeSynchronizer::class, static fn (
            Container $container,
        ): ScheduleRuntimeSynchronizer => new ContributedScheduleSynchronizer(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            self::service($container, ExtensionContributionRegistrySet::class),
            self::service($container, RuntimeMaterializationState::class),
            queuePolicies: self::service($container, QueueRuntimePolicyCatalog::class),
        ), true);

        $container->share(TrustedRuntimeGenerationGuard::class, new ExtensionRuntimeGenerationGuard(
            $compiler,
            $materialization,
        ), true);
        $container->share(ProjectionRuntime::class, static fn (Container $container): ProjectionRuntime =>
            new DoctrineProjectionRuntime(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, ClockInterface::class),
                self::service($container, TrustedRuntimeGenerationGuard::class),
                self::service($container, RuntimeMaterializationState::class),
                self::service($container, ExtensionContributionRegistrySet::class)
                    ->projections()
                    ->executableEntries(),
            ), true);
        $container->share(IntegrationEventConsumerDispatcher::class, static fn (
            Container $container,
        ): IntegrationEventConsumerDispatcher => new IntegrationEventConsumerDispatcher(
            self::service($container, InboxStore::class),
            self::service($container, EventContractRegistry::class),
            self::service($container, RetryPolicy::class),
            self::service($container, TrustedRuntimeGenerationGuard::class),
            self::service($container, TransactionManager::class),
            self::service($container, LoggerInterface::class),
            self::service($container, QueueRuntimePolicyCatalog::class),
        ), true);
        $container->share(DurableOutboundAdapterDispatcher::class, static fn (
            Container $container,
        ): DurableOutboundAdapterDispatcher => new DurableOutboundAdapterDispatcher(
            self::service($container, InboxStore::class),
            self::service($container, EventContractRegistry::class),
            self::service($container, RetryPolicy::class),
            self::service($container, TrustedRuntimeGenerationGuard::class),
            self::service($container, LoggerInterface::class),
            self::service($container, QueueRuntimePolicyCatalog::class),
        ), true);
        $container->share(IntegrationEventTransport::class, static fn (
            Container $container,
        ): IntegrationEventTransport => new RuntimeIntegrationEventTransport(
            self::service($container, ExtensionContributionRegistrySet::class),
            self::service($container, IntegrationEventConsumerDispatcher::class),
            self::service($container, DurableOutboundAdapterDispatcher::class),
            self::service($container, ProjectionRuntime::class),
            SystemPrincipal::issue($kernelProof, SystemIdentity::Worker),
            self::service($container, RuntimeMaterializationState::class),
        ), true);
        $container->share(OutboxDispatcher::class, static fn (Container $container): OutboxDispatcher =>
            new OutboxDispatcher(
                self::service($container, OutboxStore::class),
                self::service($container, EventContractRegistry::class),
                self::service($container, IntegrationEventTransport::class),
                self::service($container, RetryPolicy::class),
                self::service($container, TrustedRuntimeGenerationGuard::class),
                self::service($container, LoggerInterface::class),
            ), true);
        $container->share(ProcessWorkDispatcher::class, static fn (
            Container $container,
        ): ProcessWorkDispatcher => new ProcessWorkDispatcher(
            self::service($container, ProcessManagerStore::class),
            [new JobQueueProcessWorkHandler(
                self::service($container, JobQueue::class),
                self::service($container, ClockInterface::class),
            )],
            SystemPrincipal::issue($kernelProof, SystemIdentity::Worker),
            self::service($container, RetryPolicy::class),
            self::service($container, TrustedRuntimeGenerationGuard::class),
            self::service($container, TransactionManager::class),
            self::service($container, LoggerInterface::class),
        ), true);
        // Core owns the money conversion contract and ships no rate of any kind, so the catalog reads
        // the contribution registries and stays empty until a package that owns rates is installed.
        $container->share(MoneyConverter::class, new MoneyConverter(), true);
        $container->share(MoneyRateProviderCatalog::class, static fn (
            Container $container,
        ): MoneyRateProviderCatalog => new RuntimeMoneyRateProviderCatalog(
            self::service($container, ExtensionContributionRegistrySet::class),
        ), true);
        $container->share(MoneyConversionPipeline::class, static fn (
            Container $container,
        ): MoneyConversionPipeline => new MoneyConversionPipeline(
            self::service($container, MoneyConverter::class),
            self::service($container, MoneyRateProviderCatalog::class),
        ), true);
        // The unit-of-measure contract is wired the same way and for the same reason: core owns the
        // conversion and ships no table, so the catalog is empty until a package that owns one arrives.
        $container->share(QuantityConverter::class, new QuantityConverter(), true);
        $container->share(UnitConversionProviderCatalog::class, static fn (
            Container $container,
        ): UnitConversionProviderCatalog => new RuntimeUnitConversionProviderCatalog(
            self::service($container, ExtensionContributionRegistrySet::class),
        ), true);
        $container->share(UnitConversionPipeline::class, static fn (
            Container $container,
        ): UnitConversionPipeline => new UnitConversionPipeline(
            self::service($container, QuantityConverter::class),
            self::service($container, UnitConversionProviderCatalog::class),
        ), true);
    }

    /**
     * Register the delivery-neutral generated-business catalog, projection and contract services.
     *
     * Every browser, REST, console and MCP adapter resolves these same shared objects. The composition
     * root supplies only the trusted installed-definition resolver, canonical policy controller and
     * runtime publication state; adapters therefore cannot create an alternate metadata or disclosure
     * path. Core field presenters are registered eagerly so a missing built-in context fails at boot.
     *
     * @param   Container  $container    Container being composed.
     * @param   string     $root         Absolute repository root containing the checked-in core contract.
     * @param   object     $kernelProof  Private provenance for export queue producer contexts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerBusinessSurfaces(Container $container, string $root, object $kernelProof): void
    {
        $reportDefinitions = array_values(array_filter(
            self::service($container, ExtensionContributionRegistrySet::class)->reports()->definitions(),
            static fn (object $definition): bool => $definition instanceof ReportDefinition,
        ));
        $container->share(ReportDefinitionRegistry::class, new ReportDefinitionRegistry($reportDefinitions), true);
        $container->share(ReportApiPresenter::class, new ReportApiPresenter(), true);
        $container->share(ReportScopeResolver::class, static fn (
            Container $container,
        ): ReportScopeResolver => new BusinessRecordReportScopeResolver(
            self::service($container, BusinessRecordDefinitionResolver::class),
        ), true);
        $container->share(RecordExportReportProvider::class, static fn (
            Container $container,
        ): RecordExportReportProvider => new RecordExportReportProvider(
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, DoctrinePersistedFieldTypeDefinitionResolver::class),
        ), true);
        $container->share(ReportService::class, static fn (Container $container): ReportService =>
            new ReportService(
                self::service($container, ReportDefinitionRegistry::class),
                new BusinessRecordServiceReportReader(self::service($container, BusinessRecordService::class)),
                self::service($container, AuthorizationGateway::class),
                self::service($container, ReportScopeResolver::class),
                recordExports: self::service($container, RecordExportReportProvider::class),
            ), true);
        $container->share(ExportArtifactRepository::class, static fn (
            Container $container,
        ): ExportArtifactRepository => new DoctrineExportArtifactRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, TransactionManager::class),
        ), true);
        $container->share(ExportArtifactStorage::class, static fn (): ExportArtifactStorage =>
            new FilesystemExportArtifactStorage(
                $root . '/storage/private/report-exports/objects',
            ), true);
        $container->share(ExportPolicySnapshotProvider::class, static fn (
            Container $container,
        ): ExportPolicySnapshotProvider => new BusinessRecordExportPolicySnapshotProvider(
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, BusinessRecordAccessController::class),
        ), true);
        $container->share(ExportQueueProducerContextProvider::class, new SystemExportQueueProducerContextProvider(
            SystemPrincipal::issue($kernelProof, SystemIdentity::Worker),
        ), true);
        $container->share(ExportJobDispatcher::class, static fn (Container $container): ExportJobDispatcher =>
            new JobQueueExportJobDispatcher(
                self::service($container, JobQueue::class),
                self::service($container, ExportQueueProducerContextProvider::class),
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(ExportExecutionContextResolver::class, static fn (
            Container $container,
        ): ExportExecutionContextResolver => new LiveExportExecutionContextResolver(
            self::service($container, PortalPrincipalLoader::class),
            self::service($container, MembershipDirectory::class),
        ), true);
        $container->share(ExportService::class, static fn (Container $container): ExportService => new ExportService(
            self::service($container, ReportDefinitionRegistry::class),
            self::service($container, ReportScopeResolver::class),
            self::service($container, ExportArtifactRepository::class),
            self::service($container, ExportArtifactStorage::class),
            self::service($container, ExportJobDispatcher::class),
            self::service($container, ExportPolicySnapshotProvider::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
            self::service($container, RecordExportReportProvider::class),
        ), true);
        $container->share(ReportCsvEncoder::class, new ReportCsvEncoder(), true);
        $container->share(ExportGenerationService::class, static fn (
            Container $container,
        ): ExportGenerationService => new ExportGenerationService(
            self::service($container, ExportArtifactRepository::class),
            self::service($container, ExportExecutionContextResolver::class),
            self::service($container, ExportService::class),
            self::service($container, ReportService::class),
            self::service($container, ReportCsvEncoder::class),
            self::service($container, ExportArtifactStorage::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(GenerateReportExportHandler::class, static fn (
            Container $container,
        ): GenerateReportExportHandler => new GenerateReportExportHandler(
            self::service($container, ExportGenerationService::class),
        ), true);

        $container->share(
            FieldPresentationRegistry::class,
            self::service($container, ExtensionContributionRegistrySet::class)->fieldPresentations(),
            true,
        );
        $container->share(FieldModelPresenter::class, static fn (
            Container $container,
        ): FieldModelPresenter => new RegistryFieldModelPresenter(
            self::service($container, FieldPresentationRegistry::class),
        ), true);
        $container->share(BusinessRecordQueryFactory::class, new BusinessRecordQueryFactory(), true);
        $container->share(BusinessRecordProjector::class, new BusinessRecordProjector(), true);
        $container->share(BusinessFormInputMapper::class, new BusinessFormInputMapper(), true);
        $container->share(BusinessCustomViewPresenter::class, static fn (
            Container $container,
        ): BusinessCustomViewPresenter => new BusinessCustomViewPresenter(
            self::service($container, Translator::class),
        ), true);
        $container->share(BusinessDocumentPresenter::class, new BusinessDocumentPresenter(), true);
        $container->share(GeneratedBusinessActionStepUp::class, static fn (
            Container $container,
        ): GeneratedBusinessActionStepUp => new GeneratedBusinessActionStepUp(
            self::service($container, AuthorizationStepUpProofAdapter::class),
            self::service($container, TransactionManager::class),
        ), true);
        $container->share(CustomBusinessSurfaceDispatcher::class, static fn (
            Container $container,
        ): CustomBusinessSurfaceDispatcher => new CustomBusinessSurfaceDispatcher(
            self::service($container, ExtensionContributionRegistrySet::class)->customBusinessViewHandlers(),
            self::service($container, ExtensionContributionRegistrySet::class)->customBusinessActionHandlers(),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(CustomBusinessActionExecutor::class, static fn (
            Container $container,
        ): CustomBusinessActionExecutor => new CustomBusinessActionExecutor(
            self::service($container, CustomBusinessSurfaceDispatcher::class),
            self::service($container, BusinessRecordService::class),
            self::service($container, BusinessRecordIdempotencyRepository::class),
            self::service($container, BusinessRecordMutationFence::class),
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, BusinessRecordAccessController::class),
            self::service($container, RecordFingerprint::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            self::service($container, RuntimeMaterializationState::class),
        ), true);
        $container->share(BusinessSurfaceCatalog::class, static fn (
            Container $container,
        ): BusinessSurfaceCatalog => new BusinessSurfaceCatalog(
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, BusinessRecordAccessController::class),
            self::service($container, FieldTypeRegistry::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, TransactionManager::class),
            self::service($container, RuntimeMaterializationState::class),
            self::service($container, CustomBusinessSurfaceDispatcher::class),
            self::service($container, ActiveLocale::class),
        ), true);
        $container->share(BusinessApprovalSurfaceService::class, static fn (
            Container $container,
        ): BusinessApprovalSurfaceService => new BusinessApprovalSurfaceService(
            self::service($container, ApprovalQueryService::class),
            self::service($container, BusinessSurfaceCatalog::class),
        ), true);
        $container->share(BusinessOperationStatusRepository::class, static fn (
            Container $container,
        ): BusinessOperationStatusRepository => new DoctrineBusinessOperationStatusRepository(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, BusinessRecordIdempotencyRepository::class),
        ), true);
        $container->share(BusinessOperationStatusService::class, static fn (
            Container $container,
        ): BusinessOperationStatusService => new BusinessOperationStatusService(
            self::service($container, BusinessOperationStatusRepository::class),
            self::service($container, TransactionManager::class),
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, BusinessRecordAccessController::class),
            self::service($container, RecordFingerprint::class),
            self::service($container, BusinessSurfaceCatalog::class),
            self::service($container, BusinessRecordProjector::class),
            self::service($container, CustomBusinessSurfaceDispatcher::class),
            self::service($container, RuntimeMaterializationState::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(BusinessSurfaceService::class, static fn (
            Container $container,
        ): BusinessSurfaceService => new BusinessSurfaceService(
            self::service($container, BusinessSurfaceCatalog::class),
            self::service($container, BusinessRecordService::class),
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, FieldTypeRegistry::class),
            self::service($container, BusinessRecordQueryFactory::class),
            self::service($container, BusinessRecordProjector::class),
            self::service($container, CustomBusinessSurfaceDispatcher::class),
            self::service($container, CustomBusinessActionExecutor::class),
            self::service($container, FieldModelPresenter::class),
            self::service($container, MediaService::class),
            self::service($container, TransactionManager::class),
            self::service($container, ActiveLocale::class),
        ), true);
        $container->share(BusinessMutationPlanService::class, static fn (
            Container $container,
        ): BusinessMutationPlanService => new BusinessMutationPlanService(
            self::service($container, BusinessSurfaceCatalog::class),
            self::service($container, BusinessRecordService::class),
            self::service($container, BusinessRecordDefinitionResolver::class),
            self::service($container, BusinessRecordAccessController::class),
            self::service($container, RecordFingerprint::class),
            self::service($container, MutationPlanCipher::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(GeneratedBusinessBrowserController::class, static fn (
            Container $container,
        ): GeneratedBusinessBrowserController => new GeneratedBusinessBrowserController(
            self::service($container, BusinessSurfaceService::class),
            self::service($container, BusinessFormInputMapper::class),
            self::service($container, BusinessOperationStatusService::class),
            self::service($container, BusinessCustomViewPresenter::class),
            self::service($container, BusinessDocumentPresenter::class),
            self::service($container, ReportService::class),
            self::service($container, RecordExportReportProvider::class),
            self::service($container, Translator::class),
        ), true);
        $container->share(OpenApiContractCompiler::class, new OpenApiContractCompiler(), true);
        $container->share(
            OpenApiContractCache::class,
            new FilesystemOpenApiContractCache($root . '/storage/cache/openapi'),
            true,
        );
        $container->share(OpenApiContractService::class, static function (
            Container $container,
        ) use ($root): OpenApiContractService {
            $json = file_get_contents($root . '/api/openapi/kumwe-v1.json');
            if ($json === false) {
                throw new RuntimeException('The checked-in core OpenAPI contract cannot be read.');
            }
            $contract = self::decodeOpenApiObject($json);

            return new OpenApiContractService(
                $contract,
                self::service($container, BusinessSurfaceCatalog::class),
                self::service($container, OpenApiContractCompiler::class),
                self::service($container, OpenApiContractCache::class),
                self::service($container, LoggerInterface::class),
            );
        }, true);
    }

    /**
     * Register every PSR-15 middleware the pipeline and the individual routes select from.
     *
     * Registration order carries no meaning here; `configureApplication()` decides the pipeline
     * order. The idempotency, if-match and CSRF middleware are registered even though only specific
     * routes name them, because a route references middleware by service name and Mezzio resolves it
     * from this container at dispatch time.
     *
     * @param   Container                 $container      Container being composed.
     * @param   ApplicationConfiguration  $configuration  Boot configuration for hosts, proxies and body limits.
     * @param   bool                      $portalEnabled  Whether to register portal boundary middleware.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerMiddleware(
        Container $container,
        ApplicationConfiguration $configuration,
        bool $portalEnabled,
    ): void {
        $container->share(RequestIdMiddleware::class, static fn (Container $container): RequestIdMiddleware =>
            new RequestIdMiddleware(self::service($container, CorrelationContext::class)), true);
        $container->share(MetricsMiddleware::class, static fn (Container $container): MetricsMiddleware =>
            new MetricsMiddleware(self::service($container, MetricRecorder::class)), true);
        $container->share(ProblemDetailsMiddleware::class, static function (
            Container $container,
        ) use ($configuration): ProblemDetailsMiddleware {
            return new ProblemDetailsMiddleware(
                self::service($container, LoggerInterface::class),
                $configuration->debug,
            );
        }, true);
        $container->share(TrustedProxyMiddleware::class, new TrustedProxyMiddleware(
            new TrustedProxyMatcher($configuration->trustedProxies),
        ), true);
        $container->share(TrustedHostMiddleware::class, new TrustedHostMiddleware(
            new TrustedHostMatcher($configuration->trustedHosts),
        ), true);
        $container->share(BodyLimitMiddleware::class, new BodyLimitMiddleware($configuration->maxBodyBytes), true);
        $container->share(ProblemDetailsResponseFactory::class, new ProblemDetailsResponseFactory(), true);
        $container->share(RequireIdempotencyKeyMiddleware::class, static function (
            Container $container,
        ): RequireIdempotencyKeyMiddleware {
            return new RequireIdempotencyKeyMiddleware(
                self::service($container, ProblemDetailsResponseFactory::class),
            );
        }, true);
        $container->share(IdempotencyLedger::class, static fn (
            Container $container,
        ): IdempotencyLedger => new DoctrineIdempotencyLedger(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(SecretOnceIdempotencyLedger::class, static fn (
            Container $container,
        ): SecretOnceIdempotencyLedger => new DoctrineSecretOnceIdempotencyLedger(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(PersistentIdempotencyMiddleware::class, static fn (
            Container $container,
        ): PersistentIdempotencyMiddleware => new PersistentIdempotencyMiddleware(
            self::service($container, IdempotencyLedger::class),
            self::service($container, ClockInterface::class),
            self::service($container, ProblemDetailsResponseFactory::class),
            self::service($container, TransactionManager::class),
            new HttpMutationPreauthorizer(
                self::service($container, AuthorizationGateway::class),
                self::service($container, ContentService::class),
                self::service($container, AccessControlRepository::class),
                self::service($container, TokenDelegationPreauthorizer::class),
                self::service($container, TokenRotationPreauthorizer::class),
                self::service($container, ContentModelRepository::class),
            ),
        ), true);
        $container->share(SecretOnceIdempotencyMiddleware::class, static fn (
            Container $container,
        ): SecretOnceIdempotencyMiddleware => new SecretOnceIdempotencyMiddleware(
            self::service($container, SecretOnceIdempotencyLedger::class),
            self::service($container, ProblemDetailsResponseFactory::class),
            new HttpMutationPreauthorizer(
                self::service($container, AuthorizationGateway::class),
                self::service($container, ContentService::class),
                self::service($container, AccessControlRepository::class),
                self::service($container, TokenDelegationPreauthorizer::class),
                self::service($container, TokenRotationPreauthorizer::class),
                self::service($container, ContentModelRepository::class),
            ),
            self::service($container, TransactionManager::class),
        ), true);
        $container->share(RequireIfMatchMiddleware::class, static function (
            Container $container,
        ): RequireIfMatchMiddleware {
            return new RequireIfMatchMiddleware(
                self::service($container, ProblemDetailsResponseFactory::class),
            );
        }, true);
        $container->share(AdministratorSessionMiddleware::class, static fn (
            Container $container,
        ): AdministratorSessionMiddleware => new AdministratorSessionMiddleware(
            self::service($container, AdministratorSessionStore::class),
            self::service($container, AuthorizationGateway::class),
            SiteContext::fromString($configuration->publicSite),
        ), true);
        $container->share(AdministratorAuthorizationMiddleware::class, static fn (
            Container $container,
        ): AdministratorAuthorizationMiddleware => new AdministratorAuthorizationMiddleware(
            self::service($container, AdministratorRenderer::class),
        ), true);
        $container->share(AdministratorCsrfMiddleware::class, static fn (
            Container $container,
        ): AdministratorCsrfMiddleware => new AdministratorCsrfMiddleware(
            self::service($container, Translator::class),
            self::service($container, ActiveLocale::class),
        ), true);
        if ($portalEnabled) {
            $container->share(PortalSessionMiddleware::class, static fn (
                Container $container,
            ): PortalSessionMiddleware => new PortalSessionMiddleware(
                self::service($container, PortalSessionStore::class),
                self::service($container, PortalExecutionContextFactory::class),
                self::service($container, AuthorizationGateway::class),
            ), true);
            $container->share(PortalAuthorizationMiddleware::class, static fn (
                Container $container,
            ): PortalAuthorizationMiddleware => new PortalAuthorizationMiddleware(
                self::service($container, AuthorizationGateway::class),
            ), true);
            $container->share(PortalCsrfMiddleware::class, static fn (
                Container $container,
            ): PortalCsrfMiddleware => new PortalCsrfMiddleware(
                self::service($container, Translator::class),
                self::service($container, ActiveLocale::class),
            ), true);
        }
        $container->share(BearerAuthenticationMiddleware::class, static function (
            Container $container,
        ): BearerAuthenticationMiddleware {
            return new BearerAuthenticationMiddleware(self::service($container, AccessTokenVerifier::class));
        }, true);
        $container->share(SecurityHeadersMiddleware::class, new SecurityHeadersMiddleware(
            $configuration->isProduction(),
        ), true);
        $container->share(RouteMiddleware::class, static fn (Container $container): RouteMiddleware =>
            new RouteMiddleware(self::service($container, RouterInterface::class)), true);
        $container->share(ImplicitHeadMiddleware::class, static fn (Container $container): ImplicitHeadMiddleware =>
            new ImplicitHeadMiddleware(
                self::service($container, RouterInterface::class),
                self::service($container, StreamFactoryInterface::class),
            ), true);
        $container->share(ImplicitOptionsMiddleware::class, static function (
            Container $container,
        ): ImplicitOptionsMiddleware {
            return new ImplicitOptionsMiddleware(
                self::service($container, ResponseFactoryInterface::class),
            );
        }, true);
        $container->share(MethodNotAllowedMiddleware::class, static function (
            Container $container,
        ): MethodNotAllowedMiddleware {
            return new MethodNotAllowedMiddleware(
                self::service($container, ResponseFactoryInterface::class),
            );
        }, true);
        $container->share(DispatchMiddleware::class, new DispatchMiddleware(), true);
    }

    /**
     * Register every request handler, presenter and responder the routes dispatch to.
     *
     * Administrator cookie security is derived here from the configured base URL scheme, so an
     * installation served over plain HTTP still issues a usable session cookie while an HTTPS
     * deployment gets a secure one without a second configuration switch.
     *
     * @param   Container                 $container      Container being composed.
     * @param   ApplicationConfiguration  $configuration  Boot configuration for base URL, site and limits.
     * @param   string                    $root           Absolute path of the repository root.
     * @param   bool                      $portalEnabled  Whether to register ordinary-user portal handlers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerHandlers(
        Container $container,
        ApplicationConfiguration $configuration,
        string $root,
        bool $portalEnabled,
    ): void {
        $container->share(ContentLayoutCatalog::class, static fn (
            Container $container,
        ): ContentLayoutCatalog => new ContentLayoutCatalog(
            self::service($container, ContentModelRepository::class),
            self::service($container, ApplicationConfiguration::class)->publicSite,
        ), true);
        $container->share(HomePageHandler::class, static fn (Container $container): HomePageHandler =>
            new HomePageHandler(
                self::service($container, PublicPageLocator::class),
                self::service($container, SiteSettings::class),
                self::service($container, SiteRenderer::class),
                self::service($container, ContentPresenter::class),
                self::service($container, ContentLayoutCatalog::class),
                self::service($container, TranslationGroupPresenter::class),
                self::service($container, ActiveLocale::class),
            ), true);
        $container->share(LivenessHandler::class, new LivenessHandler(), true);
        $container->share(MetricsHandler::class, static fn (Container $container): MetricsHandler =>
            new MetricsHandler(
                self::service($container, MetricsAccessPolicy::class),
                self::service($container, MetricCatalog::class),
                self::service($container, MetricRecorder::class),
                self::service($container, RuntimeMetricCollector::class),
                self::service($container, PrometheusExposition::class),
            ), true);
        $container->share(ApiIndexHandler::class, new ApiIndexHandler(), true);
        $container->share(NotFoundHandler::class, new NotFoundHandler(), true);
        $container->share(ReadinessHandler::class, static fn (Container $container): ReadinessHandler =>
            new ReadinessHandler(new LocalRuntimeReadinessProbe(
                self::service($container, ExtensionRuntimeMapCompiler::class),
            )), true);
        $container->share(RobotsHandler::class, static fn (Container $container): RobotsHandler =>
            new RobotsHandler(self::service($container, SiteSettings::class)), true);
        $container->share(SafePlanFactory::class, static fn (Container $container): SafePlanFactory =>
            new SafePlanFactory(self::service($container, ClockInterface::class)), true);
        $container->share(PlanPreviewHandler::class, static fn (Container $container): PlanPreviewHandler =>
            new PlanPreviewHandler(
                self::service($container, SafePlanFactory::class),
                self::service($container, ProblemDetailsResponseFactory::class),
            ), true);
        $container->share(ContentApiResponder::class, static fn (Container $container): ContentApiResponder =>
            new ContentApiResponder(self::service($container, ProblemDetailsResponseFactory::class)), true);
        $container->share(ContentCollectionHandler::class, static fn (
            Container $container,
        ): ContentCollectionHandler => new ContentCollectionHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentApiResponder::class),
        ), true);
        $container->share(ContentItemHandler::class, static fn (
            Container $container,
        ): ContentItemHandler => new ContentItemHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentApiResponder::class),
        ), true);
        $container->share(ContentModelApiHandler::class, static fn (
            Container $container,
        ): ContentModelApiHandler => new ContentModelApiHandler(
            self::service($container, ContentModelService::class),
            self::service($container, ContentApiResponder::class),
        ), true);
        $container->share(ContentTransitionHandler::class, static fn (
            Container $container,
        ): ContentTransitionHandler => new ContentTransitionHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentApiResponder::class),
        ), true);
        $container->share(ContentRestoreHandler::class, static fn (
            Container $container,
        ): ContentRestoreHandler => new ContentRestoreHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentApiResponder::class),
        ), true);
        $container->share(PublishedContentHandler::class, static fn (
            Container $container,
        ): PublishedContentHandler => new PublishedContentHandler(
            self::service($container, PublicPageLocator::class),
            self::service($container, SiteSettings::class),
            self::service($container, SiteRenderer::class),
            self::service($container, ContentPresenter::class),
            self::service($container, ContentLayoutCatalog::class),
            self::service($container, TranslationGroupPresenter::class),
            self::service($container, ActiveLocale::class),
        ), true);
        $container->share(ExtensionAssetHandler::class, static fn (
            Container $container,
        ): ExtensionAssetHandler => new ExtensionAssetHandler(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
            self::service($container, StreamFactoryInterface::class),
            $root . '/public/assets/extensions',
        ), true);
        $container->share(MediaAssetHandler::class, static fn (
            Container $container,
        ): MediaAssetHandler => new MediaAssetHandler(
            self::service($container, MediaStorage::class),
            self::service($container, StreamFactoryInterface::class),
            SiteContext::fromString($configuration->publicSite),
        ), true);
        $configuration = self::service($container, ApplicationConfiguration::class);
        $secureCookie = parse_url($configuration->baseUrl, PHP_URL_SCHEME) === 'https';
        $container->share(AdministratorLoginHandler::class, static fn (
            Container $container,
        ): AdministratorLoginHandler => new AdministratorLoginHandler(
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, AdministratorSessionStore::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, Translator::class),
            $secureCookie,
            $configuration->administratorSessionSeconds,
            SiteContext::fromString($configuration->publicSite),
        ), true);
        $container->share(AdministratorLogoutHandler::class, static fn (
            Container $container,
        ): AdministratorLogoutHandler => new AdministratorLogoutHandler(
            self::service($container, AdministratorSessionStore::class),
            $secureCookie,
        ), true);
        if ($portalEnabled) {
            $container->share(PortalLoginHandler::class, static fn (
                Container $container,
            ): PortalLoginHandler => new PortalLoginHandler(
                self::service($container, PortalAuthenticator::class),
                self::service($container, PortalContextResolver::class),
                self::service($container, PortalSessionStore::class),
                self::service($container, PortalRenderer::class),
                self::service($container, Translator::class),
                $secureCookie,
                $configuration->administratorSessionSeconds,
            ), true);
            $container->share(PortalLogoutHandler::class, static fn (
                Container $container,
            ): PortalLogoutHandler => new PortalLogoutHandler(
                self::service($container, PortalSessionStore::class),
                $secureCookie,
            ), true);
            $container->share(PortalHomeHandler::class, static fn (
                Container $container,
            ): PortalHomeHandler => new PortalHomeHandler(
                self::service($container, PortalRenderer::class),
                self::service($container, DashboardComposer::class),
                self::service($container, DashboardPreferenceService::class),
                self::service($container, DashboardPreferenceFormPresenter::class),
                self::service($container, DashboardPreferenceQueryDecoder::class),
            ), true);
            $container->share(PortalDashboardPreferencesHandler::class, static fn (
                Container $container,
            ): PortalDashboardPreferencesHandler => new PortalDashboardPreferencesHandler(
                self::service($container, DashboardPreferenceService::class),
                self::service($container, DashboardPreferenceFormDecoder::class),
                self::service($container, DashboardPreferenceQueryDecoder::class),
                self::service($container, PortalRenderer::class),
            ), true);
            $container->share(PortalSecurityHandler::class, static fn (
                Container $container,
            ): PortalSecurityHandler => new PortalSecurityHandler(
                self::service($container, StepUpProvider::class),
                self::service($container, PortalRenderer::class),
                self::service($container, Translator::class),
                $secureCookie,
                $configuration->administratorSessionSeconds,
            ), true);
            $container->share(PortalApprovalHandler::class, static fn (
                Container $container,
            ): PortalApprovalHandler => new PortalApprovalHandler(
                self::service($container, BusinessApprovalSurfaceService::class),
                self::service($container, ApprovalService::class),
                self::service($container, StepUpProvider::class),
                self::service($container, AuthorizationStepUpProofAdapter::class),
                self::service($container, TransactionManager::class),
                self::service($container, PortalRenderer::class),
                self::service($container, Translator::class),
                $secureCookie,
                $configuration->administratorSessionSeconds,
            ), true);
            $container->share(PortalReportHandler::class, static fn (
                Container $container,
            ): PortalReportHandler => new PortalReportHandler(
                self::service($container, ReportService::class),
                self::service($container, ExportService::class),
                self::service($container, ReportApiPresenter::class),
                self::service($container, PortalRenderer::class),
                self::service($container, StreamFactoryInterface::class),
            ), true);
        }
        $container->share(AdministratorDashboardHandler::class, static fn (
            Container $container,
        ): AdministratorDashboardHandler => new AdministratorDashboardHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentModelService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, DashboardComposer::class),
            self::service($container, DashboardPreferenceService::class),
            self::service($container, DashboardPreferenceFormPresenter::class),
            self::service($container, DashboardPreferenceQueryDecoder::class),
        ), true);
        $container->share(AdministratorDashboardPreferencesHandler::class, static fn (
            Container $container,
        ): AdministratorDashboardPreferencesHandler => new AdministratorDashboardPreferencesHandler(
            self::service($container, DashboardPreferenceService::class),
            self::service($container, DashboardPreferenceFormDecoder::class),
            self::service($container, DashboardPreferenceQueryDecoder::class),
            self::service($container, AdministratorRenderer::class),
        ), true);
        $container->share(AdministratorInterfaceStandardHandler::class, static fn (
            Container $container,
        ): AdministratorInterfaceStandardHandler => new AdministratorInterfaceStandardHandler(
            self::service($container, AdministratorRenderer::class),
        ), true);
        $container->share(AdministratorReportHandler::class, static fn (
            Container $container,
        ): AdministratorReportHandler => new AdministratorReportHandler(
            self::service($container, ReportService::class),
            self::service($container, ExportService::class),
            self::service($container, ReportApiPresenter::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, StreamFactoryInterface::class),
        ), true);
        $container->share(AdministratorContentListHandler::class, static fn (
            Container $container,
        ): AdministratorContentListHandler => new AdministratorContentListHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentModelService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, PublicPageLocator::class),
        ), true);
        $container->share(BusinessDefinitionsHandler::class, static fn (
            Container $container,
        ): BusinessDefinitionsHandler => new BusinessDefinitionsHandler(
            self::service($container, BusinessDefinitionService::class),
            new BusinessDefinitionFormMapper(),
            self::service($container, ExtensionContributionRegistrySet::class)->fieldTypes(),
            self::service($container, AdministratorRenderer::class),
        ), true);
        $container->share(
            BusinessDefinitionApiPresenter::class,
            static fn (): BusinessDefinitionApiPresenter => new BusinessDefinitionApiPresenter(),
            true,
        );
        $container->share(
            BusinessSchemaApiPresenter::class,
            static fn (): BusinessSchemaApiPresenter => new BusinessSchemaApiPresenter(),
            true,
        );
        $container->share(BusinessApiResponder::class, static fn (
            Container $container,
        ): BusinessApiResponder => new BusinessApiResponder(
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(BusinessRecordApiPresenter::class, static fn (
            Container $container,
        ): BusinessRecordApiPresenter => new BusinessRecordApiPresenter(
            self::service($container, BusinessRecordProjector::class),
        ), true);
        $container->share(BusinessRecordApiResponder::class, static fn (
            Container $container,
        ): BusinessRecordApiResponder => new BusinessRecordApiResponder(
            self::service($container, BusinessRecordApiPresenter::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(BusinessRecordApiHandler::class, static fn (
            Container $container,
        ): BusinessRecordApiHandler => new BusinessRecordApiHandler(
            self::service($container, BusinessRecordService::class),
            self::service($container, BusinessRecordQueryFactory::class),
            self::service($container, BusinessRecordApiResponder::class),
            self::service($container, BusinessSurfaceCatalog::class),
            self::service($container, BusinessSurfaceService::class),
        ), true);
        $container->share(ReportApiHandler::class, static fn (
            Container $container,
        ): ReportApiHandler => new ReportApiHandler(
            self::service($container, ReportService::class),
            self::service($container, ExportService::class),
            self::service($container, ReportApiPresenter::class),
            self::service($container, StreamFactoryInterface::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(BusinessOperationStatusApiHandler::class, static fn (
            Container $container,
        ): BusinessOperationStatusApiHandler => new BusinessOperationStatusApiHandler(
            self::service($container, BusinessOperationStatusService::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(
            BusinessApprovalApiPresenter::class,
            new BusinessApprovalApiPresenter(),
            true,
        );
        $container->share(BusinessApprovalApiHandler::class, static fn (
            Container $container,
        ): BusinessApprovalApiHandler => new BusinessApprovalApiHandler(
            self::service($container, BusinessApprovalSurfaceService::class),
            self::service($container, BusinessApprovalApiPresenter::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(BusinessDefinitionDiscoveryApiHandler::class, static fn (
            Container $container,
        ): BusinessDefinitionDiscoveryApiHandler => new BusinessDefinitionDiscoveryApiHandler(
            self::service($container, BusinessSurfaceCatalog::class),
            self::service($container, BusinessRecordApiResponder::class),
        ), true);
        $container->share(OpenApiHandler::class, static fn (Container $container): OpenApiHandler =>
            new OpenApiHandler(
                self::service($container, OpenApiContractService::class),
                self::service($container, ProblemDetailsResponseFactory::class),
            ), true);
        $container->share(AdministratorBusinessSurfaceHandler::class, static fn (
            Container $container,
        ): AdministratorBusinessSurfaceHandler => new AdministratorBusinessSurfaceHandler(
            self::service($container, GeneratedBusinessBrowserController::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, AdministratorStepUpProvider::class),
            self::service($container, GeneratedBusinessActionStepUp::class),
            self::service($container, Translator::class),
            $secureCookie,
            $configuration->administratorSessionSeconds,
        ), true);
        if ($portalEnabled) {
            $container->share(PortalBusinessSurfaceHandler::class, static fn (
                Container $container,
            ): PortalBusinessSurfaceHandler => new PortalBusinessSurfaceHandler(
                self::service($container, GeneratedBusinessBrowserController::class),
                self::service($container, PortalRenderer::class),
                self::service($container, StepUpProvider::class),
                self::service($container, GeneratedBusinessActionStepUp::class),
                self::service($container, Translator::class),
                $secureCookie,
                $configuration->administratorSessionSeconds,
            ), true);
        }
        $container->share(BusinessDefinitionApiHandler::class, static fn (
            Container $container,
        ): BusinessDefinitionApiHandler => new BusinessDefinitionApiHandler(
            self::service($container, BusinessDefinitionService::class),
            self::service($container, BusinessDefinitionApiPresenter::class),
            self::service($container, BusinessApiResponder::class),
        ), true);
        $container->share(BusinessSchemaApiHandler::class, static fn (
            Container $container,
        ): BusinessSchemaApiHandler => new BusinessSchemaApiHandler(
            self::service($container, BusinessSchemaService::class),
            self::service($container, BusinessSchemaApiPresenter::class),
            self::service($container, BusinessApiResponder::class),
            self::service($container, HighImpactCredentialGuard::class),
        ), true);
        $container->share(PostingPeriodApiHandler::class, static fn (
            Container $container,
        ): PostingPeriodApiHandler => new PostingPeriodApiHandler(
            self::service($container, PostingPeriodService::class),
            self::service($container, BusinessApiResponder::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(BusinessSchemaPlansHandler::class, static fn (
            Container $container,
        ): BusinessSchemaPlansHandler => new BusinessSchemaPlansHandler(
            self::service($container, BusinessSchemaService::class),
            self::service($container, BusinessSchemaEnvironment::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, Translator::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(CreateBusinessSchemaPlanHandler::class, static fn (
            Container $container,
        ): CreateBusinessSchemaPlanHandler => new CreateBusinessSchemaPlanHandler(
            self::service($container, BusinessSchemaService::class),
        ), true);
        $container->share(CreateBusinessSchemaPurgePlanHandler::class, static fn (
            Container $container,
        ): CreateBusinessSchemaPurgePlanHandler => new CreateBusinessSchemaPurgePlanHandler(
            self::service($container, BusinessSchemaService::class),
            self::service($container, HighImpactCredentialGuard::class),
        ), true);
        $container->share(ApproveBusinessSchemaPlanHandler::class, static fn (
            Container $container,
        ): ApproveBusinessSchemaPlanHandler => new ApproveBusinessSchemaPlanHandler(
            self::service($container, BusinessSchemaService::class),
            self::service($container, HighImpactCredentialGuard::class),
        ), true);
        $container->share(ExecuteBusinessSchemaPlanHandler::class, static fn (
            Container $container,
        ): ExecuteBusinessSchemaPlanHandler => new ExecuteBusinessSchemaPlanHandler(
            self::service($container, BusinessSchemaService::class),
        ), true);
        $container->share(RecoverBusinessSchemaPlanHandler::class, static fn (
            Container $container,
        ): RecoverBusinessSchemaPlanHandler => new RecoverBusinessSchemaPlanHandler(
            self::service($container, BusinessSchemaService::class),
        ), true);
        $container->share(RecordBusinessSchemaRecoveryEvidenceHandler::class, static fn (
            Container $container,
        ): RecordBusinessSchemaRecoveryEvidenceHandler => new RecordBusinessSchemaRecoveryEvidenceHandler(
            self::service($container, BusinessSchemaService::class),
            self::service($container, BusinessSchemaEnvironment::class),
            self::service($container, HighImpactCredentialGuard::class),
        ), true);
        $container->share(AdministratorContentEditorHandler::class, static fn (
            Container $container,
        ): AdministratorContentEditorHandler => new AdministratorContentEditorHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentModelService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, ContentFormPresenter::class),
            self::service($container, MediaService::class),
            self::service($container, PublicPageLocator::class),
        ), true);
        $container->share(AdministratorMediaHandler::class, static fn (
            Container $container,
        ): AdministratorMediaHandler => new AdministratorMediaHandler(
            self::service($container, MediaService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, Translator::class),
            $root . '/storage/tmp',
        ), true);
        $container->share(AdministratorContentModelsHandler::class, static fn (
            Container $container,
        ): AdministratorContentModelsHandler => new AdministratorContentModelsHandler(
            self::service($container, ContentModelService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, ContentModelFormMapper::class),
            self::service($container, ContentModelFormPresenter::class),
        ), true);
        $container->share(AdministratorCreateContentHandler::class, static fn (
            Container $container,
        ): AdministratorCreateContentHandler => new AdministratorCreateContentHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentModelService::class),
            self::service($container, ContentFormDataMapper::class),
            self::service($container, AdministratorContentEditorHandler::class),
        ), true);
        $container->share(AdministratorUpdateContentHandler::class, static fn (
            Container $container,
        ): AdministratorUpdateContentHandler => new AdministratorUpdateContentHandler(
            self::service($container, ContentService::class),
            self::service($container, ContentModelService::class),
            self::service($container, ContentFormDataMapper::class),
            self::service($container, AdministratorContentEditorHandler::class),
        ), true);
        $container->share(AdministratorTransitionContentHandler::class, static fn (
            Container $container,
        ): AdministratorTransitionContentHandler => new AdministratorTransitionContentHandler(
            self::service($container, ContentService::class),
        ), true);
        $container->share(AdministratorTrashContentHandler::class, static fn (
            Container $container,
        ): AdministratorTrashContentHandler => new AdministratorTrashContentHandler(
            self::service($container, ContentService::class),
        ), true);
        $container->share(AdministratorRestoreContentHandler::class, static fn (
            Container $container,
        ): AdministratorRestoreContentHandler => new AdministratorRestoreContentHandler(
            self::service($container, ContentService::class),
        ), true);
        $container->share(AdministratorExtensionsHandler::class, static fn (
            Container $container,
        ): AdministratorExtensionsHandler => new AdministratorExtensionsHandler(
            self::service($container, ExtensionManager::class),
            self::service($container, TrustStore::class),
            self::service($container, AdministratorRenderer::class),
            dirname(__DIR__, 2) . '/storage/tmp',
            self::service($container, RevocationFeedSynchronizer::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(AdministratorExtensionActionHandler::class, static fn (
            Container $container,
        ): AdministratorExtensionActionHandler => new AdministratorExtensionActionHandler(
            self::service($container, ExtensionManager::class),
            self::service($container, TrustStore::class),
        ), true);
        $container->share(AdministratorSettingsHandler::class, static fn (
            Container $container,
        ): AdministratorSettingsHandler => new AdministratorSettingsHandler(
            self::service($container, SiteSettings::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, SitePresentationFormMapper::class),
            self::service($container, ContentService::class),
            self::service($container, MediaService::class),
            self::service($container, NavigationService::class),
        ), true);
        $container->share(AdministratorWordingHandler::class, static fn (
            Container $container,
        ): AdministratorWordingHandler => new AdministratorWordingHandler(
            self::service($container, MessageOverrideService::class),
            self::service($container, SupportedLocales::class),
            self::service($container, AdministratorRenderer::class),
        ), true);
        $container->share(AdministratorNavigationHandler::class, static fn (
            Container $container,
        ): AdministratorNavigationHandler => new AdministratorNavigationHandler(
            self::service($container, NavigationService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, ContentService::class),
            self::service($container, SiteSettings::class),
        ), true);
        $container->share(AdministratorAccessControlHandler::class, static fn (
            Container $container,
        ): AdministratorAccessControlHandler => new AdministratorAccessControlHandler(
            self::service($container, AccessControlService::class),
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, AdministratorSessionStore::class),
            self::service($container, MembershipDirectory::class),
            self::service($container, AdministratorStepUpProvider::class),
            self::service($container, AuthorizationStepUpProofAdapter::class),
            self::service($container, StepUpProofConsumer::class),
            self::service($container, TransactionManager::class),
            self::service($container, ClockInterface::class),
            $secureCookie,
            $configuration->administratorSessionSeconds,
        ), true);
        $container->share(AdministratorBusinessSecurityHandler::class, static fn (
            Container $container,
        ): AdministratorBusinessSecurityHandler => new AdministratorBusinessSecurityHandler(
            self::service($container, BusinessSecurityAdministrationService::class),
            self::service($container, ApprovalService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, Translator::class),
            self::service($container, AdministratorStepUpProvider::class),
            self::service($container, AuthorizationStepUpProofAdapter::class),
            self::service($container, TransactionManager::class),
            $secureCookie,
            $configuration->administratorSessionSeconds,
        ), true);
        $container->share(AdministratorAutomationHandler::class, static fn (
            Container $container,
        ): AdministratorAutomationHandler => new AdministratorAutomationHandler(
            self::service($container, AutomationManagementService::class),
            self::service($container, AdministratorRenderer::class),
            self::service($container, AutomationJobFormRegistry::class),
        ), true);
        $container->share(NavigationApiResponder::class, static fn (
            Container $container,
        ): NavigationApiResponder => new NavigationApiResponder(
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(MenuCollectionHandler::class, static fn (Container $container): MenuCollectionHandler =>
            new MenuCollectionHandler(
                self::service($container, NavigationService::class),
                self::service($container, NavigationApiResponder::class),
            ), true);
        $container->share(MenuResourceHandler::class, static fn (Container $container): MenuResourceHandler =>
            new MenuResourceHandler(
                self::service($container, NavigationService::class),
                self::service($container, NavigationApiResponder::class),
            ), true);
        $container->share(MenuItemCollectionHandler::class, static fn (
            Container $container,
        ): MenuItemCollectionHandler => new MenuItemCollectionHandler(
            self::service($container, NavigationService::class),
            self::service($container, NavigationApiResponder::class),
        ), true);
        $container->share(MenuItemResourceHandler::class, static fn (
            Container $container,
        ): MenuItemResourceHandler => new MenuItemResourceHandler(
            self::service($container, NavigationService::class),
            self::service($container, NavigationApiResponder::class),
        ), true);
        $container->share(AccessControlApiHandler::class, static fn (
            Container $container,
        ): AccessControlApiHandler => new AccessControlApiHandler(
            self::service($container, AccessControlService::class),
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(SiteSettingsApiHandler::class, static fn (
            Container $container,
        ): SiteSettingsApiHandler => new SiteSettingsApiHandler(
            self::service($container, SiteSettings::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(ExtensionApiHandler::class, static fn (
            Container $container,
        ): ExtensionApiHandler => new ExtensionApiHandler(
            self::service($container, ExtensionManager::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(TrustStoreApiHandler::class, static fn (
            Container $container,
        ): TrustStoreApiHandler => new TrustStoreApiHandler(
            self::service($container, TrustStore::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(TrustLifecycleMiddleware::class, static fn (
            Container $container,
        ): TrustLifecycleMiddleware => new TrustLifecycleMiddleware(
            self::service($container, TrustStore::class),
        ), true);
        $container->share(AutomationApiHandler::class, static fn (
            Container $container,
        ): AutomationApiHandler => new AutomationApiHandler(
            self::service($container, AutomationManagementService::class),
            self::service($container, ProblemDetailsResponseFactory::class),
        ), true);
        $container->share(McpHttpHandler::class, static function (
            Container $container,
        ): McpHttpHandler {
            $configuration = self::service($container, ApplicationConfiguration::class);
            $host = parse_url($configuration->baseUrl, PHP_URL_HOST);

            if (!is_string($host) || $host === '') {
                throw new RuntimeException('The configured Kumwe base URL has no usable MCP host.');
            }

            return new McpHttpHandler(
                self::service($container, KumweMcpServerFactory::class),
                self::service($container, KumweMcpHandlers::class),
                self::service($container, ResponseFactoryInterface::class),
                self::service($container, StreamFactoryInterface::class),
                self::service($container, LoggerInterface::class),
                $configuration->maxBodyBytes,
                [$host],
            );
        }, true);
    }

    /**
     * Pipe the middleware and declare every route the application answers.
     *
     * Pipeline order is the security contract: request identity and problem details wrap everything,
     * the trusted proxy and host filters run before the body limit and the security headers, and
     * routing, session, authorization and bearer authentication all precede dispatch. Routes are
     * declared core first, then the routes contributed by active extensions, then the catch-all
     * published-content route, so an extension can add a path but never shadow a core one.
     *
     * Locale negotiation sits immediately after request identity, ahead of the error boundary, so a
     * problem document manufactured for a rejected request is rendered in the caller's language
     * rather than in whatever language the previous request left behind in a long-lived process.
     *
     * Metric recording sits between request identity and the error boundary, which is the only place
     * it counts every response including the ones the error boundary manufactured. The `/metrics`
     * route is declared unconditionally so the compiled route graph never depends on a deployment
     * flag; whether it answers is `MetricsAccessPolicy`'s decision, taken per request.
     *
     * @param   Application  $application    Mezzio application to pipe middleware into and route.
     * @param   Container    $container      Container the application resolves handlers from.
     * @param   bool         $portalEnabled  Whether to pipe and declare the ordinary-user portal boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function configureApplication(
        Application $application,
        Container $container,
        bool $portalEnabled,
    ): void {
        $application->pipe(RequestIdMiddleware::class);
        $application->pipe(LocaleNegotiationMiddleware::class);
        $application->pipe(MetricsMiddleware::class);
        $application->pipe(ProblemDetailsMiddleware::class);
        $application->pipe(TrustedProxyMiddleware::class);
        $application->pipe(TrustedHostMiddleware::class);
        $application->pipe(BodyLimitMiddleware::class);
        $application->pipe(SecurityHeadersMiddleware::class);
        $application->pipe(RouteMiddleware::class);
        $application->pipe(ImplicitHeadMiddleware::class);
        $application->pipe(ImplicitOptionsMiddleware::class);
        $application->pipe(MethodNotAllowedMiddleware::class);
        $application->pipe(AdministratorSessionMiddleware::class);
        // A trusted administrator session may be denied by the route gate below, which renders a
        // localized HTML response itself. Enrich the scope before that early-return boundary.
        $application->pipe(TranslationScopeMiddleware::class);
        $application->pipe(AdministratorAuthorizationMiddleware::class);
        if ($portalEnabled) {
            $application->pipe(PortalSessionMiddleware::class);
            // The portal route gate can also return before dispatch once a session is trusted.
            $application->pipe(TranslationScopeMiddleware::class);
            $application->pipe(PortalAuthorizationMiddleware::class);
        }
        $application->pipe(BearerAuthenticationMiddleware::class);
        // Bearer identity is the last authentication source; this pass covers API dispatch while the
        // earlier passes cover localized responses produced by administrator and portal authorization.
        $application->pipe(TranslationScopeMiddleware::class);
        $application->pipe(DispatchMiddleware::class);
        $application->pipe(NotFoundHandler::class);

        $application->get('/', HomePageHandler::class, 'site.home');
        $application->get('/health/live', LivenessHandler::class, 'health.live');
        $application->get('/health/ready', ReadinessHandler::class, 'health.ready');
        $application->get('/metrics', MetricsHandler::class, 'observability.metrics');
        $application->get('/robots.txt', RobotsHandler::class, 'site.robots');
        $application->route(
            '/administrator/login',
            AdministratorLoginHandler::class,
            ['GET', 'POST'],
            'administrator.login',
        );
        if ($portalEnabled) {
            $application->route('/portal/login', PortalLoginHandler::class, ['GET', 'POST'], 'portal.login');
            self::portalRoute($application->get('/portal', PortalHomeHandler::class, 'portal.index'), 'portal.access');
            self::portalRoute($application->post(
                '/portal/dashboard/preferences',
                [PortalCsrfMiddleware::class, PortalDashboardPreferencesHandler::class],
                'portal.dashboard.preferences',
            ), 'portal.access');
            self::portalRoute($application->post(
                '/portal/logout',
                [PortalCsrfMiddleware::class, PortalLogoutHandler::class],
                'portal.logout',
            ), 'portal.access');
            self::portalRoute(
                $application->get('/portal/security', PortalSecurityHandler::class, 'portal.security'),
                'portal.access',
            );
            foreach (
                [
                    ['/portal/security/totp/enroll', 'portal.security.totp.enroll'],
                    ['/portal/security/totp/confirm', 'portal.security.totp.confirm'],
                    ['/portal/security/challenge', 'portal.security.challenge'],
                    ['/portal/security/recovery', 'portal.security.recovery'],
                ] as [$path, $name]
            ) {
                self::portalRoute($application->post(
                    $path,
                    [PortalCsrfMiddleware::class, PortalSecurityHandler::class],
                    $name,
                ), 'portal.access');
            }
            self::portalRoute(
                $application->get('/portal/approvals', PortalApprovalHandler::class, 'portal.approvals'),
                'portal.access',
            );
            self::portalRoute(
                $application->get(
                    '/portal/approvals/{id}',
                    PortalApprovalHandler::class,
                    'portal.approvals.detail',
                ),
                'portal.access',
            );
            foreach (['approve', 'reject', 'revoke'] as $decision) {
                self::portalRoute($application->post(
                    '/portal/approvals/{id}/' . $decision,
                    [PortalCsrfMiddleware::class, PortalApprovalHandler::class],
                    'portal.approvals.' . $decision,
                ), 'portal.access');
            }
            self::portalRoute($application->get(
                '/portal/reports',
                PortalReportHandler::class,
                'portal.reports',
            ), 'portal.access');
            self::portalRoute($application->post(
                '/portal/reports/{report}',
                [PortalCsrfMiddleware::class, PortalReportHandler::class],
                'portal.reports.execute',
            ), 'portal.access');
            self::portalRoute($application->post(
                '/portal/reports/{report}/exports',
                [PortalCsrfMiddleware::class, PortalReportHandler::class],
                'portal.reports.export',
            ), 'portal.access');
            self::portalRoute($application->get(
                '/portal/reports/exports/{artifact}',
                PortalReportHandler::class,
                'portal.report-exports.read',
            ), 'portal.access');
            self::portalRoute($application->get(
                '/portal/reports/exports/{artifact}/download',
                PortalReportHandler::class,
                'portal.report-exports.download',
            ), 'portal.access');
            self::portalRoute(
                $application->get(
                    '/portal/business/operations/{operation}',
                    PortalBusinessSurfaceHandler::class,
                    'portal.business.operation',
                ),
                'portal.access',
            );
            self::portalRoute(
                $application->get(
                    '/portal/business/{definition}/{record}/relationships/{business_relationship}',
                    PortalBusinessSurfaceHandler::class,
                    'portal.business.relationship',
                ),
                'portal.access',
            );
            self::portalRoute($application->post(
                '/portal/business/{definition}/{record}/relationships/{business_relationship}',
                [PortalCsrfMiddleware::class, PortalBusinessSurfaceHandler::class],
                'portal.business.relationship.mutate',
            ), 'portal.access');
            foreach (
                [
                    [
                        '/portal/business/{definition}/{record}/choices/owned-lines/'
                            . '{owned_relationship}/{owned_kind:relations|media}/{owned_field}',
                        'owned-line-field',
                    ],
                    ['/portal/business/{definition}/choices/relations/{related}', 'relations.collection'],
                    ['/portal/business/{definition}/choices/media/{media}', 'media.collection'],
                    ['/portal/business/{definition}/{record}/choices/relations/{related}', 'relations.record'],
                    ['/portal/business/{definition}/{record}/choices/media/{media}', 'media.record'],
                ] as [$path, $name]
            ) {
                self::portalRoute(
                    $application->get(
                        $path,
                        PortalBusinessSurfaceHandler::class,
                        'portal.business.choices.' . $name,
                    ),
                    'portal.access',
                );
            }
            self::portalRoute(
                $application->get(
                    '/portal/business/{definition}/views/{view}',
                    PortalBusinessSurfaceHandler::class,
                    'portal.business.custom-view',
                ),
                'portal.access',
            );
            self::portalRoute(
                $application->get(
                    '/portal/business/{definition}/{record}/views/{view}',
                    PortalBusinessSurfaceHandler::class,
                    'portal.business.custom-record-view',
                ),
                'portal.access',
            );
            foreach (
                [
                    ['/portal/business', 'portal.business'],
                    ['/portal/business/{definition}', 'portal.business.definition'],
                    ['/portal/business/{definition}/{record}', 'portal.business.record'],
                ] as [$path, $name]
            ) {
                self::portalRoute(
                    $application->get($path, PortalBusinessSurfaceHandler::class, $name),
                    'portal.access',
                );
                self::portalRoute($application->post(
                    $path,
                    [PortalCsrfMiddleware::class, PortalBusinessSurfaceHandler::class],
                    $name . '.mutate',
                ), 'portal.access');
            }
        }
        self::administratorRoute(
            $application->get('/administrator', AdministratorDashboardHandler::class, 'administrator.index'),
            'administrator.access',
        );
        self::administratorRoute($application->post(
            '/administrator/dashboard/preferences',
            [AdministratorCsrfMiddleware::class, AdministratorDashboardPreferencesHandler::class],
            'administrator.dashboard.preferences',
        ), 'administrator.access');
        self::administratorRoute(
            $application->get(
                '/administrator/interface-standard',
                AdministratorInterfaceStandardHandler::class,
                'administrator.interface-standard',
            ),
            'administrator.access',
        );
        self::administratorRoute(
            $application->get(
                '/administrator/business/operations/{operation}',
                AdministratorBusinessSurfaceHandler::class,
                'administrator.business.operation',
            ),
            'administrator.access',
        );
        self::administratorRoute(
            $application->get(
                '/administrator/business/{definition}/{record}/relationships/{business_relationship}',
                AdministratorBusinessSurfaceHandler::class,
                'administrator.business.relationship',
            ),
            'administrator.access',
        );
        self::administratorRoute($application->post(
            '/administrator/business/{definition}/{record}/relationships/{business_relationship}',
            [AdministratorCsrfMiddleware::class, AdministratorBusinessSurfaceHandler::class],
            'administrator.business.relationship.mutate',
        ), 'administrator.access');
        foreach (
            [
                [
                    '/administrator/business/{definition}/{record}/choices/owned-lines/'
                        . '{owned_relationship}/{owned_kind:relations|media}/{owned_field}',
                    'owned-line-field',
                ],
                ['/administrator/business/{definition}/choices/relations/{related}', 'relations.collection'],
                ['/administrator/business/{definition}/choices/media/{media}', 'media.collection'],
                [
                    '/administrator/business/{definition}/{record}/choices/relations/{related}',
                    'relations.record',
                ],
                ['/administrator/business/{definition}/{record}/choices/media/{media}', 'media.record'],
            ] as [$path, $name]
        ) {
            self::administratorRoute(
                $application->get(
                    $path,
                    AdministratorBusinessSurfaceHandler::class,
                    'administrator.business.choices.' . $name,
                ),
                'administrator.access',
            );
        }
        self::administratorRoute(
            $application->get(
                '/administrator/business/{definition}/views/{view}',
                AdministratorBusinessSurfaceHandler::class,
                'administrator.business.custom-view',
            ),
            'administrator.access',
        );
        self::administratorRoute(
            $application->get(
                '/administrator/business/{definition}/{record}/views/{view}',
                AdministratorBusinessSurfaceHandler::class,
                'administrator.business.custom-record-view',
            ),
            'administrator.access',
        );
        foreach (
            [
                ['/administrator/business', 'administrator.business'],
                ['/administrator/business/{definition}', 'administrator.business.definition'],
                ['/administrator/business/{definition}/{record}', 'administrator.business.record'],
            ] as [$path, $name]
        ) {
            self::administratorRoute(
                $application->get($path, AdministratorBusinessSurfaceHandler::class, $name),
                'administrator.access',
            );
            self::administratorRoute($application->post(
                $path,
                [AdministratorCsrfMiddleware::class, AdministratorBusinessSurfaceHandler::class],
                $name . '.mutate',
            ), 'administrator.access');
        }
        self::administratorRoute($application->get(
            '/administrator/reports',
            AdministratorReportHandler::class,
            'administrator.reports',
        ), 'business.record.report');
        self::administratorRoute($application->post(
            '/administrator/reports/{report}',
            [AdministratorCsrfMiddleware::class, AdministratorReportHandler::class],
            'administrator.reports.execute',
        ), 'business.record.report');
        self::administratorRoute($application->post(
            '/administrator/reports/{report}/exports',
            [AdministratorCsrfMiddleware::class, AdministratorReportHandler::class],
            'administrator.reports.export',
        ), 'business.record.export');
        self::administratorRoute($application->get(
            '/administrator/reports/exports/{artifact}',
            AdministratorReportHandler::class,
            'administrator.report-exports.read',
        ), 'business.record.export');
        self::administratorRoute($application->get(
            '/administrator/reports/exports/{artifact}/download',
            AdministratorReportHandler::class,
            'administrator.report-exports.download',
        ), 'business.record.export');
        self::administratorRoute($application->get(
            '/administrator/content',
            AdministratorContentListHandler::class,
            'administrator.content',
        ), 'content.read');
        self::administratorRoute($application->get(
            '/administrator/content/new',
            AdministratorContentEditorHandler::class,
            'administrator.content.new',
        ), 'content.create');
        self::administratorRoute($application->get(
            '/administrator/media',
            AdministratorMediaHandler::class,
            'administrator.media',
        ), 'content.read');
        self::administratorRoute($application->post(
            '/administrator/media',
            [AdministratorCsrfMiddleware::class, AdministratorMediaHandler::class],
            'administrator.media.upload',
        ), 'content.update');
        self::administratorRoute($application->post(
            '/administrator/media/{id}/delete',
            [AdministratorCsrfMiddleware::class, AdministratorMediaHandler::class],
            'administrator.media.delete',
        ), 'content.delete');
        self::administratorRoute($application->get(
            '/administrator/content/{id}/edit',
            AdministratorContentEditorHandler::class,
            'administrator.content.edit',
        ), 'content.update');
        self::administratorRoute($application->post(
            '/administrator/content',
            [AdministratorCsrfMiddleware::class, AdministratorCreateContentHandler::class],
            'administrator.content.create',
        ), 'content.create');
        self::administratorRoute($application->post(
            '/administrator/content/{id}',
            [AdministratorCsrfMiddleware::class, AdministratorUpdateContentHandler::class],
            'administrator.content.update',
        ), 'content.update');
        self::administratorRoute($application->post(
            '/administrator/content/{id}/transition',
            [AdministratorCsrfMiddleware::class, AdministratorTransitionContentHandler::class],
            'administrator.content.transition',
        ), 'content.read');
        self::administratorRoute($application->post(
            '/administrator/content/{id}/trash',
            [AdministratorCsrfMiddleware::class, AdministratorTrashContentHandler::class],
            'administrator.content.trash',
        ), 'content.delete');
        self::administratorRoute($application->post(
            '/administrator/content/{id}/restore',
            [AdministratorCsrfMiddleware::class, AdministratorRestoreContentHandler::class],
            'administrator.content.restore',
        ), 'content.restore');
        self::administratorRoute($application->get(
            '/administrator/content-models',
            AdministratorContentModelsHandler::class,
            'administrator.content-models',
        ), 'content.read');
        self::administratorRoute($application->post(
            '/administrator/content-models',
            [AdministratorCsrfMiddleware::class, AdministratorContentModelsHandler::class],
            'administrator.content-models.update',
        ), 'content.update');
        self::administratorRoute($application->get(
            '/administrator/business-definitions',
            BusinessDefinitionsHandler::class,
            'administrator.business-definitions',
        ), 'content.read');
        self::administratorRoute($application->post(
            '/administrator/business-definitions',
            [AdministratorCsrfMiddleware::class, BusinessDefinitionsHandler::class],
            'administrator.business-definitions.update',
        ), 'content.update');
        self::administratorRoute($application->get(
            '/administrator/business-schema-plans',
            BusinessSchemaPlansHandler::class,
            'administrator.business-schema-plans',
        ), 'business.schema.read');
        self::administratorRoute($application->post(
            '/administrator/business-schema-plans/plan',
            [AdministratorCsrfMiddleware::class, CreateBusinessSchemaPlanHandler::class],
            'administrator.business-schema-plans.plan',
        ), 'business.schema.plan');
        self::administratorRoute($application->post(
            '/administrator/business-schema-plans/{id}/approve',
            [AdministratorCsrfMiddleware::class, ApproveBusinessSchemaPlanHandler::class],
            'administrator.business-schema-plans.approve',
        ), 'business.schema.approve');
        self::administratorRoute($application->post(
            '/administrator/business-schema-plans/{id}/execute',
            [AdministratorCsrfMiddleware::class, ExecuteBusinessSchemaPlanHandler::class],
            'administrator.business-schema-plans.execute',
        ), 'business.schema.execute');
        self::administratorRoute($application->post(
            '/administrator/business-schema-plans/recovery-evidence',
            [AdministratorCsrfMiddleware::class, RecordBusinessSchemaRecoveryEvidenceHandler::class],
            'administrator.business-schema-plans.recovery-evidence',
        ), 'business.schema.recover');
        self::administratorRoute($application->post(
            '/administrator/business-schema-plans/{id}/recover',
            [AdministratorCsrfMiddleware::class, RecoverBusinessSchemaPlanHandler::class],
            'administrator.business-schema-plans.recover',
        ), 'business.schema.recover');
        self::administratorRoute($application->post(
            '/administrator/business-schema-plans/purge',
            [AdministratorCsrfMiddleware::class, CreateBusinessSchemaPurgePlanHandler::class],
            'administrator.business-schema-plans.purge',
        ), 'business.schema.destructive');
        self::administratorRoute($application->post(
            '/administrator/logout',
            [AdministratorCsrfMiddleware::class, AdministratorLogoutHandler::class],
            'administrator.logout',
        ), 'administrator.access');
        self::administratorRoute($application->get(
            '/administrator/extensions',
            AdministratorExtensionsHandler::class,
            'administrator.extensions',
        ), 'extensions.manage');
        self::administratorRoute($application->post(
            '/administrator/extensions',
            [AdministratorCsrfMiddleware::class, AdministratorExtensionsHandler::class],
            'administrator.extensions.install',
        ), 'extensions.manage');
        self::administratorRoute($application->post(
            '/administrator/extensions/action',
            [AdministratorCsrfMiddleware::class, AdministratorExtensionActionHandler::class],
            'administrator.extensions.action',
        ), 'extensions.manage');
        self::administratorRoute($application->get(
            '/administrator/settings',
            AdministratorSettingsHandler::class,
            'administrator.settings',
        ), 'settings.manage');
        self::administratorRoute($application->post(
            '/administrator/settings',
            [AdministratorCsrfMiddleware::class, AdministratorSettingsHandler::class],
            'administrator.settings.update',
        ), 'settings.manage');
        self::administratorRoute($application->get(
            '/administrator/navigation',
            AdministratorNavigationHandler::class,
            'administrator.navigation',
        ), 'navigation.manage');
        self::administratorRoute($application->post(
            '/administrator/navigation',
            [AdministratorCsrfMiddleware::class, AdministratorNavigationHandler::class],
            'administrator.navigation.update',
        ), 'navigation.manage');
        self::administratorRoute($application->get(
            '/administrator/wording',
            AdministratorWordingHandler::class,
            'administrator.wording',
        ), 'localization.overrides.manage');
        self::administratorRoute($application->post(
            '/administrator/wording',
            [AdministratorCsrfMiddleware::class, AdministratorWordingHandler::class],
            'administrator.wording.update',
        ), 'localization.overrides.manage');
        self::administratorRoute($application->get(
            '/administrator/access',
            AdministratorAccessControlHandler::class,
            'administrator.access-control',
        ), 'users.manage');
        self::administratorRoute($application->post(
            '/administrator/access',
            [AdministratorCsrfMiddleware::class, AdministratorAccessControlHandler::class],
            'administrator.access-control.update',
        ), 'users.manage');
        self::administratorRoute($application->get(
            '/administrator/business-security',
            AdministratorBusinessSecurityHandler::class,
            'administrator.business-security',
        ), 'business.security.manage');
        self::administratorRoute($application->post(
            '/administrator/business-security',
            [AdministratorCsrfMiddleware::class, AdministratorBusinessSecurityHandler::class],
            'administrator.business-security.update',
        ), 'business.security.manage');
        self::administratorRoute($application->get(
            '/administrator/automation',
            AdministratorAutomationHandler::class,
            'administrator.automation',
        ), 'automation.manage');
        self::administratorRoute($application->post(
            '/administrator/automation',
            [AdministratorCsrfMiddleware::class, AdministratorAutomationHandler::class],
            'administrator.automation.update',
        ), 'automation.manage');
        $application->get('/pages/{slug}', PublishedContentHandler::class, 'site.content.page');
        $application->get('/media/{id}/{name}', MediaAssetHandler::class, 'site.media.asset');
        $application->get('/assets/extensions/{path:.+}', ExtensionAssetHandler::class, 'site.extension.asset');
        $application->get('/api/v1', ApiIndexHandler::class, 'api.v1.index');

        self::apiRoute($application->get(
            '/api/v1/openapi.json',
            OpenApiHandler::class,
            'api.v1.openapi',
        ));
        self::apiRoute($application->get(
            '/api/v1/business/reports',
            ReportApiHandler::class,
            'api.v1.business.reports',
        ), 'business.record.report');
        self::apiRoute($application->post(
            '/api/v1/business/reports/{report}',
            ReportApiHandler::class,
            'api.v1.business.reports.execute',
        ), 'business.record.report');
        self::apiRoute($application->post(
            '/api/v1/business/reports/{report}/exports',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                ReportApiHandler::class,
            ],
            'api.v1.business.reports.export',
        ), 'business.record.export');
        self::apiRoute($application->get(
            '/api/v1/business/report-exports/{artifact}',
            ReportApiHandler::class,
            'api.v1.business.report-exports.read',
        ), 'business.record.export');
        self::apiRoute($application->get(
            '/api/v1/business/report-exports/{artifact}/download',
            ReportApiHandler::class,
            'api.v1.business.report-exports.download',
        ), 'business.record.export');
        self::apiRoute($application->get(
            '/api/v1/business/definitions',
            BusinessDefinitionDiscoveryApiHandler::class,
            'api.v1.business.definitions',
        ), 'business.record.browse');
        self::apiRoute($application->get(
            '/api/v1/business/definitions/{definition}',
            BusinessDefinitionDiscoveryApiHandler::class,
            'api.v1.business.definitions.read',
        ), 'business.record.read');
        self::apiRoute($application->get(
            '/api/v1/business/operations/{operation}',
            BusinessOperationStatusApiHandler::class,
            'api.v1.business.operations.read',
        ), 'business.record.read');
        self::apiRoute($application->get(
            '/api/v1/business/approvals',
            BusinessApprovalApiHandler::class,
            'api.v1.business.approvals',
        ));
        self::apiRoute($application->get(
            '/api/v1/business/approvals/{approval}',
            BusinessApprovalApiHandler::class,
            'api.v1.business.approvals.read',
        ));
        self::apiRoute($application->get(
            '/api/v1/business/records/{definition}',
            BusinessRecordApiHandler::class,
            'api.v1.business.records.browse',
        ), 'business.record.browse');
        self::apiRoute($application->post(
            '/api/v1/business/records/{definition}/search',
            BusinessRecordApiHandler::class,
            'api.v1.business.records.search',
        ), 'business.record.browse');
        self::apiRoute($application->post(
            '/api/v1/business/views/{definition}/{view}',
            BusinessRecordApiHandler::class,
            'api.v1.business.records.custom_view',
        ));
        self::apiRoute($application->post(
            '/api/v1/business/records/{definition}',
            [RequireIdempotencyKeyMiddleware::class, BusinessRecordApiHandler::class],
            'api.v1.business.records.create',
        ), 'business.record.create');
        self::apiRoute($application->get(
            '/api/v1/business/records/{definition}/{record}',
            BusinessRecordApiHandler::class,
            'api.v1.business.records.read',
        ), 'business.record.read');
        self::apiRoute($application->post(
            '/api/v1/business/views/{definition}/{record}/{view}',
            BusinessRecordApiHandler::class,
            'api.v1.business.records.custom_record_view',
        ));
        self::apiRoute($application->patch(
            '/api/v1/business/records/{definition}/{record}',
            [
                RequireIdempotencyKeyMiddleware::class,
                RequireIfMatchMiddleware::class,
                BusinessRecordApiHandler::class,
            ],
            'api.v1.business.records.update',
        ), 'business.record.update');
        self::apiRoute($application->delete(
            '/api/v1/business/records/{definition}/{record}',
            [
                RequireIdempotencyKeyMiddleware::class,
                RequireIfMatchMiddleware::class,
                BusinessRecordApiHandler::class,
            ],
            'api.v1.business.records.delete',
        ), 'business.record.delete');
        foreach (['archive', 'restore'] as $lifecycle) {
            self::apiRoute($application->post(
                '/api/v1/business/records/{definition}/{record}/' . $lifecycle,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    RequireIfMatchMiddleware::class,
                    BusinessRecordApiHandler::class,
                ],
                'api.v1.business.records.' . $lifecycle,
            ), 'business.record.' . $lifecycle);
        }
        self::apiRoute($application->get(
            '/api/v1/business/records/{definition}/{record}/history',
            BusinessRecordApiHandler::class,
            'api.v1.business.records.history',
        ), 'business.record.history');
        foreach (['action', 'approval'] as $actionOperation) {
            $suffix = $actionOperation === 'approval' ? '/approval' : '';
            self::apiRoute($application->post(
                '/api/v1/business/records/{definition}/{record}/actions/{action}' . $suffix,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    RequireIfMatchMiddleware::class,
                    BusinessRecordApiHandler::class,
                ],
                'api.v1.business.records.action.' . $actionOperation,
            ), 'business.record.action');
        }
        self::apiRoute($application->post(
            '/api/v1/business/records/{definition}/{record}/relations/{relation}',
            [
                RequireIdempotencyKeyMiddleware::class,
                RequireIfMatchMiddleware::class,
                BusinessRecordApiHandler::class,
            ],
            'api.v1.business.records.relate',
        ), 'business.record.relate');
        self::apiRoute($application->get(
            '/api/v1/business/records/{definition}/{record}/relations/{relation}',
            BusinessRecordApiHandler::class,
            'api.v1.business.records.relation.read',
        ), 'business.record.read');
        self::apiRoute($application->delete(
            '/api/v1/business/records/{definition}/{record}/relations/{relation}/{target}',
            [
                RequireIdempotencyKeyMiddleware::class,
                RequireIfMatchMiddleware::class,
                BusinessRecordApiHandler::class,
            ],
            'api.v1.business.records.unrelate',
        ), 'business.record.relate');
        self::apiRoute($application->put(
            '/api/v1/business/records/{definition}/{record}/relations/{relation}/order',
            [
                RequireIdempotencyKeyMiddleware::class,
                RequireIfMatchMiddleware::class,
                BusinessRecordApiHandler::class,
            ],
            'api.v1.business.records.reorder',
        ), 'business.record.relate');

        $contentCollection = $application->get(
            '/api/v1/content',
            ContentCollectionHandler::class,
            'api.v1.content.collection',
        );
        $contentCollection->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.read'],
        ]);
        $contentCreate = $application->post(
            '/api/v1/content',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                ContentCollectionHandler::class,
            ],
            'api.v1.content.create',
        );
        $contentCreate->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.create'],
        ]);
        $contentItem = $application->get(
            '/api/v1/content/{id}',
            ContentItemHandler::class,
            'api.v1.content.read',
        );
        $contentItem->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.read'],
        ]);
        $contentUpdate = $application->patch(
            '/api/v1/content/{id}',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                ContentItemHandler::class,
            ],
            'api.v1.content.update',
        );
        $contentUpdate->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.update'],
        ]);
        $contentDelete = $application->delete(
            '/api/v1/content/{id}',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                ContentItemHandler::class,
            ],
            'api.v1.content.trash',
        );
        $contentDelete->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.delete'],
        ]);
        $contentTransition = $application->post(
            '/api/v1/content/{id}/transition',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                ContentTransitionHandler::class,
            ],
            'api.v1.content.transition',
        );
        $contentTransition->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.read'],
        ]);
        $contentRestore = $application->post(
            '/api/v1/content/{id}/restore',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                ContentRestoreHandler::class,
            ],
            'api.v1.content.restore',
        );
        $contentRestore->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.restore'],
        ]);

        foreach (
            [
                '/api/v1/content-types' => ['content-types', '/api/v1/content-types/{id}'],
                '/api/v1/workflows' => ['workflows', '/api/v1/workflows/{id}'],
            ] as $path => [$model, $resourcePath]
        ) {
            self::apiRoute($application->get(
                $path,
                ContentModelApiHandler::class,
                'api.v1.' . $model . '.list',
            ), 'content.read');
            self::apiRoute($application->post(
                $path,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    ContentModelApiHandler::class,
                ],
                'api.v1.' . $model . '.create',
            ), 'content.update');
            self::apiRoute($application->get(
                $resourcePath,
                ContentModelApiHandler::class,
                'api.v1.' . $model . '.read',
            ), 'content.read');
            self::apiRoute($application->patch(
                $resourcePath,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    RequireIfMatchMiddleware::class,
                    ContentModelApiHandler::class,
                ],
                'api.v1.' . $model . '.update',
            ), 'content.update');
        }

        // Business definitions. Reading is content.read and every mutation is content.update,
        // matching the administrator screens these routes are the machine equivalent of.
        self::apiRoute($application->get(
            '/api/v1/business-definitions',
            BusinessDefinitionApiHandler::class,
            'api.v1.business-definitions.list',
        ), 'content.read');
        $definitionReads = [
            '' => 'read',
            '/draft' => 'draft.read',
            '/history' => 'history',
            '/compatibility' => 'compatibility',
        ];
        foreach ($definitionReads as $suffix => $name) {
            self::apiRoute($application->get(
                '/api/v1/business-definitions/{identifier}' . $suffix,
                BusinessDefinitionApiHandler::class,
                'api.v1.business-definitions.' . $name,
            ), 'content.read');
        }
        self::apiRoute($application->put(
            '/api/v1/business-definitions/{identifier}/draft',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                BusinessDefinitionApiHandler::class,
            ],
            'api.v1.business-definitions.draft.save',
        ), 'content.update');
        foreach (['validate', 'publish', 'supersede', 'deprecate', 'reject'] as $action) {
            self::apiRoute($application->post(
                '/api/v1/business-definitions/{identifier}/' . $action,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    BusinessDefinitionApiHandler::class,
                ],
                'api.v1.business-definitions.' . $action,
            ), 'content.update');
        }

        // Schema plans. Each stage is independently grantable, so each route declares only
        // the capability that stage needs; none of them inherits another's authority.
        self::apiRoute($application->get(
            '/api/v1/business-schema-definitions',
            BusinessSchemaApiHandler::class,
            'api.v1.business-schema.definitions',
        ), 'business.schema.read');
        self::apiRoute($application->get(
            '/api/v1/business-schema-plans',
            BusinessSchemaApiHandler::class,
            'api.v1.business-schema-plans.list',
        ), 'business.schema.read');
        self::apiRoute($application->get(
            '/api/v1/business-schema-plans/{planId}',
            BusinessSchemaApiHandler::class,
            'api.v1.business-schema-plans.read',
        ), 'business.schema.read');
        self::apiRoute($application->post(
            '/api/v1/business-schema-plans',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                BusinessSchemaApiHandler::class,
            ],
            'api.v1.business-schema-plans.create',
        ), 'business.schema.plan');
        self::apiRoute($application->post(
            '/api/v1/business-schema-plans/purge',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                BusinessSchemaApiHandler::class,
            ],
            'api.v1.business-schema-plans.purge',
        ), 'business.schema.destructive');
        $planStages = [
            'approve' => 'business.schema.approve',
            'execute' => 'business.schema.execute',
            'recover' => 'business.schema.recover',
        ];
        foreach ($planStages as $action => $capability) {
            self::apiRoute($application->post(
                '/api/v1/business-schema-plans/{planId}/' . $action,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    BusinessSchemaApiHandler::class,
                ],
                'api.v1.business-schema-plans.' . $action,
            ), $capability);
        }

        // Posting periods. Listing and managing are independently grantable, so the read route
        // declares only the read capability while close and reopen each demand the manage one.
        self::apiRoute($application->get(
            '/api/v1/business-periods',
            PostingPeriodApiHandler::class,
            'api.v1.business-periods.list',
        ), 'business.period.read');
        foreach (['close', 'reopen'] as $periodAction) {
            self::apiRoute($application->post(
                '/api/v1/business-periods/' . $periodAction,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    PostingPeriodApiHandler::class,
                ],
                'api.v1.business-periods.' . $periodAction,
            ), 'business.period.manage');
        }

        self::apiRoute($application->get(
            '/api/v1/menus',
            MenuCollectionHandler::class,
            'api.v1.menus.list',
        ), 'navigation.manage');
        self::apiRoute($application->post(
            '/api/v1/menus',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                MenuCollectionHandler::class,
            ],
            'api.v1.menus.create',
        ), 'navigation.manage');
        self::apiRoute($application->get(
            '/api/v1/menus/{id}',
            MenuResourceHandler::class,
            'api.v1.menus.read',
        ), 'navigation.manage');
        self::apiRoute($application->patch(
            '/api/v1/menus/{id}',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                MenuResourceHandler::class,
            ],
            'api.v1.menus.update',
        ), 'navigation.manage');
        self::apiRoute($application->delete(
            '/api/v1/menus/{id}',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                MenuResourceHandler::class,
            ],
            'api.v1.menus.delete',
        ), 'navigation.manage');
        self::apiRoute($application->get(
            '/api/v1/menus/{menuId}/items',
            MenuItemCollectionHandler::class,
            'api.v1.menu-items.list',
        ), 'navigation.manage');
        self::apiRoute($application->post(
            '/api/v1/menus/{menuId}/items',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                MenuItemCollectionHandler::class,
            ],
            'api.v1.menu-items.create',
        ), 'navigation.manage');
        self::apiRoute($application->get(
            '/api/v1/menu-items/{id}',
            MenuItemResourceHandler::class,
            'api.v1.menu-items.read',
        ), 'navigation.manage');
        self::apiRoute($application->patch(
            '/api/v1/menu-items/{id}',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                MenuItemResourceHandler::class,
            ],
            'api.v1.menu-items.update',
        ), 'navigation.manage');
        self::apiRoute($application->delete(
            '/api/v1/menu-items/{id}',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                RequireIfMatchMiddleware::class,
                MenuItemResourceHandler::class,
            ],
            'api.v1.menu-items.delete',
        ), 'navigation.manage');

        foreach (
            [
            ['GET', '/api/v1/users', 'api.v1.users.list'],
            ['GET', '/api/v1/roles', 'api.v1.roles.list'],
            ['GET', '/api/v1/tokens', 'api.v1.tokens.list'],
            ] as [$method, $path, $name]
        ) {
            self::apiRoute(
                $application->route($path, AccessControlApiHandler::class, [$method], $name),
                'users.manage',
            );
        }

        self::apiRoute($application->get(
            '/api/v1/settings',
            SiteSettingsApiHandler::class,
            'api.v1.settings.read',
        ), 'settings.manage');
        self::apiRoute($application->put(
            '/api/v1/settings',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                SiteSettingsApiHandler::class,
            ],
            'api.v1.settings.update',
        ), 'settings.manage');
        self::apiRoute($application->get(
            '/api/v1/extensions',
            ExtensionApiHandler::class,
            'api.v1.extensions.list',
        ), 'extensions.manage');
        self::apiRoute($application->get(
            '/api/v1/extension-trust-keys',
            TrustStoreApiHandler::class,
            'api.v1.extension-trust-keys.list',
        ), 'extensions.manage');
        foreach (
            [
            ['POST', '/api/v1/extension-trust-keys', 'api.v1.extension-trust-keys.create'],
            ['POST', '/api/v1/extension-trust-keys/{keyId}/rotate', 'api.v1.extension-trust-keys.rotate'],
            ['DELETE', '/api/v1/extension-trust-keys/{keyId}', 'api.v1.extension-trust-keys.revoke'],
            ] as [$method, $path, $name]
        ) {
            self::apiRoute($application->route(
                $path,
                [
                    TrustLifecycleMiddleware::class,
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    TrustStoreApiHandler::class,
                ],
                [$method],
                $name,
            ), 'extensions.manage');
        }
        foreach (
            [
            ['POST', '/api/v1/extensions/{vendor}/{name}/activate', 'api.v1.extensions.activate'],
            ['POST', '/api/v1/extensions/{vendor}/{name}/disable', 'api.v1.extensions.disable'],
            ['DELETE', '/api/v1/extensions/{vendor}/{name}', 'api.v1.extensions.uninstall'],
            ] as [$method, $path, $name]
        ) {
            self::apiRoute($application->route(
                $path,
                [
                    TrustLifecycleMiddleware::class,
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    ExtensionApiHandler::class,
                ],
                [$method],
                $name,
            ), 'extensions.manage');
        }
        foreach (
            [
            ['POST', '/api/v1/users', 'api.v1.users.create'],
            ['PATCH', '/api/v1/users/{id}', 'api.v1.users.update'],
            ['POST', '/api/v1/roles', 'api.v1.roles.create'],
            ['PUT', '/api/v1/users/{id}/roles/{roleId}', 'api.v1.user-roles.assign'],
            ['DELETE', '/api/v1/users/{id}/roles/{roleId}', 'api.v1.user-roles.revoke'],
            ['POST', '/api/v1/roles/{id}/grants', 'api.v1.role-grants.create'],
            ['DELETE', '/api/v1/grants/{grantId}', 'api.v1.role-grants.revoke'],
            ['POST', '/api/v1/tokens', 'api.v1.tokens.create'],
            ['DELETE', '/api/v1/tokens/{tokenId}', 'api.v1.tokens.revoke'],
            ['POST', '/api/v1/tokens/{tokenId}/rotate', 'api.v1.tokens.rotate'],
            ['DELETE', '/api/v1/users/{id}/tokens', 'api.v1.tokens.emergency-revoke'],
            ['DELETE', '/api/v1/users/{id}/tokens/emergency', 'api.v1.tokens.emergency-revoke-all'],
            ] as [$method, $path, $name]
        ) {
            self::apiRoute($application->route(
                $path,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    in_array($name, ['api.v1.tokens.create', 'api.v1.tokens.rotate'], true)
                        ? SecretOnceIdempotencyMiddleware::class
                        : PersistentIdempotencyMiddleware::class,
                    AccessControlApiHandler::class,
                ],
                [$method],
                $name,
            ), 'users.manage');
        }

        self::apiRoute($application->get(
            '/api/v1/schedules',
            AutomationApiHandler::class,
            'api.v1.schedules.list',
        ), 'automation.manage');
        self::apiRoute($application->post(
            '/api/v1/schedules',
            [
                RequireIdempotencyKeyMiddleware::class,
                PersistentIdempotencyMiddleware::class,
                AutomationApiHandler::class,
            ],
            'api.v1.schedules.create',
        ), 'automation.manage');
        self::apiRoute($application->get(
            '/api/v1/schedules/{id}',
            AutomationApiHandler::class,
            'api.v1.schedules.read',
        ), 'automation.manage');
        foreach (
            [
            ['PATCH', 'api.v1.schedules.update'],
            ['DELETE', 'api.v1.schedules.delete'],
            ] as [$method, $name]
        ) {
            self::apiRoute($application->route(
                '/api/v1/schedules/{id}',
                [
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    RequireIfMatchMiddleware::class,
                    AutomationApiHandler::class,
                ],
                [$method],
                $name,
            ), 'automation.manage');
        }
        self::apiRoute($application->get(
            '/api/v1/jobs',
            AutomationApiHandler::class,
            'api.v1.jobs.list',
        ), 'automation.manage');
        foreach (
            [
            ['/api/v1/jobs/{id}/retry', 'api.v1.jobs.retry'],
            ['/api/v1/jobs/{id}/cancel', 'api.v1.jobs.cancel'],
            ] as [$path, $name]
        ) {
            self::apiRoute($application->post(
                $path,
                [
                    RequireIdempotencyKeyMiddleware::class,
                    PersistentIdempotencyMiddleware::class,
                    AutomationApiHandler::class,
                ],
                $name,
            ), 'automation.manage');
        }

        $planRoute = $application->post(
            '/api/v1/plans',
            [RequireIdempotencyKeyMiddleware::class, PlanPreviewHandler::class],
            'api.v1.plans.preview',
        );
        $planRoute->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => ['content.read'],
        ]);

        $mcpRoute = $application->route('/mcp', McpHttpHandler::class, ['GET', 'POST', 'DELETE'], 'mcp');
        self::apiRoute($mcpRoute);
        $mcpRoute->setOptions(array_replace($mcpRoute->getOptions(), [
            BearerAuthenticationMiddleware::OPTION_TOKEN_AUDIENCE => 'kumwe-mcp',
            BearerAuthenticationMiddleware::OPTION_TOKEN_PURPOSE => 'mcp',
        ]));
        $application->route('/mcp', McpHttpHandler::class, ['OPTIONS'], 'mcp.options');
        self::service($container, ActiveExtensionSet::class)->registerRoutes(
            $application,
            self::service($container, AdministratorRenderer::class),
            $portalEnabled ? self::service($container, PortalRenderer::class) : null,
        );
        $application->get('/{path:.+}', PublishedContentHandler::class, 'site.content.path');
    }

    /**
     * Register the automation job handlers, the queue worker and every console command.
     *
     * Each command that acts without an operator present receives a `SystemPrincipal` issued from
     * the kernel proof, which is how unattended work reaches the authorization gateway at all. The
     * administrator theme recovery path is given its own capability object rather than the kernel
     * proof, so ordinary theme mutation cannot reach the recovery behaviour.
     *
     * @param   Container  $container    Container being composed.
     * @param   \stdClass  $kernelProof  Composition-root capability system principals are issued from.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerConsole(Container $container, object $kernelProof): void
    {
        $provenance = $kernelProof;
        $recoveryCapability = new \stdClass();
        $container->share(AdministratorThemeRecovery::class, static fn (
            Container $container,
        ): AdministratorThemeRecovery => new ConsoleAdministratorThemeRecovery(
            new DoctrineAdministratorThemeRecovery(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, TransactionManager::class),
                self::service($container, AuditRecorder::class),
                self::service($container, ClockInterface::class),
                self::service($container, ExtensionRuntimeMapCompiler::class),
                $recoveryCapability,
            ),
            self::service($container, RedisRuntime::class),
            self::service($container, ExtensionRegistryFenceAllocator::class),
            self::service($container, TrustStore::class),
            $recoveryCapability,
        ), true);
        $container->share(PurgeAdministratorSessionsHandler::class, static fn (
            Container $container,
        ): PurgeAdministratorSessionsHandler => new PurgeAdministratorSessionsHandler(
            self::service($container, AdministratorSessionStore::class),
        ), true);
        $container->share(IdempotencyPurger::class, static fn (Container $container): IdempotencyPurger =>
            new DoctrineIdempotencyPurger(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(PurgeIdempotencyRecordsHandler::class, static fn (
            Container $container,
        ): PurgeIdempotencyRecordsHandler => new PurgeIdempotencyRecordsHandler(
            self::service($container, IdempotencyPurger::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(PurgeBusinessRecordIdempotencyHandler::class, static fn (
            Container $container,
        ): PurgeBusinessRecordIdempotencyHandler => new PurgeBusinessRecordIdempotencyHandler(
            self::service($container, BusinessRecordIdempotencyPurger::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(RecordAuditAnchorHandler::class, static fn (
            Container $container,
        ): RecordAuditAnchorHandler => new RecordAuditAnchorHandler(
            self::service($container, AuditAnchorWriter::class),
        ), true);
        $container->share(VerifyAuditTrailHandler::class, static fn (
            Container $container,
        ): VerifyAuditTrailHandler => new VerifyAuditTrailHandler(
            self::service($container, AuditTrailVerifier::class),
        ), true);
        $container->share(EnforceAuditRetentionHandler::class, static fn (
            Container $container,
        ): EnforceAuditRetentionHandler => new EnforceAuditRetentionHandler(
            self::service($container, AuditRetentionService::class),
        ), true);
        $container->share(RotateRecordSecretsHandler::class, static fn (
            Container $container,
        ): RotateRecordSecretsHandler => new RotateRecordSecretsHandler(
            self::service($container, RecordSecretRotation::class),
        ), true);
        $container->share(RebuildExtensionMapHandler::class, static fn (
            Container $container,
        ): RebuildExtensionMapHandler => new RebuildExtensionMapHandler(
            self::service($container, ExtensionRuntimeMapCompiler::class),
            self::service($container, AuthorizationGateway::class),
        ), true);
        $container->share(SynchronizeTrustRevocationsHandler::class, static fn (
            Container $container,
        ): SynchronizeTrustRevocationsHandler => new SynchronizeTrustRevocationsHandler(
            self::service($container, RevocationFeedSynchronizer::class),
        ), true);
        $container->share(TransitionContentHandler::class, static fn (
            Container $container,
        ): TransitionContentHandler => new TransitionContentHandler(
            self::service($container, ContentService::class),
        ), true);
        $container->share(JobHandlerRegistry::class, static function (
            Container $container,
        ): JobHandlerRegistry {
            $handlers = [
                self::service($container, PurgeAdministratorSessionsHandler::class),
                self::service($container, PurgeIdempotencyRecordsHandler::class),
                self::service($container, PurgeBusinessRecordIdempotencyHandler::class),
                self::service($container, RecordAuditAnchorHandler::class),
                self::service($container, VerifyAuditTrailHandler::class),
                self::service($container, EnforceAuditRetentionHandler::class),
                self::service($container, RotateRecordSecretsHandler::class),
                self::service($container, RebuildExtensionMapHandler::class),
                self::service($container, SynchronizeTrustRevocationsHandler::class),
                self::service($container, TransitionContentHandler::class),
                self::service($container, GenerateReportExportHandler::class),
            ];
            foreach (
                self::service(
                    $container,
                    ExtensionContributionRegistrySet::class,
                )->jobs()->executableEntries() as $entry
            ) {
                $definition = $entry['definition'];
                $handler = $entry['implementation'];
                if (!$definition instanceof JobContributionDefinition || !$handler instanceof JobHandler) {
                    throw new RuntimeException('The trusted job registry contains an invalid executable entry.');
                }
                $handlers[] = new ValidatedContributedJobHandler($definition, $handler);
            }

            return new JobHandlerRegistry($handlers);
        }, true);
        $container->share(GlobalJobPrincipals::class, static fn (): GlobalJobPrincipals => new GlobalJobPrincipals(
            SystemPrincipal::issue($provenance, SystemIdentity::InstallationMaintenance),
            SystemPrincipal::issue($provenance, SystemIdentity::ExtensionMaterializer),
        ), true);
        $container->share(Worker::class, static fn (Container $container): Worker => new Worker(
            self::service($container, JobQueue::class),
            self::service($container, JobHandlerRegistry::class),
            self::service($container, AuthorizationGateway::class),
            self::service($container, ResourceSiteOwnership::class),
            SystemPrincipal::issue($provenance, SystemIdentity::Worker),
            self::service($container, JobExecutionScope::class),
            self::service($container, GlobalJobPrincipals::class),
        ), true);
        $container->share(FilesystemDemoManifestCatalog::class, static fn (): FilesystemDemoManifestCatalog =>
            new FilesystemDemoManifestCatalog(dirname(__DIR__, 2)), true);
        $container->share(DoctrineDemoProfileLedger::class, static fn (
            Container $container,
        ): DoctrineDemoProfileLedger => new DoctrineDemoProfileLedger(
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->alias(DemoProfileLedger::class, DoctrineDemoProfileLedger::class);
        $container->share(DemoContentProfileInstaller::class, static fn (
            Container $container,
        ): DemoContentProfileInstaller => new DemoContentProfileInstaller(
            self::service($container, ContentService::class),
            self::service($container, NavigationService::class),
            self::service($container, SiteSettings::class),
            self::service($container, DemoProfileLedger::class),
            self::service($container, TransactionManager::class),
        ), true);
        $container->share(VdmBusinessDemoInstaller::class, static fn (
            Container $container,
        ): VdmBusinessDemoInstaller => new VdmBusinessDemoInstaller(
            self::service($container, BusinessDefinitionService::class),
            self::service($container, BusinessSchemaService::class),
            self::service($container, BusinessRecordService::class),
            new VdmBusinessManifestProjector(),
            new VdmBusinessOperationGuard(),
            self::service($container, DemoProfileLedger::class),
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(DemoProfileInstaller::class, static fn (
            Container $container,
        ): DemoProfileInstaller => new DemoProfileInstaller(
            self::service($container, ApplicationConfiguration::class),
            self::service($container, FilesystemDemoManifestCatalog::class),
            self::service($container, DemoContentProfileInstaller::class),
            self::service($container, VdmBusinessDemoInstaller::class),
            self::service($container, DemoProfileLedger::class),
            SystemPrincipal::issue($provenance, SystemIdentity::ProfileInstaller),
        ), true);
        $container->alias(DemoProfileReconciler::class, DemoProfileInstaller::class);
        // The console's one translator binding: catalogue-backed, without the database-held override
        // layers, so a recovery command still renders its wording when the database is unreachable.
        // The console negotiates no locale, so messages resolve at the source locale.
        $container->share(Output::class, static fn (Container $container): Output => StreamOutput::standard(
            new CatalogueTranslator(
                self::service($container, MessageCatalogueRepository::class),
                new ArrayMessageOverrideRepository(),
                self::service($container, MessagePatternFormatter::class),
                self::service($container, ActiveLocale::class),
                self::service($container, SupportedLocales::class),
            ),
        ), true);
        $container->share(MigrateCommand::class, static fn (Container $container): MigrateCommand =>
            new MigrateCommand(
                self::service($container, MigrationRunner::class),
                self::service($container, DemoProfileReconciler::class),
                self::service($container, ExtensionRuntimeMapCompiler::class),
                SystemPrincipal::issue($provenance, SystemIdentity::Migration),
            ), true);
        $container->share(MaterializeExtensionRuntimeCommand::class, static fn (
            Container $container,
        ): MaterializeExtensionRuntimeCommand => new MaterializeExtensionRuntimeCommand(
            self::service($container, ExtensionRuntimeMapCompiler::class),
            self::service($container, ExtensionInstallReconciler::class),
        ), true);
        $container->share(WatchExtensionRuntimeCommand::class, static fn (
            Container $container,
        ): WatchExtensionRuntimeCommand => new WatchExtensionRuntimeCommand(
            self::service($container, ExtensionRuntimeMapCompiler::class),
            self::service($container, ExtensionInstallReconciler::class),
        ), true);
        $container->share(MigrationStatusCommand::class, static fn (Container $container): MigrationStatusCommand =>
            new MigrationStatusCommand(
                self::service($container, MigrationRunner::class),
                SystemPrincipal::issue($provenance, SystemIdentity::Migration),
            ), true);
        $container->share(RecoverMigrationLockCommand::class, static fn (
            Container $container,
        ): RecoverMigrationLockCommand => new RecoverMigrationLockCommand(
            self::service($container, MigrationLockRecoveryService::class),
            SystemPrincipal::issue($provenance, SystemIdentity::Migration),
        ), true);
        $container->share(HealthCheckCommand::class, static fn (Container $container): HealthCheckCommand =>
            new HealthCheckCommand(self::service($container, ReadinessProbe::class)), true);
        $container->share(CreateAdministratorCommand::class, static fn (
            Container $container,
        ): CreateAdministratorCommand => new CreateAdministratorCommand(
            self::service($container, AdministratorIdentityGateway::class),
            SystemPrincipal::issue($provenance, SystemIdentity::Bootstrap),
        ), true);
        $container->share(RecoverCredentialsCommand::class, static fn (
            Container $container,
        ): RecoverCredentialsCommand => new RecoverCredentialsCommand(
            self::service($container, AccessControlService::class),
            self::service($container, AccessControlRepository::class),
            SystemPrincipal::issue($provenance, SystemIdentity::CredentialRecovery),
        ), true);
        $container->share(DemoAccessProvisioner::class, static fn (
            Container $container,
        ): DemoAccessProvisioner => new DemoAccessProvisioner(
            self::service($container, AccessControlService::class),
            self::service($container, AccessControlRepository::class),
            self::service($container, BusinessSecurityAdministrationRepository::class),
            self::service($container, Connection::class),
            self::service($container, TableNames::class),
            self::service($container, TransactionManager::class),
            self::service($container, AuditRecorder::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(DemoAccessCommand::class, static fn (
            Container $container,
        ): DemoAccessCommand => new DemoAccessCommand(
            self::service($container, ApplicationConfiguration::class),
            self::service($container, FilesystemDemoManifestCatalog::class),
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, DemoAccessProvisioner::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(DemoExampleExtensionInstaller::class, static fn (
            Container $container,
        ): DemoExampleExtensionInstaller => new DemoExampleExtensionInstaller(
            dirname(__DIR__, 2),
            self::service($container, ExtensionManager::class),
            self::service($container, TrustStore::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(DemoExamplesCommand::class, static fn (
            Container $container,
        ): DemoExamplesCommand => new DemoExamplesCommand(
            self::service($container, ApplicationConfiguration::class),
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, DemoExampleExtensionInstaller::class),
        ), true);
        $container->share(DemoInstallCommand::class, static fn (
            Container $container,
        ): DemoInstallCommand => new DemoInstallCommand(
            self::service($container, ApplicationConfiguration::class),
            self::service($container, FilesystemDemoManifestCatalog::class),
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, DemoAccessProvisioner::class),
            self::service($container, DemoExampleExtensionInstaller::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(DemoProfileExporter::class, static fn (
            Container $container,
        ): DemoProfileExporter => new DemoProfileExporter(
            self::service($container, ContentService::class),
            self::service($container, NavigationService::class),
            self::service($container, SiteSettings::class),
            self::service($container, DemoProfileLedger::class),
            self::service($container, ClockInterface::class),
        ), true);
        $container->share(DemoBusinessProfileExporter::class, static fn (
            Container $container,
        ): DemoBusinessProfileExporter => new DemoBusinessProfileExporter(
            self::service($container, BusinessDefinitionService::class),
            self::service($container, BusinessRecordService::class),
            self::service($container, AccessControlService::class),
            self::service($container, BusinessSecurityAdministrationRepository::class),
            self::service($container, FilesystemDemoManifestCatalog::class),
            self::service($container, ApplicationConfiguration::class),
            self::service($container, DemoProfileLedger::class),
        ), true);
        $container->share(DemoExportCommand::class, static fn (
            Container $container,
        ): DemoExportCommand => new DemoExportCommand(
            self::service($container, ApplicationConfiguration::class),
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, DemoProfileExporter::class),
            self::service($container, DemoBusinessProfileExporter::class),
        ), true);
        $container->share(CreateAccessTokenCommand::class, static fn (
            Container $container,
        ): CreateAccessTokenCommand => new CreateAccessTokenCommand(
            self::service($container, AdministratorIdentityGateway::class),
            self::service($container, ConsoleAuthorizer::class),
            self::service($container, MembershipDirectory::class),
        ), true);
        $container->share(ListExtensionsCommand::class, static fn (
            Container $container,
        ): ListExtensionsCommand => new ListExtensionsCommand(
            self::service($container, ExtensionManager::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(InstallExtensionCommand::class, static fn (
            Container $container,
        ): InstallExtensionCommand => new InstallExtensionCommand(
            self::service($container, ExtensionManager::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ScaffoldExtensionCommand::class, static fn (
            Container $container,
        ): ScaffoldExtensionCommand => new ScaffoldExtensionCommand(
            self::service($container, ComponentScaffolder::class),
        ), true);
        $container->share(BuildExtensionCommand::class, static fn (
            Container $container,
        ): BuildExtensionCommand => new BuildExtensionCommand(
            self::service($container, DeterministicPackageBuilder::class),
        ), true);
        $container->share(InspectExtensionCommand::class, static fn (
            Container $container,
        ): InspectExtensionCommand => new InspectExtensionCommand(
            self::service($container, PackageInspector::class),
        ), true);
        $container->share(SignExtensionCommand::class, static fn (
            Container $container,
        ): SignExtensionCommand => new SignExtensionCommand(
            self::service($container, PackageSigner::class),
        ), true);
        $container->share(RunExtensionConformanceCommand::class, static fn (
            Container $container,
        ): RunExtensionConformanceCommand => new RunExtensionConformanceCommand(
            self::service($container, StaticConformanceRunner::class),
        ), true);
        $container->share(ActivateExtensionCommand::class, static fn (
            Container $container,
        ): ActivateExtensionCommand => new ActivateExtensionCommand(
            self::service($container, ExtensionManager::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(DisableExtensionCommand::class, static fn (
            Container $container,
        ): DisableExtensionCommand => new DisableExtensionCommand(
            self::service($container, ExtensionManager::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(UninstallExtensionCommand::class, static fn (
            Container $container,
        ): UninstallExtensionCommand => new UninstallExtensionCommand(
            self::service($container, ExtensionManager::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(RecoverAdministratorThemeCommand::class, static fn (
            Container $container,
        ): RecoverAdministratorThemeCommand => new RecoverAdministratorThemeCommand(
            self::service($container, AdministratorThemeRecovery::class),
        ), true);
        $container->share(QueueWorkCommand::class, static fn (Container $container): QueueWorkCommand =>
            new QueueWorkCommand(
                self::service($container, Worker::class),
                SystemPrincipal::issue($provenance, SystemIdentity::Worker),
                self::service($container, ExtensionRuntimeMapCompiler::class),
                self::service($container, RuntimeMaterializationState::class),
                self::service($container, QueueRuntimePolicyCatalog::class),
            ), true);
        $container->share(ScheduleRunCommand::class, static fn (Container $container): ScheduleRunCommand =>
            new ScheduleRunCommand(
                self::service($container, Scheduler::class),
                SystemPrincipal::issue($provenance, SystemIdentity::Scheduler),
                self::service($container, ExtensionRuntimeMapCompiler::class),
                self::service($container, RuntimeMaterializationState::class),
            ), true);
        $container->share(IntegrationWorkCommand::class, static fn (
            Container $container,
        ): IntegrationWorkCommand => new IntegrationWorkCommand(
            self::service($container, OutboxDispatcher::class),
            self::service($container, ProcessWorkDispatcher::class),
            self::service($container, ExtensionRuntimeMapCompiler::class),
            self::service($container, RuntimeMaterializationState::class),
        ), true);
        $container->share(ReportCommand::class, static fn (Container $container): ReportCommand =>
            new ReportCommand(
                self::service($container, ReportService::class),
                self::service($container, ExportService::class),
                self::service($container, ConsoleAuthorizer::class),
                self::service($container, ReportApiPresenter::class),
            ), true);
        $container->share(ConsoleAuthorizer::class, static fn (Container $container): ConsoleAuthorizer =>
            new ConsoleAuthorizer(self::service($container, AccessTokenVerifier::class)), true);
        $container->share(ManageIntegrationsCommand::class, static fn (
            Container $container,
        ): ManageIntegrationsCommand => new ManageIntegrationsCommand(
            self::service($container, IntegrationOperationsService::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ManageAutomationCommand::class, static fn (
            Container $container,
        ): ManageAutomationCommand => new ManageAutomationCommand(
            self::service($container, AutomationManagementService::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ManageContentCommand::class, static fn (Container $container): ManageContentCommand =>
            new ManageContentCommand(
                self::service($container, ContentService::class),
                self::service($container, ConsoleAuthorizer::class),
            ), true);
        $container->share(ManageContentModelsCommand::class, static fn (
            Container $container,
        ): ManageContentModelsCommand => new ManageContentModelsCommand(
            self::service($container, ContentModelService::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ManageBusinessDefinitionsCommand::class, static fn (
            Container $container,
        ): ManageBusinessDefinitionsCommand => new ManageBusinessDefinitionsCommand(
            self::service($container, BusinessDefinitionService::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(BusinessRecordConsolePresenter::class, new BusinessRecordConsolePresenter(), true);
        $container->share(BusinessConsoleFailureMapper::class, new BusinessConsoleFailureMapper(), true);
        $container->share(ManageBusinessRecordsCommand::class, static fn (
            Container $container,
        ): ManageBusinessRecordsCommand => new ManageBusinessRecordsCommand(
            self::service($container, BusinessRecordService::class),
            self::service($container, BusinessSurfaceService::class),
            self::service($container, BusinessSurfaceCatalog::class),
            self::service($container, BusinessRecordQueryFactory::class),
            self::service($container, BusinessRecordProjector::class),
            self::service($container, BusinessOperationStatusService::class),
            self::service($container, BusinessApprovalSurfaceService::class),
            self::service($container, ConsoleAuthorizer::class),
            self::service($container, BusinessRecordConsolePresenter::class),
            self::service($container, BusinessConsoleFailureMapper::class),
        ), true);
        $container->share(ManageBusinessSchemaCommand::class, static fn (
            Container $container,
        ): ManageBusinessSchemaCommand => new ManageBusinessSchemaCommand(
            self::service($container, BusinessSchemaService::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ManagePostingPeriodsCommand::class, static fn (
            Container $container,
        ): ManagePostingPeriodsCommand => new ManagePostingPeriodsCommand(
            self::service($container, PostingPeriodService::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ManageNavigationCommand::class, static fn (
            Container $container,
        ): ManageNavigationCommand => new ManageNavigationCommand(
            self::service($container, NavigationService::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ManageSettingsCommand::class, static fn (Container $container): ManageSettingsCommand =>
            new ManageSettingsCommand(
                self::service($container, SiteSettings::class),
                self::service($container, ConsoleAuthorizer::class),
            ), true);
        $container->share(ManageAccessCommand::class, static fn (Container $container): ManageAccessCommand =>
            new ManageAccessCommand(
                self::service($container, AccessControlService::class),
                self::service($container, AdministratorIdentityGateway::class),
                self::service($container, ConsoleAuthorizer::class),
            ), true);
        $container->share(ManageTrustStoreCommand::class, static fn (
            Container $container,
        ): ManageTrustStoreCommand => new ManageTrustStoreCommand(
            self::service($container, TrustStore::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(VerifyAuditTrailCommand::class, static fn (
            Container $container,
        ): VerifyAuditTrailCommand => new VerifyAuditTrailCommand(
            self::service($container, AuditTrailVerifier::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(ExportAuditTrailCommand::class, static fn (
            Container $container,
        ): ExportAuditTrailCommand => new ExportAuditTrailCommand(
            self::service($container, AuditTrailExporter::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(RotateRecordSecretsCommand::class, static fn (
            Container $container,
        ): RotateRecordSecretsCommand => new RotateRecordSecretsCommand(
            self::service($container, RecordSecretRotation::class),
            self::service($container, ConsoleAuthorizer::class),
        ), true);
        $container->share(McpServeCommand::class, static fn (Container $container): McpServeCommand =>
            new McpServeCommand(
                self::service($container, KumweMcpServerFactory::class),
                self::service($container, KumweMcpHandlers::class),
                self::service($container, AccessTokenVerifier::class),
                self::service($container, LoggerInterface::class),
            ), true);
        $container->share(ConsoleApplication::class, static fn (Container $container): ConsoleApplication =>
            new ConsoleApplication([
                self::service($container, MigrateCommand::class),
                self::service($container, MaterializeExtensionRuntimeCommand::class),
                self::service($container, WatchExtensionRuntimeCommand::class),
                self::service($container, MigrationStatusCommand::class),
                self::service($container, RecoverMigrationLockCommand::class),
                self::service($container, HealthCheckCommand::class),
                self::service($container, CreateAdministratorCommand::class),
                self::service($container, RecoverCredentialsCommand::class),
                self::service($container, DemoAccessCommand::class),
                self::service($container, DemoExamplesCommand::class),
                self::service($container, DemoInstallCommand::class),
                self::service($container, DemoExportCommand::class),
                self::service($container, CreateAccessTokenCommand::class),
                self::service($container, ListExtensionsCommand::class),
                self::service($container, InstallExtensionCommand::class),
                self::service($container, ScaffoldExtensionCommand::class),
                self::service($container, BuildExtensionCommand::class),
                self::service($container, InspectExtensionCommand::class),
                self::service($container, SignExtensionCommand::class),
                self::service($container, RunExtensionConformanceCommand::class),
                self::service($container, ActivateExtensionCommand::class),
                self::service($container, DisableExtensionCommand::class),
                self::service($container, UninstallExtensionCommand::class),
                self::service($container, RecoverAdministratorThemeCommand::class),
                self::service($container, QueueWorkCommand::class),
                self::service($container, ScheduleRunCommand::class),
                self::service($container, IntegrationWorkCommand::class),
                self::service($container, ManageIntegrationsCommand::class),
                self::service($container, ReportCommand::class),
                self::service($container, ManageAutomationCommand::class),
                self::service($container, ManageContentCommand::class),
                self::service($container, ManageContentModelsCommand::class),
                self::service($container, ManageBusinessDefinitionsCommand::class),
                self::service($container, ManageBusinessRecordsCommand::class),
                self::service($container, ManageBusinessSchemaCommand::class),
                self::service($container, ManagePostingPeriodsCommand::class),
                self::service($container, ManageNavigationCommand::class),
                self::service($container, ManageSettingsCommand::class),
                self::service($container, ManageAccessCommand::class),
                self::service($container, ManageTrustStoreCommand::class),
                self::service($container, VerifyAuditTrailCommand::class),
                self::service($container, ExportAuditTrailCommand::class),
                self::service($container, RotateRecordSecretsCommand::class),
                self::service($container, McpServeCommand::class),
            ], self::service($container, Output::class)), true);
    }

    /**
     * Register the Model Context Protocol server factory, its handlers and its session store.
     *
     * Sessions are files under `storage/sessions/mcp` with a one-hour lifetime, so a horizontally
     * scaled deployment has to give every replica the same volume for a session to survive a request
     * that lands on another instance. Both the HTTP transport and the `mcp:serve` console command
     * resolve the same handlers, so the two transports expose an identical capability surface.
     *
     * @param   Container  $container  Container being composed.
     * @param   string     $root       Absolute path of the repository root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function registerMcp(Container $container, string $root): void
    {
        $container->share(McpCapabilityCatalog::class, new McpCapabilityCatalog(), true);
        $container->share(McpMutationGuard::class, static fn (Container $container): McpMutationGuard =>
            new McpMutationGuard(
                self::service($container, Connection::class),
                self::service($container, TableNames::class),
                self::service($container, ClockInterface::class),
                self::service($container, TransactionManager::class),
            ), true);
        $container->share(BusinessMcpHandlers::class, static fn (Container $container): BusinessMcpHandlers =>
            new BusinessMcpHandlers(
                self::service($container, BusinessSurfaceCatalog::class),
                self::service($container, BusinessSurfaceService::class),
                self::service($container, BusinessMutationPlanService::class),
                self::service($container, McpMutationGuard::class),
                self::service($container, BusinessOperationStatusService::class),
                self::service($container, BusinessSurfaceService::class),
            ), true);
        $container->share(ReportMcpHandlers::class, static fn (Container $container): ReportMcpHandlers =>
            new ReportMcpHandlers(
                self::service($container, ReportService::class),
                self::service($container, ExportService::class),
                self::service($container, ReportApiPresenter::class),
            ), true);
        $container->share(SessionStoreInterface::class, static fn (Container $container): SessionStoreInterface =>
            new FileSessionStore(
                $root . '/storage/sessions/mcp',
                3_600,
                self::service($container, ClockInterface::class),
            ), true);
        $container->share(KumweMcpHandlers::class, static fn (Container $container): KumweMcpHandlers =>
            new KumweMcpHandlers(
                self::service($container, McpCapabilityCatalog::class),
                self::service($container, ContentService::class),
                self::service($container, NavigationService::class),
                self::service($container, AccessControlService::class),
                self::service($container, SiteSettings::class),
                self::service($container, ExtensionManager::class),
                self::service($container, TrustStore::class),
                self::service($container, AutomationManagementService::class),
                self::service($container, BusinessDefinitionService::class),
                self::service($container, BusinessSchemaService::class),
                self::service($container, BusinessMcpHandlers::class),
                self::service($container, ReportMcpHandlers::class),
                self::service($container, McpMutationGuard::class),
                self::service($container, ClockInterface::class),
                self::service($container, AuthorizationGateway::class),
            ), true);
        $container->share(KumweMcpServerFactory::class, static fn (Container $container): KumweMcpServerFactory =>
            new KumweMcpServerFactory(
                self::service($container, McpCapabilityCatalog::class),
                sessions: self::service($container, SessionStoreInterface::class),
            ), true);
    }

    /**
     * Require an authenticated administrator session holding every listed capability.
     *
     * `AdministratorAuthorizationMiddleware` rejects any `/administrator` route that declares no
     * capability, so every such route other than the login form is registered through here. The
     * capabilities are conjunctive: the session must hold all of them.
     *
     * @param   Route   $route         Route returned by the router, to attach the requirement to.
     * @param   string  $capabilities  Capability names the session must hold, all of them required.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function administratorRoute(Route $route, string ...$capabilities): void
    {
        $route->setOptions([
            AdministratorAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES => $capabilities,
        ]);
    }

    /**
     * Attach conjunctive portal capability requirements to one route.
     *
     * @param   Route   $route         Matched route receiving the authorization option.
     * @param   string  $capabilities  Capability names the portal actor must hold through the gateway.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function portalRoute(Route $route, string ...$capabilities): void
    {
        $route->setOptions([
            PortalAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES => $capabilities,
        ]);
    }

    /**
     * Require a bearer token issued for the HTTP API and holding every listed capability.
     *
     * Besides the capabilities this pins the audience to `kumwe-http` and the purpose to `api`, so a
     * token minted for another transport is refused even when it carries the right capability. Pass
     * no capability for a route that only needs an authenticated token.
     *
     * @param   Route   $route         Route returned by the router, to attach the requirement to.
     * @param   string  $capabilities  Capability names the presented token must carry, all required.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function apiRoute(Route $route, string ...$capabilities): void
    {
        $route->setOptions([
            BearerAuthenticationMiddleware::OPTION_AUTHENTICATION => 'bearer',
            BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES => $capabilities,
            BearerAuthenticationMiddleware::OPTION_TOKEN_AUDIENCE => 'kumwe-http',
            BearerAuthenticationMiddleware::OPTION_TOKEN_PURPOSE => 'api',
        ]);
    }

    /**
     * Decode the checked-in OpenAPI document and prove its root is a string-keyed JSON object.
     *
     * @param   string  $json  Checked-in OpenAPI JSON bytes.
     *
     * @return  array<string, mixed>  Decoded contract root.
     *
     * @throws  RuntimeException  When the decoded contract root is not a JSON object.
     *
     * @since   2.0.0
     */
    private static function decodeOpenApiObject(string $json): array
    {
        $contract = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($contract) || array_is_list($contract)) {
            throw new RuntimeException('The checked-in core OpenAPI contract is not an object.');
        }
        foreach (array_keys($contract) as $key) {
            if (!is_string($key)) {
                throw new RuntimeException('The checked-in core OpenAPI contract is not an object.');
            }
        }
        /** @var array<string, mixed> $contract */

        return $contract;
    }

    /**
     * Prove definitions from one generic owner-aware registry have the surface's exact contract type.
     *
     * @template T of ContributionDefinition
     *
     * @param   list<ContributionDefinition>  $definitions  Generic active declarations in stable order.
     * @param   class-string<T>               $type         Required declaration type for the surface.
     *
     * @return  list<T>  Declarations proven to implement the requested surface contract.
     *
     * @throws  RuntimeException  When a registry contains a declaration for another surface.
     *
     * @since   2.0.0
     */
    private static function contributionDefinitions(array $definitions, string $type): array
    {
        $resolved = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof $type) {
                throw new RuntimeException(sprintf(
                    'An extension contribution registry contains a definition other than %s.',
                    $type,
                ));
            }
            $resolved[] = $definition;
        }

        return $resolved;
    }

    /**
     * Resolve a shared service and prove it is of the requested type.
     *
     * Joomla DI hands back an untyped value, so every factory in this class resolves through here.
     * That keeps the container's contents typed for static analysis and turns a misregistered
     * service into an immediate composition failure rather than a wrong object reaching a
     * constructor.
     *
     * @template T of object
     *
     * @param   Container        $container  Container to resolve from.
     * @param   class-string<T>  $service    Service identifier, always the class or interface name.
     *
     * @return  T  The resolved service, guaranteed to be an instance of the requested type.
     *
     * @throws  RuntimeException  When the container resolves the identifier to a value of another type.
     *
     * @since   2.0.0
     */
    private static function service(Container $container, string $service): object
    {
        $resolved = $container->get($service);

        if (!$resolved instanceof $service) {
            throw new RuntimeException(sprintf('Container service "%s" resolved to an invalid value.', $service));
        }

        return $resolved;
    }
}
