<?php

declare(strict_types=1);

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordService;
use Kumwe\CMS\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\CMS\BusinessSecurity\Infrastructure\Persistence\DoctrineBusinessSecurityAdministrationRepository;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Identity\Application\Administration\AccessControlRepository;
use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Tests\Support\NeutralBusinessFixture;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

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
        base64_encode(sodium_crypto_sign_detached((string) $checksum, $secretKey)),
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
        base64_encode(sodium_crypto_sign_detached((string) $assetChecksum, $assetSecretKey)),
    );
    $manager->activate('kumwe/asset-inspection-example', $context);
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
    foreach ($assetDefinitions as $assetDefinition) {
        if (!is_array($assetDefinition)) {
            throw new RuntimeException('A browser report fixture definition is invalid.');
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
    $security = new DoctrineBusinessSecurityAdministrationRepository($database, $tables, $ownership);
    $organizationId = Uuid::uuid7()->toString();
    $workspaceId = Uuid::uuid7()->toString();
    $membershipId = Uuid::uuid7()->toString();
    $at = new DateTimeImmutable();
    $transactions->transactional(function () use (
        $security,
        $organizationId,
        $workspaceId,
        $membershipId,
        $portalUser,
        $portalRole,
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
    });
    foreach (['desktop', 'mobile'] as $project) {
        foreach ([0, 1] as $retry) {
            foreach (['maker', 'approver'] as $approvalActor) {
                $approvalUser = $access->createUser(
                    $context,
                    sprintf('browser-%s-%s-%d@kumwe.test', $approvalActor, $project, $retry),
                    sprintf('Browser %s %s %d', ucfirst($project), $approvalActor, $retry),
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
}
