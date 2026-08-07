<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Extension;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Extension\Application\Migration\ExtensionTableNames;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Runtime\ActiveExtensionSet;
use Kumwe\CMS\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\CMS\Extension\Infrastructure\RedisLockedExtensionManager;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

#[CoversClass(DoctrineExtensionManager::class)]
#[CoversClass(RedisLockedExtensionManager::class)]
#[UsesClass(ActiveExtensionSet::class)]
#[UsesClass(ExtensionContributionRegistrySet::class)]
final class ExtensionContributionLifecycleIntegrationTest extends TestCase
{
    public function testSignedContributionLifecycleIsPermissionAwareTrustBoundAndDataPreserving(): void
    {
        $environment = Environment::fromGlobals();
        $container = TestKernelFactory::create($environment);
        $manager = $container->get(ExtensionManager::class);
        $trust = $container->get(TrustStore::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(ExtensionManager::class, $manager);
        self::assertInstanceOf(TrustStore::class, $trust);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $context = TestKernelFactory::administratorContext($container);
        $marker = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 16));
        $identifier = 'integration/contributions-' . $marker;
        $namespace = str_replace('/', '.', $identifier);
        $capability = $namespace . '.manage';
        $keyId = 'integration.contributions.' . $marker;
        $archive = $this->examplePackage($identifier);
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $bytes = file_get_contents($archive);
        if (!is_string($bytes)) {
            throw new RuntimeException('The contribution fixture package cannot be read.');
        }
        $checksum = PackageChecksum::calculate($bytes);
        $signature = base64_encode(sodium_crypto_sign_detached((string) $checksum, $secretKey));
        $extensionTables = new ExtensionTableNames(
            $database,
            $tables,
            ExtensionIdentifier::fromString($identifier),
        );
        $dataTable = $extensionTables->raw('announcements');
        $installed = false;

        try {
            $trust->add(
                $context,
                $keyId,
                base64_encode($publicKey),
                'integration',
                '*',
                new DateTimeImmutable('+1 year'),
            );
            $result = $manager->install($archive, $context, $keyId, $signature);
            $installed = true;
            self::assertSame('disabled', $result['status']);
            self::assertTrue($database->createSchemaManager()->tablesExist([$dataTable]));
            $diagnostic = $this->installed($manager, $context, $identifier);
            self::assertSame(2, $diagnostic['manifest_schema']);
            self::assertFalse($diagnostic['contributions']['active']);
            self::assertFalse($diagnostic['contributions']['capabilities'][0]['active']);

            $manager->activate($identifier, $context);
            $trust->synchronizeRuntimeMaterialization();
            $runtime = TestKernelFactory::create($environment);
            $active = $runtime->get(ActiveExtensionSet::class);
            $registries = $runtime->get(ExtensionContributionRegistrySet::class);
            self::assertInstanceOf(ActiveExtensionSet::class, $active);
            self::assertInstanceOf(ExtensionContributionRegistrySet::class, $registries);
            self::assertSame($capability, $active->contributionInventory($identifier)['capabilities'][0]['id']);
            self::assertSame([], $registries->navigation()->visible([]));
            $visible = $registries->navigation()->visible([$capability => true]);
            self::assertSame($namespace . '.navigation', $visible[0]['id']);

            $recovery = (new ContainerFactory())->createRecovery($environment);
            $recoveryActive = $recovery->get(ActiveExtensionSet::class);
            $recoveryRegistries = $recovery->get(ExtensionContributionRegistrySet::class);
            self::assertInstanceOf(ActiveExtensionSet::class, $recoveryActive);
            self::assertInstanceOf(ExtensionContributionRegistrySet::class, $recoveryRegistries);
            self::assertSame(0, $recoveryActive->count());
            self::assertSame(
                [],
                $recoveryRegistries->inventory(ContributionOwner::extension($identifier))['capabilities'],
            );

            $manager->disable($identifier, $context);
            self::assertSame([], $registries->navigation()->visible([$capability => true]));

            $manager->activate($identifier, $context);
            self::assertSame($namespace . '.navigation', $registries->navigation()->visible([
                $capability => true,
            ])[0]['id']);

            self::assertSame(
                [$identifier],
                $trust->emergencyRevoke($context, $keyId, 'Contribution lifecycle trust exercise.'),
            );
            self::assertSame([], $registries->navigation()->visible([$capability => true]));
            $quarantined = TestKernelFactory::create($environment)->get(ActiveExtensionSet::class);
            self::assertInstanceOf(ActiveExtensionSet::class, $quarantined);
            self::assertSame(0, $quarantined->count());

            $manager->uninstall($identifier, $context);
            $installed = false;
            self::assertTrue($database->createSchemaManager()->tablesExist([$dataTable]));
        } finally {
            if ($installed) {
                try {
                    $manager->uninstall($identifier, $context);
                } catch (Throwable) {
                }
            }
            if ($database->createSchemaManager()->tablesExist([$dataTable])) {
                $database->createSchemaManager()->dropTable($dataTable);
            }
            if (is_file($archive)) {
                unlink($archive);
            }
        }
    }

    /** @return array<string, mixed> */
    private function installed(ExtensionManager $manager, ExecutionContext $context, string $identifier): array
    {
        foreach ($manager->installed($context) as $extension) {
            if (($extension['identifier'] ?? null) === $identifier) {
                return $extension;
            }
        }
        throw new RuntimeException('The installed contribution fixture is unavailable.');
    }

    private function examplePackage(string $identifier): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'kumwe-contribution-extension-');
        if (!is_string($archive)) {
            throw new RuntimeException('The contribution fixture archive cannot be allocated.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The contribution fixture archive cannot be opened.');
        }
        $root = dirname(__DIR__, 3) . '/examples/extensions/announcements';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        try {
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if (!is_string($contents)) {
                    throw new RuntimeException('An announcements fixture file cannot be read.');
                }
                $contents = str_replace(
                    ['kumwe/announcements-example', 'kumwe.announcements-example'],
                    [$identifier, str_replace('/', '.', $identifier)],
                    $contents,
                );
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if (!$zip->addFromString($relative, $contents)) {
                    throw new RuntimeException('An announcements fixture file cannot be packaged.');
                }
            }
        } finally {
            $zip->close();
        }
        return $archive;
    }
}
