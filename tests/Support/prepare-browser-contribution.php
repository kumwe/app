<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Access\AuthorizationResource;
use Kumwe\Access\ResourceSiteOwnershipWriter;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\BusinessRecord\Application\Command\ExecuteRecordActionCommand;
use Kumwe\App\BusinessRecord\Application\PostingPeriodService;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessSecurity\Infrastructure\Persistence\DoctrineBusinessSecurityAdministrationRepository;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\Extension\Package\PackageChecksum;
use Kumwe\Extension\Package\PackageSignatureMessage;
use Kumwe\App\Identity\Application\Administration\AccessControlRepository;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\BrowserProjectManifest;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Ramsey\Uuid\Uuid;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$container = require dirname(__DIR__, 2) . '/bootstrap/container.php';
$adminEmail = getenv('KUMWE_BROWSER_ADMIN_EMAIL');
$adminPassword = getenv('KUMWE_BROWSER_ADMIN_PASSWORD');
$limitedEmail = getenv('KUMWE_BROWSER_LIMITED_EMAIL');
$limitedPassword = getenv('KUMWE_BROWSER_LIMITED_PASSWORD');
$portalEmail = getenv('KUMWE_BROWSER_PORTAL_EMAIL');
$portalPassword = getenv('KUMWE_BROWSER_PORTAL_PASSWORD');
$portalEmail = is_string($portalEmail) && $portalEmail !== ''
    ? $portalEmail
    : 'browser-portal@kumwe.test';
$portalPassword = is_string($portalPassword) && $portalPassword !== ''
    ? $portalPassword
    : 'browser portal password';
if (
    !is_string($adminEmail) || $adminEmail === ''
    || !is_string($adminPassword) || $adminPassword === ''
    || !is_string($limitedEmail) || $limitedEmail === ''
    || !is_string($limitedPassword) || $limitedPassword === ''
    || $portalEmail === ''
    || $portalPassword === ''
) {
    throw new RuntimeException('Browser contribution fixture credentials are unavailable.');
}

$identities = $container->get(AdministratorIdentityGateway::class);
$manager = $container->get(ExtensionManager::class);
$trust = $container->get(TrustStore::class);
$access = $container->get(AccessControlService::class);
$repository = $container->get(AccessControlRepository::class);
$ownership = $container->get(ResourceSiteOwnershipWriter::class);
$database = $container->get(Connection::class);
$tables = $container->get(TableNames::class);
$transactions = $container->get(TransactionManager::class);
if (
    !$identities instanceof AdministratorIdentityGateway
    || !$manager instanceof ExtensionManager
    || !$trust instanceof TrustStore
    || !$access instanceof AccessControlService
    || !$repository instanceof AccessControlRepository
    || !$ownership instanceof ResourceSiteOwnershipWriter
    || !$database instanceof Connection
    || !$tables instanceof TableNames
    || !$transactions instanceof TransactionManager
) {
    throw new RuntimeException('Browser contribution fixture services are unavailable.');
}

$principal = $identities->authenticate($adminEmail, $adminPassword, 'browser-contribution-fixture');
if ($principal === null) {
    throw new RuntimeException('The browser fixture administrator cannot be authenticated.');
}
$context = $principal->context(
    SiteContext::default(),
    AuthenticationStrength::Password,
    'browser-contribution-' . bin2hex(random_bytes(16)),
);
$archive = tempnam(sys_get_temp_dir(), 'kumwe-browser-announcements-');
if (!is_string($archive)) {
    throw new RuntimeException('The browser fixture package cannot be allocated.');
}
$assetArchive = null;
$manifestSixArchive = null;

