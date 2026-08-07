<?php

declare(strict_types=1);

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Identity\Application\Administration\AccessControlRepository;
use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
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
if (
    !is_string($adminEmail) || $adminEmail === ''
    || !is_string($adminPassword) || $adminPassword === ''
    || !is_string($limitedEmail) || $limitedEmail === ''
    || !is_string($limitedPassword) || $limitedPassword === ''
) {
    throw new RuntimeException('Browser contribution fixture credentials are unavailable.');
}

$identities = $container->get(AdministratorIdentityGateway::class);
$manager = $container->get(ExtensionManager::class);
$trust = $container->get(TrustStore::class);
$access = $container->get(AccessControlService::class);
$repository = $container->get(AccessControlRepository::class);
$ownership = $container->get(ResourceSiteOwnershipWriter::class);
if (
    !$identities instanceof AdministratorIdentityGateway
    || !$manager instanceof ExtensionManager
    || !$trust instanceof TrustStore
    || !$access instanceof AccessControlService
    || !$repository instanceof AccessControlRepository
    || !$ownership instanceof ResourceSiteOwnershipWriter
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
} finally {
    if (is_file($archive)) {
        unlink($archive);
    }
}
