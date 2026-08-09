<?php

declare(strict_types=1);

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessSecurity\Infrastructure\Persistence\DoctrineBusinessSecurityAdministrationRepository;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Identity\Application\Administration\AccessControlRepository;
use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
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
    $grantId = Uuid::uuid7()->toString();
    $repository->grant(
        $grantId,
        $administratorRole,
        'kumwe.announcements-example.manage',
        'global',
        null,
        $context->actorId(),
        new DateTimeImmutable(),
    );
    $ownership->record(AuthorizationResource::item('grant', $grantId), SiteContext::default());

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
} finally {
    if (is_file($archive)) {
        unlink($archive);
    }
}