try {
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('The browser fixture package cannot be opened.');
    }
    $root = dirname(__DIR__, 2) . '/examples/extensions/announcements';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    try {
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (!is_string($contents) || !$zip->addFromString($relative, $contents)) {
                throw new RuntimeException('The browser fixture package cannot include an example file.');
            }
        }
    } finally {
        $zip->close();
    }

    $bytes = file_get_contents($archive);
    if (!is_string($bytes)) {
        throw new RuntimeException('The browser fixture package cannot be signed.');
    }
    $checksum = PackageChecksum::calculate($bytes);
    $keyPair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $keyId = 'browser.announcements.v1';
    $trust->add(
        $context,
        $keyId,
        base64_encode($publicKey),
        'kumwe',
        'announcements-example',
        new DateTimeImmutable('+1 year'),
    );
    $manager->install(
        $archive,
        $context,
        $keyId,
        base64_encode(sodium_crypto_sign_detached(
            PackageSignatureMessage::forChecksum($checksum),
            $secretKey,
        )),
    );
    $manager->activate('kumwe/announcements-example', $context);
    $trust->synchronizeRuntimeMaterialization();

    $assetArchive = tempnam(sys_get_temp_dir(), 'kumwe-browser-asset-inspection-');
    if (!is_string($assetArchive)) {
        throw new RuntimeException('The browser report fixture package cannot be allocated.');
    }
    $assetRoot = dirname(__DIR__, 2) . '/examples/extensions/asset-inspection';
    $zip = new ZipArchive();
    if ($zip->open($assetArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('The browser report fixture package cannot be opened.');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assetRoot));
    try {
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            $relative = substr($file->getPathname(), strlen($assetRoot) + 1);
            if (!is_string($contents) || !$zip->addFromString($relative, $contents)) {
                throw new RuntimeException('The browser report fixture package cannot include an example file.');
            }
        }
    } finally {
        $zip->close();
    }
    $assetBytes = file_get_contents($assetArchive);
    if (!is_string($assetBytes)) {
        throw new RuntimeException('The browser report fixture package cannot be signed.');
    }
    $assetChecksum = PackageChecksum::calculate($assetBytes);
    $assetKeyPair = sodium_crypto_sign_keypair();
    $assetPublicKey = sodium_crypto_sign_publickey($assetKeyPair);
    $assetSecretKey = sodium_crypto_sign_secretkey($assetKeyPair);
    $assetKeyId = 'browser.asset-inspection.v1';
    $trust->add(
        $context,
        $assetKeyId,
        base64_encode($assetPublicKey),
        'kumwe',
        'asset-inspection-example',
        new DateTimeImmutable('+1 year'),
    );
    $manager->install(
        $assetArchive,
        $context,
        $assetKeyId,
        base64_encode(sodium_crypto_sign_detached(
            PackageSignatureMessage::forChecksum($assetChecksum),
            $assetSecretKey,
        )),
    );
    $manager->activate('kumwe/asset-inspection-example', $context);
    $trust->synchronizeRuntimeMaterialization();

    $manifestSixArchive = tempnam(sys_get_temp_dir(), 'kumwe-browser-manifest-six-');
    if (!is_string($manifestSixArchive)) {
        throw new RuntimeException('The browser manifest-six fixture package cannot be allocated.');
    }
    $manifestSixRoot = dirname(__DIR__, 2)
        . '/vendor/kumwe/extension-sdk/resources/fixtures/generations/manifest-6';
    $zip = new ZipArchive();
    if ($zip->open($manifestSixArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('The browser manifest-six fixture package cannot be opened.');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($manifestSixRoot, FilesystemIterator::SKIP_DOTS),
    );
    try {
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            $relative = substr($file->getPathname(), strlen($manifestSixRoot) + 1);
            if (!is_string($contents) || !$zip->addFromString($relative, $contents)) {
                throw new RuntimeException('The browser manifest-six fixture package cannot include a file.');
            }
        }
    } finally {
        $zip->close();
    }
    $manifestSixBytes = file_get_contents($manifestSixArchive);
    if (!is_string($manifestSixBytes)) {
        throw new RuntimeException('The browser manifest-six fixture package cannot be signed.');
    }
    $manifestSixChecksum = PackageChecksum::calculate($manifestSixBytes);
    $manifestSixKeyPair = sodium_crypto_sign_keypair();
    $manifestSixPublicKey = sodium_crypto_sign_publickey($manifestSixKeyPair);
    $manifestSixSecretKey = sodium_crypto_sign_secretkey($manifestSixKeyPair);
    $manifestSixKeyId = 'browser.contract-manifest-six.v1';
    $trust->add(
        $context,
        $manifestSixKeyId,
        base64_encode($manifestSixPublicKey),
        'kumwe',
        'contract-manifest-six',
        new DateTimeImmutable('+1 year'),
    );
    $manager->install(
        $manifestSixArchive,
        $context,
        $manifestSixKeyId,
        base64_encode(sodium_crypto_sign_detached(
            PackageSignatureMessage::forChecksum($manifestSixChecksum),
            $manifestSixSecretKey,
        )),
    );
    $manager->activate('kumwe/contract-manifest-six', $context);
    $trust->synchronizeRuntimeMaterialization();

    $assetManifestJson = file_get_contents($assetRoot . '/kumwe.json');
    if (!is_string($assetManifestJson)) {
        throw new RuntimeException('The browser report fixture manifest is unavailable.');
    }
    $assetManifest = json_decode($assetManifestJson, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($assetManifest)) {
        throw new RuntimeException('The browser report fixture manifest is invalid.');
    }
    $assetDefinitions = $assetManifest['contributions']['business']['definitions'] ?? null;
    if (!is_array($assetDefinitions) || !array_is_list($assetDefinitions)) {
        throw new RuntimeException('The browser report fixture definitions are unavailable.');
    }
    $assetDefinitionsById = [];
    foreach ($assetDefinitions as $assetDefinition) {
        $definitionId = is_array($assetDefinition) ? ($assetDefinition['id'] ?? null) : null;
        if (!is_string($definitionId) || isset($assetDefinitionsById[$definitionId])) {
            throw new RuntimeException('A browser report fixture definition is invalid.');
        }
        $assetDefinitionsById[$definitionId] = $assetDefinition;
    }
    // Inspection owns ordered junctions whose finding and measurement targets must already be installed.
    $assetSchemaOrder = [
        '019bc200-0000-7000-8000-000000000001',
        '019bc200-0000-7000-8000-000000000002',
        '019bc200-0000-7000-8000-000000000004',
        '019bc200-0000-7000-8000-000000000005',
        '019bc200-0000-7000-8000-000000000003',
    ];
    if (count($assetDefinitionsById) !== count($assetSchemaOrder)) {
        throw new RuntimeException('The browser report fixture definition graph is incomplete.');
    }
    foreach ($assetSchemaOrder as $definitionId) {
        $assetDefinition = $assetDefinitionsById[$definitionId] ?? null;
        if (!is_array($assetDefinition)) {
            throw new RuntimeException('A browser report fixture schema dependency is unavailable.');
        }
        NeutralBusinessFixture::install($container, $context, $assetDefinition);
    }

    $administratorRole = null;
    foreach ($repository->roles() as $role) {
        if (($role['code'] ?? null) === 'administrator' && is_string($role['id'] ?? null)) {
            $administratorRole = $role['id'];
            break;
        }
    }
    if (!is_string($administratorRole)) {
        throw new RuntimeException('The browser fixture administrator role is unavailable.');
    }
    foreach (
        [
            'kumwe.announcements-example.manage',
            'kumwe.asset-inspection-example.manage',
            'kumwe.asset-inspection-example.view',
        ] as $capability
    ) {
        $grantId = Uuid::uuid7()->toString();
        $repository->grant(
            $grantId,
            $administratorRole,
            $capability,
            'global',
            null,
            $context->actorId(),
            new DateTimeImmutable(),
        );
        $ownership->record(AuthorizationResource::item('grant', $grantId), SiteContext::default());
    }

    $limitedUser = $access->createUser(
        $context,
        $limitedEmail,
        'Browser Limited Administrator',
        $limitedPassword,
    );
    $limitedRole = $access->createRole($context, 'browser-limited', 'Browser Limited Administrator');
    $access->grant($context, $limitedRole, 'administrator.access');
    $access->grant($context, $limitedRole, 'content.read');
    $access->assignRole($context, $limitedUser, $limitedRole);

    $dashboardUser = $access->createUser(
        $context,
        'browser-dashboard@kumwe.test',
        'Browser Dashboard Group Member',
        'browser dashboard password',
    );
    $dashboardRole = $access->createRole($context, 'browser-dashboard', 'Browser Dashboard Group');
    $access->grant($context, $dashboardRole, 'administrator.access');
    $access->grant($context, $dashboardRole, 'content.read');
    $access->assignRole($context, $dashboardUser, $dashboardRole);

    // The minimal administrator proves the shell degrades: administrator.access and nothing else.
    $minimalUser = $access->createUser(
        $context,
        'browser-minimal@kumwe.test',
        'Browser Minimal Administrator',
        'browser minimal password',
    );
    $minimalRole = $access->createRole($context, 'browser-minimal', 'Browser Minimal Administrator');
    $access->grant($context, $minimalRole, 'administrator.access');
    $access->assignRole($context, $minimalUser, $minimalRole);

    $portalUser = $access->createUser(
        $context,
        $portalEmail,
        'Browser Portal Member',
        $portalPassword,
    );
    $portalRole = $access->createRole($context, 'browser-portal', 'Browser Portal Member');
    $access->grant($context, $portalRole, 'portal.access', 'site', 'default');
    $portalReportGrant = Uuid::uuid7()->toString();
    $repository->grant(
        $portalReportGrant,
        $portalRole,
        'kumwe.asset-inspection-example.view',
        'site',
        'default',
        $context->actorId(),
        new DateTimeImmutable(),
    );
    $ownership->record(AuthorizationResource::item('grant', $portalReportGrant), SiteContext::default());
    $portalDashboardManager = $access->createUser(
        $context,
        'browser-portal-dashboard-manager@kumwe.test',
        'Browser Portal Dashboard Manager',
        'browser portal dashboard manager password',
    );
    $portalDashboardManagerRole = $access->createRole(
        $context,
        'browser-portal-dashboard-manager',
        'Browser Portal Dashboard Manager',
    );
    $access->grant($context, $portalDashboardManagerRole, 'portal.access', 'site', 'default');
    $access->grant($context, $portalDashboardManagerRole, 'users.manage');
    $security = new DoctrineBusinessSecurityAdministrationRepository($database, $tables, $ownership);
    $organizationId = Uuid::uuid7()->toString();
    $workspaceId = Uuid::uuid7()->toString();
    $membershipId = Uuid::uuid7()->toString();
    $portalDashboardManagerMembershipId = Uuid::uuid7()->toString();
    $at = new DateTimeImmutable();
    $transactions->transactional(function () use (
        $security,
        $organizationId,
        $workspaceId,
        $membershipId,
        $portalDashboardManagerMembershipId,
        $portalUser,
        $portalRole,
        $portalDashboardManager,
        $portalDashboardManagerRole,
        $context,
        $at,
    ): void {
        $security->insertOrganization($organizationId, 'default', 'acme', 'Acme Browser Organization', $at);
        $security->insertWorkspace($workspaceId, $organizationId, 'default', 'north', 'North Workspace', $at);
        $security->insertMembership(
            $membershipId,
            $organizationId,
            'default',
            $portalUser,
            $at->modify('-1 minute'),
            $at->modify('+1 day'),
            $context->actorId(),
            $at,
        );
        $security->assignMembershipWorkspace(
            $membershipId,
            $workspaceId,
            'default',
            $context->actorId(),
            $at,
        );
        $security->assignMembershipRole(
            $membershipId,
            $portalRole,
            'default',
            $context->actorId(),
            $at,
        );
        $security->insertMembership(
            $portalDashboardManagerMembershipId,
            $organizationId,
            'default',
            $portalDashboardManager,
            $at->modify('-1 minute'),
            $at->modify('+1 day'),
            $context->actorId(),
            $at,
        );
        $security->assignMembershipWorkspace(
            $portalDashboardManagerMembershipId,
            $workspaceId,
            'default',
            $context->actorId(),
            $at,
        );
        $security->assignMembershipRole(
            $portalDashboardManagerMembershipId,
            $portalDashboardManagerRole,
            'default',
            $context->actorId(),
            $at,
        );
    });
    // The projects and the retry budget come from tests/Browser/projects.json, the same file
    // playwright.config.ts builds its matrix from, so a project cannot run the portal journeys without
    // an approval identity: adding it to the manifest is what creates both. Only `all` projects reach
    // the maker-checker journey -- the right-to-left projects are confined to a spec that never enrolls
    // an authenticator. TOTP enrollment is a once-per-account operation, so each project needs its own
    // pair, and each attempt of a project needs one the previous attempt has not already consumed. The
    // `breadth` projects never reach that journey, so they need no approval identities of their own.
    $matrix = BrowserProjectManifest::read(dirname(__DIR__) . '/Browser/projects.json');
    $approvalProjects = [];
    foreach ($matrix['projects'] as $matrixProject) {
        if ($matrixProject['specs'] === 'all') {
            $approvalProjects[] = $matrixProject['name'];
        }
    }
    $approvalAttempts = range(0, $matrix['retries']);
    foreach ($approvalProjects as $project) {
        foreach ($approvalAttempts as $retry) {
            foreach (['maker', 'approver'] as $approvalActor) {
                $approvalUser = $access->createUser(
                    $context,
                    sprintf('browser-%s-%s-%d@kumwe.test', $approvalActor, $project, $retry),
                    sprintf('Browser %s %s %d', ucwords($project, '-'), $approvalActor, $retry),
                    sprintf('browser %s %s password %d', $project, $approvalActor, $retry),
                );
                $approvalMembership = Uuid::uuid7()->toString();
                $transactions->transactional(function () use (
                    $security,
                    $approvalMembership,
                    $organizationId,
                    $workspaceId,
                    $approvalUser,
                    $portalRole,
                    $context,
                    $at,
                ): void {
                    $security->insertMembership(
                        $approvalMembership,
                        $organizationId,
                        'default',
                        $approvalUser,
                        $at->modify('-1 minute'),
                        $at->modify('+1 day'),
                        $context->actorId(),
                        $at,
                    );
                    $security->assignMembershipWorkspace(
                        $approvalMembership,
                        $workspaceId,
                        'default',
                        $context->actorId(),
                        $at,
                    );
                    $security->assignMembershipRole(
                        $approvalMembership,
                        $portalRole,
                        'default',
                        $context->actorId(),
                        $at,
                    );
                });
            }
        }
    }

    $relationshipSuffix = 's5browser';
    $targetDocument = NeutralBusinessFixture::relationTargetDocument(
        $relationshipSuffix,
        '019b40d9-8dd0-7ca2-a0db-9eae6a150502',
    );
    $targetDocument['portal_exposure'] = true;
    $targetDocument['portal_operations'] = ['browse', 'read', 'relation', 'reorder'];
    $targetDefinition = NeutralBusinessFixture::install(
        $container,
        $context,
        $targetDocument,
    );
    $lineDocument = NeutralBusinessFixture::ownedLineDocument(
        $relationshipSuffix,
        '019b40d9-8dd0-7ca2-a0db-9eae6a150503',
    );
    $lineDocument['portal_exposure'] = true;
    $lineDocument['portal_operations'] = ['browse', 'create', 'read', 'relation', 'reorder'];
    $lineDefinition = NeutralBusinessFixture::install(
        $container,
        $context,
        $lineDocument,
    );
    $businessDocument = NeutralBusinessFixture::document(
        'session5order',
        '019b40d9-8dd0-7ca2-a0db-9eae6a150501',
    );
    $businessDocument['handle'] = 'site.default.session5_order';
    $businessDocument['singular_label'] = 'Session 5 order';
    $businessDocument['plural_label'] = 'Session 5 orders';
    $businessDocument['portal_exposure'] = true;
    $businessDocument['fields'][] = [
        'handle' => 'conditional_note',
        'label' => 'Conditional note',
        'type' => 'core.text',
        'default' => 'Stored conditional note',
        'length' => 160,
        'visibility_condition' => [
            'op' => 'eq',
            'type' => 'boolean',
            'args' => [
                ['op' => 'field', 'type' => 'boolean', 'field' => 'enabled'],
                ['op' => 'literal', 'type' => 'boolean', 'value' => true],
            ],
        ],
        'editability_condition' => [
            'op' => 'eq',
            'type' => 'boolean',
            'args' => [
                ['op' => 'field', 'type' => 'string', 'field' => 'status'],
                ['op' => 'literal', 'type' => 'string', 'value' => 'ready'],
            ],
        ],
    ];
    $businessDocument['relationships'] = [
        [
            'handle' => 'tags',
            'label' => 'Tags',
            'kind' => 'many_to_many',
            'target' => $targetDefinition->handle,
            'ordered' => true,
            'on_delete' => 'restrict',
        ],
        [
            'handle' => 'lines',
            'label' => 'Lines',
            'kind' => 'owned_line_collection',
            'target' => $lineDefinition->handle,
            'ordered' => true,
            'on_delete' => 'cascade',
        ],
    ];
    $businessDocument['portal_operations'] = [
        'action',
        'approval',
        'archive',
        'browse',
        'create',
        'delete',
        'export',
        'history',
        'read',
        'relation',
        'reorder',
        'report',
        'restore',
        'status',
        'update',
    ];
    foreach ($businessDocument['views'] as &$view) {
        $view['portal'] = true;
    }
    unset($view);
    foreach ($businessDocument['actions'] as &$action) {
        $action['portal'] = true;
        $action['high_impact'] = true;
    }
    unset($action);
    $businessDefinition = NeutralBusinessFixture::install($container, $context, $businessDocument);
    foreach (
        [
            'business.approval.request',
            'business.approval.approve',
            'business.step_up.manage',
            'business.record.action',
            'business.record.archive',
            'business.record.browse',
            'business.record.create',
            'business.record.delete',
            'business.record.export',
            'business.record.history',
            'business.record.read',
            'business.record.relate',
            'business.record.report',
            'business.record.restore',
            'business.record.transition',
            'business.record.update',
        ] as $capability
    ) {
        $access->grant($context, $portalRole, $capability, 'site', 'default');
    }
    $businessRecords = $container->get(BusinessRecordService::class);
    if (!$businessRecords instanceof BusinessRecordService) {
        throw new RuntimeException('The generated-business browser fixture service is unavailable.');
    }
    $assetInspectionId = '019bc210-0000-7000-8000-000000000101';
    $businessRecords->create(new CreateRecordCommand(
        $context,
        'kumwe.asset-inspection-example.inspection',
        [
            'id' => $assetInspectionId,
            'reference' => 'BROWSER-INSPECT-001',
            'inspection_date' => '2026-08-10',
            'raw_score' => 82,
            'adjustment' => -3,
            'internal_note' => 'Browser report restricted note',
        ],
        NeutralBusinessFixture::idempotencyKey('browser-asset-inspection'),
        recordId: $assetInspectionId,
    ));
    $deniedAssetInspectionId = '019bc210-0000-7000-8000-000000000102';
    $businessRecords->create(new CreateRecordCommand(
        $context,
        'kumwe.asset-inspection-example.inspection',
        [
            'id' => $deniedAssetInspectionId,
            'reference' => 'BROWSER-POLICY-DENIED',
            'inspection_date' => '2026-08-11',
            'raw_score' => 65,
            'adjustment' => 0,
        ],
        NeutralBusinessFixture::idempotencyKey('browser-asset-inspection-policy-denied'),
        recordId: $deniedAssetInspectionId,
    ));

    $profileJson = file_get_contents($assetRoot . '/policies/inspection-viewer.json');
    if (!is_string($profileJson)) {
        throw new RuntimeException('The browser report fixture policy profile is unavailable.');
    }
    $profile = json_decode($profileJson, true, 16, JSON_THROW_ON_ERROR);
    if (
        !is_array($profile)
        || array_is_list($profile)
        || CanonicalDefinitionJson::checksum($profile)
            !== '4111a514bab062215a032df003a3edd940f8b2648c8c20030567b6e46c1c220b'
        || ($profile['format'] ?? null) !== 'kumwe-asset-inspection-policy-profile-v1'
        || ($profile['definition_id'] ?? null) !== '019bc200-0000-7000-8000-000000000003'
    ) {
        throw new RuntimeException('The browser report fixture policy profile checksum is invalid.');
    }
    $rowPolicy = $profile['row_policy'] ?? null;
    $fieldPolicy = $profile['field_policy'] ?? null;
    $profileRequests = $profile['policy_requests'] ?? null;
    if (
        !is_array($rowPolicy)
        || array_is_list($rowPolicy)
        || !is_array($fieldPolicy)
        || array_is_list($fieldPolicy)
        || !is_array($profileRequests)
        || !array_is_list($profileRequests)
        || !is_array($rowPolicy['allows'] ?? null)
        || count($rowPolicy['allows']) !== 1
        || ($rowPolicy['denies'] ?? null) !== []
    ) {
        throw new RuntimeException('The browser report fixture row-policy shape is invalid.');
    }
    $predicate = $rowPolicy['allows'][0];
    if (!is_array($predicate) || array_is_list($predicate)) {
        throw new RuntimeException('The browser report fixture row-policy predicate is invalid.');
    }
    $fieldRules = $fieldPolicy + ['actions' => []];
    $policyRequests = [];
    foreach ($profileRequests as $request) {
        $policyCode = is_array($request) ? ($request['policy_code'] ?? null) : null;
        $operation = is_array($request) ? ($request['operation'] ?? null) : null;
        $effect = is_array($request) ? ($request['effect'] ?? null) : null;
        $priority = is_array($request) ? ($request['priority'] ?? null) : null;
        if (
            !is_array($request)
            || array_is_list($request)
            || !is_string($policyCode)
            || !is_string($operation)
            || $effect !== 'allow'
            || ($request['predicate_type'] ?? null) !== 'comparison'
            || ($request['field'] ?? null) !== 'risk_score'
            || ($request['operator'] ?? null) !== 'greater_than_or_equal'
            || ($request['value_type'] ?? null) !== 'integer'
            || ($request['value'] ?? null) !== '70'
            || !is_int($priority)
        ) {
            throw new RuntimeException('A browser report fixture policy request is invalid.');
        }
        $policyRequests[] = [
            'policy_code' => $policyCode,
            'operation' => $operation,
            'priority' => $priority,
        ];
    }
    if (
        array_column($policyRequests, 'operation') !== [
        'business.record.browse',
        'business.record.export',
        'business.record.read',
        'business.record.report',
        ]
    ) {
        throw new RuntimeException('The browser report fixture policy operations are incomplete.');
    }
    NeutralBusinessFixture::removeRecordAccess(
        $container,
        '019bc200-0000-7000-8000-000000000003',
    );
    $policyChecksum = CanonicalDefinitionJson::checksum([
        'ast' => $predicate,
        'fields' => $fieldRules,
    ]);
    $policyAt = new DateTimeImmutable('2026-08-10T00:00:00+00:00');
    foreach ($policyRequests as $request) {
        if (
            $database->fetchOne(sprintf(
                'SELECT policy_code FROM %s WHERE policy_code = ?',
                $tables->quoted('resource_policies'),
            ), [$request['policy_code']]) !== false
        ) {
            continue;
        }
        $security->insertResourcePolicy(
            Uuid::uuid7()->toString(),
            $request['policy_code'],
            $request['operation'],
            $request['operation'],
            'allow',
            null,
            '019bc200-0000-7000-8000-000000000003',
            $predicate,
            $fieldRules,
            $policyChecksum,
            $request['priority'],
            $context->actorId(),
            'default',
            $policyAt,
        );
    }
    foreach (
        [
            ['019b40d9-8dd0-7ca2-a0db-9eae6a150521', 'Windhoek relationship target'],
            ['019b40d9-8dd0-7ca2-a0db-9eae6a150522', 'Walvis Bay relationship target'],
        ] as [$recordId, $label]
    ) {
        $businessRecords->create(new CreateRecordCommand(
            $context,
            $targetDefinition->handle,
            ['label' => $label],
            NeutralBusinessFixture::idempotencyKey('browser-target-' . substr($recordId, -4)),
            recordId: $recordId,
        ));
    }
    foreach (
        [
            ['019b40d9-8dd0-7ca2-a0db-9eae6a150511', 'Windhoek order'],
            ['019b40d9-8dd0-7ca2-a0db-9eae6a150512', 'Walvis Bay order'],
        ] as [$recordId, $name]
    ) {
        $businessRecords->create(new CreateRecordCommand(
            $context,
            $businessDefinition->handle,
            NeutralBusinessFixture::recordValues($name),
            NeutralBusinessFixture::idempotencyKey('browser-' . substr($recordId, -4)),
            recordId: $recordId,
        ));
    }
    $invoiceLineDefinition = NeutralBusinessFixture::install($container, $context, [
        'id' => '019b40d9-8dd0-7ca2-a0db-9eae6a150601',
        'owner' => ['type' => 'site', 'identifier' => 'default'],
        'site' => 'default',
        'handle' => 'site.default.browser_invoice_line',
        'singular_label' => 'Browser invoice line',
        'plural_label' => 'Browser invoice lines',
        'status' => 'draft',
        'definition_version' => 0,
        'storage_mode' => 'relational',
        'identity_strategy' => 'uuid',
        'scope' => 'site',
        'audit_enabled' => true,
        'revisions_enabled' => true,
        'fields' => [
            [
                'handle' => 'id',
                'label' => 'ID',
                'type' => 'core.uuid',
                'required' => true,
                'nullable' => false,
                'unique' => true,
                'indexed' => true,
                'immutable_after_create' => true,
                'server_only' => true,
                'read_only' => true,
            ],
            [
                'handle' => 'description',
                'label' => 'Description',
                'type' => 'core.text',
                'required' => true,
                'nullable' => false,
                'length' => 160,
            ],
            [
                'handle' => 'quantity',
                'label' => 'Quantity',
                'type' => 'core.quantity',
                'required' => true,
                'nullable' => false,
                'precision' => 10,
                'scale' => 2,
                'configuration' => ['unit' => 'each'],
            ],
            [
                'handle' => 'unit_price',
                'label' => 'Unit price',
                'type' => 'core.money',
                'required' => true,
                'nullable' => false,
                'precision' => 14,
                'scale' => 2,
                'configuration' => ['currency' => 'NAD'],
            ],
            [
                'handle' => 'line_total',
                'label' => 'Line total',
                'type' => 'core.money',
                'required' => true,
                'nullable' => false,
                'precision' => 16,
                'scale' => 2,
                'configuration' => ['currency' => 'NAD'],
            ],
        ],
        'relationships' => [],
        'views' => [[
            'handle' => 'browser_invoice_line_list',
            'label' => 'Browser invoice lines',
            'kind' => 'list',
            'fields' => ['description', 'quantity', 'unit_price', 'line_total'],
            'administrator' => true,
            'portal' => true,
            'public' => false,
        ]],
        'actions' => [],
        'workflow' => null,
        'compatibility_metadata' => [],
        'administrator_exposure' => true,
        'portal_exposure' => true,
        'portal_operations' => ['read', 'relation'],
        'public_exposure' => false,
    ]);
    $invoiceDefinition = NeutralBusinessFixture::install($container, $context, [
        'id' => '019b40d9-8dd0-7ca2-a0db-9eae6a150602',
        'owner' => ['type' => 'site', 'identifier' => 'default'],
        'site' => 'default',
        'handle' => 'site.default.browser_invoice',
        'singular_label' => 'Browser invoice',
        'plural_label' => 'Browser invoices',
        'status' => 'draft',
        'definition_version' => 0,
        'storage_mode' => 'relational',
        'identity_strategy' => 'uuid',
        'scope' => 'site',
        'audit_enabled' => true,
        'revisions_enabled' => true,
        'fields' => [
            [
                'handle' => 'id',
                'label' => 'ID',
                'type' => 'core.uuid',
                'required' => true,
                'nullable' => false,
                'unique' => true,
                'indexed' => true,
                'immutable_after_create' => true,
                'server_only' => true,
                'read_only' => true,
            ],
            [
                'handle' => 'number',
                'label' => 'Invoice number',
                'type' => 'core.text',
                'required' => true,
                'nullable' => false,
                'length' => 40,
                'unique' => true,
                'indexed' => true,
                'searchable' => true,
                'filterable' => true,
                'sortable' => true,
            ],
            [
                'handle' => 'issued_on',
                'label' => 'Issued on',
                'type' => 'core.date',
                'required' => true,
                'nullable' => false,
                'filterable' => true,
                'sortable' => true,
            ],
            [
                'handle' => 'total',
                'label' => 'Total',
                'type' => 'core.money',
                'required' => true,
                'nullable' => false,
                'precision' => 16,
                'scale' => 2,
                'configuration' => ['currency' => 'NAD'],
            ],
        ],
        'relationships' => [[
            'handle' => 'lines',
            'label' => 'Invoice lines',
            'kind' => 'owned_line_collection',
            'target' => $invoiceLineDefinition->handle,
            'ordered' => true,
            'on_delete' => 'cascade',
        ]],
        'views' => [
            [
                'handle' => 'browser_invoice_list',
                'label' => 'Browser invoices',
                'kind' => 'list',
                'fields' => ['number', 'issued_on', 'total'],
                'filters' => ['number'],
                'sorts' => ['number'],
                'administrator' => true,
                'portal' => true,
                'public' => false,
            ],
            [
                'handle' => 'browser_invoice_document',
                'label' => 'Browser invoice document',
                'kind' => 'document',
                'fields' => ['number', 'issued_on', 'total'],
                'administrator' => true,
                'portal' => true,
                'public' => false,
                'document' => [
                    'identity' => 'number',
                    'groups' => [['label' => 'Invoice dates', 'fields' => ['issued_on']]],
                    'parties' => [],
                    'lines' => 'lines',
                    'totals' => ['total'],
                ],
            ],
        ],
        'actions' => [],
        'workflow' => null,
        'compatibility_metadata' => [],
        'administrator_exposure' => true,
        'portal_exposure' => true,
        'portal_operations' => ['browse', 'read', 'relation'],
        'public_exposure' => false,
    ]);
    $browserInvoiceId = '019b40d9-8dd0-7ca2-a0db-9eae6a150611';
    $businessRecords->create(new CreateRecordCommand(
        $context,
        $invoiceDefinition->handle,
        [
            'number' => 'INV-BROWSER-001',
            'issued_on' => '2026-08-08',
            'total' => ['amount' => '1380.00', 'currency' => 'nad'],
        ],
        NeutralBusinessFixture::idempotencyKey('browser-invoice-document'),
        recordId: $browserInvoiceId,
    ));
    $invoiceVersion = 1;
    foreach (
        [
            ['019b40d9-8dd0-7ca2-a0db-9eae6a150621', 'Automation retainer', '4.00', '250.00', '1000.00'],
            ['019b40d9-8dd0-7ca2-a0db-9eae6a150622', 'Managed hosting', '2.00', '90.00', '180.00'],
            ['019b40d9-8dd0-7ca2-a0db-9eae6a150623', 'Backup drill', '1.00', '200.00', '200.00'],
        ] as [$lineId, $description, $quantity, $unitPrice, $lineTotal]
    ) {
        $lineResult = $businessRecords->relate(new RelateRecordsCommand(
            $context,
            $invoiceDefinition->handle,
            $browserInvoiceId,
            $invoiceVersion,
            'lines',
            $lineId,
            NeutralBusinessFixture::idempotencyKey('browser-invoice-line-' . substr($lineId, -4)),
            targetValues: [
                'description' => $description,
                'quantity' => ['amount' => $quantity, 'unit' => 'each'],
                'unit_price' => ['amount' => $unitPrice, 'currency' => 'nad'],
                'line_total' => ['amount' => $lineTotal, 'currency' => 'nad'],
            ],
        ));
        $invoiceVersion = $lineResult->version;
    }
    // V2-UX-003: a posting-dated document with an immutable `approved` state. One record stays open, one
    // is approved into the immutable state, and one is dated inside a closed posting period, so both
    // generated surfaces can prove their read-only affordances and the refusal's own wording.
    $lockDefinition = NeutralBusinessFixture::install($container, $context, [
        'id' => '019b40d9-8dd0-7ca2-a0db-9eae6a150702',
        'owner' => ['type' => 'site', 'identifier' => 'default'],
        'site' => 'default',
        'handle' => 'site.default.browser_record_lock',
        'singular_label' => 'Posted statement',
        'plural_label' => 'Posted statements',
        'status' => 'draft',
        'definition_version' => 0,
        'storage_mode' => 'relational',
        'identity_strategy' => 'uuid',
        'scope' => 'site',
        'audit_enabled' => true,
        'revisions_enabled' => true,
        'fields' => [
            [
                'handle' => 'id',
                'label' => 'ID',
                'type' => 'core.uuid',
                'required' => true,
                'nullable' => false,
                'unique' => true,
                'indexed' => true,
                'immutable_after_create' => true,
                'server_only' => true,
                'read_only' => true,
            ],
            [
                'handle' => 'title',
                'label' => 'Statement title',
                'type' => 'core.text',
                'required' => true,
                'nullable' => false,
                'length' => 120,
                'searchable' => true,
                'filterable' => true,
                'sortable' => true,
            ],
            [
                'handle' => 'posted_on',
                'label' => 'Posted on',
                'type' => 'core.date',
                'required' => false,
                'nullable' => true,
                'filterable' => true,
                'sortable' => true,
                'configuration' => ['posting_date' => true],
            ],
        ],
        'relationships' => [],
        'views' => [[
            'handle' => 'browser_record_lock_list',
            'label' => 'Posted statements',
            'kind' => 'list',
            'fields' => ['title', 'posted_on'],
            'filters' => ['title'],
            'sorts' => ['title'],
            'administrator' => true,
            'portal' => true,
            'public' => false,
        ]],
        'actions' => [[
            'handle' => 'approve',
            'label' => 'Approve statement',
            'capability' => 'business.record.action',
            'administrator' => true,
            'portal' => false,
            'public' => false,
            'transition' => 'approve',
        ]],
        'workflow' => [
            'initial_state' => 'draft',
            'states' => ['draft', 'approved'],
            'immutable_states' => ['approved'],
            'transitions' => [[
                'handle' => 'approve',
                'from' => 'draft',
                'to' => 'approved',
                'capability' => 'business.record.action',
            ]],
        ],
        'compatibility_metadata' => [],
        'administrator_exposure' => true,
        'portal_exposure' => true,
        'portal_operations' => ['browse', 'read', 'update'],
        'public_exposure' => false,
    ]);
    foreach (
        [
            ['019b40d9-8dd0-7ca2-a0db-9eae6a150711', 'Open statement', '2026-08-01'],
            ['019b40d9-8dd0-7ca2-a0db-9eae6a150712', 'Approved statement', '2026-08-02'],
            ['019b40d9-8dd0-7ca2-a0db-9eae6a150713', 'Statement in a closed period', '4200-01-05'],
        ] as [$lockRecordId, $lockTitle, $lockPostedOn]
    ) {
        $businessRecords->create(new CreateRecordCommand(
            $context,
            $lockDefinition->handle,
            ['title' => $lockTitle, 'posted_on' => $lockPostedOn],
            NeutralBusinessFixture::idempotencyKey('browser-record-lock-' . substr($lockRecordId, -4)),
            recordId: $lockRecordId,
        ));
    }
    $businessRecords->action(new ExecuteRecordActionCommand(
        $context,
        $lockDefinition->handle,
        '019b40d9-8dd0-7ca2-a0db-9eae6a150712',
        1,
        'approve',
        NeutralBusinessFixture::idempotencyKey('browser-record-lock-approve'),
    ));
    $postingPeriods = $container->get(PostingPeriodService::class);
    if (!$postingPeriods instanceof PostingPeriodService) {
        throw new RuntimeException('The browser posting-period fixture service is unavailable.');
    }
    $postingPeriods->close(
        $context,
        'browser-record-lock-4200',
        new DateTimeImmutable('4200-01-01T00:00:00Z'),
        new DateTimeImmutable('4200-02-01T00:00:00Z'),
    );
    $transition = static fn (string $handle, string $from, string $to): array => [
        'handle' => $handle,
        'from' => $from,
        'to' => $to,
        'capability' => 'business.record.action',
    ];
    $administratorAction = static fn (string $handle, string $label): array => [
        'handle' => $handle,
        'label' => $label,
        'capability' => 'business.record.action',
        'administrator' => true,
        'portal' => false,
        'public' => false,
        'transition' => $handle,
    ];
    $ownedLines = static fn (string $handle, string $label, string $target): array => [
        'handle' => $handle,
        'label' => $label,
        'kind' => 'owned_line_collection',
        'target' => $target,
        'ordered' => true,
        'on_delete' => 'cascade',
    ];

    // P7-E archetype (b): an exact-value document type of owned lines whose total must agree with the sum
    // of its lines, with a review, approve and post workflow whose approved and posted states are immutable,
    // and every field exportable. The journey drafts its own thousand-line document out of process through
    // tests/Support/draft-browser-ledger-document.php, so each project and retry reviews a fresh draft.
    $ledgerLineDocument = NeutralBusinessFixture::documentLineDocument(
        'browser',
        '019b40d9-8dd0-7ca2-a0db-9eae6a150801',
    );
    $ledgerLineDocument['singular_label'] = 'Ledger line';
    $ledgerLineDocument['plural_label'] = 'Ledger lines';
    $exportable = static fn (array $fields): array => array_map(
        static fn (array $field): array => $field['handle'] === 'id' ? $field : [...$field, 'exportable' => true],
        $fields,
    );
    $ledgerLineDocument['fields'] = $exportable($ledgerLineDocument['fields']);
    $ledgerLineDefinition = NeutralBusinessFixture::install($container, $context, $ledgerLineDocument);
    $ledgerDocument = NeutralBusinessFixture::documentHeaderDocument(
        'browser',
        '019b40d9-8dd0-7ca2-a0db-9eae6a150802',
        $ledgerLineDefinition->handle,
    );
    $ledgerDocument['singular_label'] = 'Ledger document';
    $ledgerDocument['plural_label'] = 'Ledger documents';
    $ledgerDocument['fields'] = $exportable($ledgerDocument['fields']);
    $ledgerDocument['views'][] = [
        'handle' => 'ledger_document',
        'label' => 'Ledger document',
        'kind' => 'document',
        'fields' => ['title', 'total'],
        'administrator' => true,
        'portal' => false,
        'public' => false,
        'document' => [
            'identity' => 'title',
            'groups' => [],
            'parties' => [],
            'lines' => 'lines',
            'totals' => ['total'],
        ],
    ];
    $ledgerDocument['workflow'] = [
        'initial_state' => 'draft',
        'states' => ['draft', 'in_review', 'approved', 'posted'],
        'immutable_states' => ['approved', 'posted'],
        'transitions' => [
            $transition('submit', 'draft', 'in_review'),
            $transition('approve', 'in_review', 'approved'),
            $transition('post', 'approved', 'posted'),
        ],
    ];
    $ledgerDocument['actions'] = [
        $administratorAction('submit', 'Submit for review'),
        $administratorAction('approve', 'Approve document'),
        $administratorAction('post', 'Post to ledger'),
    ];
    NeutralBusinessFixture::install($container, $context, $ledgerDocument);

    // P7-E archetype (d): a mobile assignment job card with ordered parts, labour and measurement lines, a
    // media reference for the site photograph, and an assigned, in-progress, completed workflow.
    $jobLine = static fn (string $id, string $handle, string $singular, string $plural, array $fields): array => [
        'id' => $id,
        'owner' => ['type' => 'site', 'identifier' => 'default'],
        'site' => 'default',
        'handle' => $handle,
        'singular_label' => $singular,
        'plural_label' => $plural,
        'status' => 'draft',
        'definition_version' => 0,
        'storage_mode' => 'relational',
        'identity_strategy' => 'uuid',
        'scope' => 'site',
        'audit_enabled' => true,
        'revisions_enabled' => true,
        'fields' => [[
            'handle' => 'id',
            'label' => 'ID',
            'type' => 'core.uuid',
            'required' => true,
            'nullable' => false,
            'unique' => true,
            'indexed' => true,
            'immutable_after_create' => true,
            'server_only' => true,
            'read_only' => true,
        ], ...$fields],
        'relationships' => [],
        'views' => [[
            'handle' => 'list',
            'label' => $plural,
            'kind' => 'list',
            'fields' => array_map(static fn (array $field): string => $field['handle'], $fields),
            'filters' => [],
            'sorts' => [],
            'administrator' => true,
            'portal' => false,
            'public' => false,
        ]],
        'actions' => [],
        'workflow' => null,
        'compatibility_metadata' => [],
        'administrator_exposure' => true,
        'portal_exposure' => false,
        'public_exposure' => false,
    ];
    $text = static fn (string $handle, string $label, int $length = 120): array => [
        'handle' => $handle,
        'label' => $label,
        'type' => 'core.text',
        'required' => true,
        'nullable' => false,
        'length' => $length,
    ];
    $decimal = static fn (string $handle, string $label): array => [
        'handle' => $handle,
        'label' => $label,
        'type' => 'core.decimal',
        'required' => true,
        'nullable' => false,
        'precision' => 12,
        'scale' => 2,
    ];
    $partDefinition = NeutralBusinessFixture::install($container, $context, $jobLine(
        '019b40d9-8dd0-7ca2-a0db-9eae6a150901',
        'site.default.browser_job_part',
        'Part used',
        'Parts used',
        [
            $text('part_number', 'Part number', 40),
            $text('description', 'Part description'),
            $decimal('quantity', 'Quantity'),
        ],
    ));
    $labourDefinition = NeutralBusinessFixture::install($container, $context, $jobLine(
        '019b40d9-8dd0-7ca2-a0db-9eae6a150902',
        'site.default.browser_job_labour',
        'Labour entry',
        'Labour',
        [$text('task', 'Work carried out'), $decimal('hours', 'Hours')],
    ));
    $measurementDefinition = NeutralBusinessFixture::install($container, $context, $jobLine(
        '019b40d9-8dd0-7ca2-a0db-9eae6a150903',
        'site.default.browser_job_measurement',
        'Measurement',
        'Measurements',
        [$text('metric', 'What was measured'), $decimal('reading', 'Reading'), $text('unit', 'Unit', 20)],
    ));
    NeutralBusinessFixture::install($container, $context, [
        ...$jobLine('019b40d9-8dd0-7ca2-a0db-9eae6a150904', 'site.default.browser_job_card', 'Job card', 'Job cards', [
            $text('title', 'Job'),
            $text('site_address', 'Site address', 200),
            [
                'handle' => 'site_photo',
                'label' => 'Site photograph',
                'type' => 'core.media_reference',
                'required' => false,
                'nullable' => true,
            ],
        ]),
        'relationships' => [
            $ownedLines('parts', 'Parts used', $partDefinition->handle),
            $ownedLines('labour', 'Labour', $labourDefinition->handle),
            $ownedLines('measurements', 'Measurements', $measurementDefinition->handle),
        ],
        'workflow' => [
            'initial_state' => 'assigned',
            'states' => ['assigned', 'in_progress', 'completed'],
            'immutable_states' => ['completed'],
            'transitions' => [
                $transition('start', 'assigned', 'in_progress'),
                $transition('complete', 'in_progress', 'completed'),
            ],
        ],
        'actions' => [
            $administratorAction('start', 'Start work'),
            $administratorAction('complete', 'Complete job'),
        ],
    ]);

    // P7-E archetype (e): a portal order placed from a public catalogue page, fulfilled by an administrator,
    // with its payment status written out of process by an adapter holding a scoped API token.
    NeutralBusinessFixture::install($container, $context, [
        ...$jobLine('019b40d9-8dd0-7ca2-a0db-9eae6a150a01', 'site.default.browser_shop_order', 'Order', 'Orders', [
            $text('product', 'Product'),
            [
                'handle' => 'quantity',
                'label' => 'Quantity',
                'type' => 'core.integer',
                'required' => true,
                'nullable' => false,
            ],
            $text('delivery_address', 'Delivery address', 200),
            [
                'handle' => 'payment_status',
                'label' => 'Payment status',
                'type' => 'core.enum',
                'required' => true,
                'nullable' => false,
                'default' => 'pending',
                'configuration' => ['options' => ['pending', 'paid', 'failed']],
                'create_visible' => false,
            ],
        ]),
        'views' => [[
            'handle' => 'list',
            'label' => 'Orders',
            'kind' => 'list',
            'fields' => ['product', 'quantity', 'payment_status'],
            'filters' => [],
            'sorts' => [],
            'administrator' => true,
            'portal' => true,
            'public' => false,
        ]],
        'workflow' => [
            'initial_state' => 'placed',
            'states' => ['placed', 'fulfilled'],
            'transitions' => [
                $transition('fulfil', 'placed', 'fulfilled'),
            ],
        ],
        'actions' => [
            $administratorAction('fulfil', 'Mark as fulfilled'),
        ],
        'portal_exposure' => true,
        'portal_operations' => ['browse', 'read', 'create'],
    ]);
    $catalogue = $container->get(ContentService::class);
    if (!$catalogue instanceof ContentService) {
        throw new RuntimeException('The browser catalogue content service is unavailable.');
    }
    $cataloguePage = $catalogue->create(
        $context,
        'Field service kits',
        'browser-catalogue',
        ['body' => "**Site survey kit** — everything a technician needs for one visit.\n\n"
            . '[Order the site survey kit](/portal/business/site.default.browser_shop_order?new=1)'],
    );
    $cataloguePage = $catalogue->transition(
        $context,
        $cataloguePage->entry->id(),
        $cataloguePage->entry->version(),
        'review',
    );
    $catalogue->transition($context, $cataloguePage->entry->id(), $cataloguePage->entry->version(), 'published');
    // The payment adapter is its own service identity: an organization member whose role may only read and
    // update records. tests/Support/issue-browser-payment-token.php issues its membership-scoped token at the
    // moment the journey needs it, so policy changes made by earlier journeys cannot have retired it.
    $paymentEmail = 'browser-payment-adapter@kumwe.test';
    $paymentPassword = 'browser payment adapter password';
    $paymentUser = $access->createUser($context, $paymentEmail, 'Browser Payment Adapter', $paymentPassword);
    $paymentRole = $access->createRole($context, 'browser-payment-adapter', 'Browser Payment Adapter');
    foreach (['business.record.read', 'business.record.update'] as $capability) {
        $access->grant($context, $paymentRole, $capability, 'site', 'default');
    }
    $paymentMembershipId = Uuid::uuid7()->toString();
    $transactions->transactional(function () use (
        $security,
        $organizationId,
        $workspaceId,
        $paymentMembershipId,
        $paymentUser,
        $paymentRole,
        $context,
    ): void {
        $at = new DateTimeImmutable();
        $security->insertMembership(
            $paymentMembershipId,
            $organizationId,
            'default',
            $paymentUser,
            $at->modify('-1 minute'),
            $at->modify('+1 day'),
            $context->actorId(),
            $at,
        );
        $security->assignMembershipWorkspace($paymentMembershipId, $workspaceId, 'default', $context->actorId(), $at);
        $security->assignMembershipRole($paymentMembershipId, $paymentRole, 'default', $context->actorId(), $at);
    });
    // Issuing needs `users.manage`, which the adapter identity keeps for the fixture's life: withdrawing the
    // role would bump its security epoch and retire its tokens. The token carries record read and update
    // alone, so nothing presenting it can manage users.
    $paymentIssuerRole = $access->createRole($context, 'browser-payment-issuer', 'Browser Payment Token Issuer');
    $access->grant($context, $paymentIssuerRole, 'users.manage');
    $access->assignRole($context, $paymentUser, $paymentRole);
    $access->assignRole($context, $paymentUser, $paymentIssuerRole);

    $transactions->transactional(function () use (
        $security,
        $portalRole,
        $context,
    ): void {
        $security->insertSeparationRule(
            '019b40d9-8dd0-7ca2-a0db-9eae6a150550',
            null,
            'browser-session5-order-approval',
            'business_record',
            'business.record.action:approve',
            'business.approval.approve',
            null,
            $portalRole,
            1,
            true,
            $context->actorId(),
            $context->site()->identifier(),
            new DateTimeImmutable(),
        );
    });
} finally {
    if (is_file($archive)) {
        unlink($archive);
    }
    if (is_string($assetArchive) && is_file($assetArchive)) {
        unlink($assetArchive);
    }
    if (is_string($manifestSixArchive) && is_file($manifestSixArchive)) {
        unlink($manifestSixArchive);
    }
}
