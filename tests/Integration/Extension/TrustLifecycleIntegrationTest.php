<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Extension;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(TrustStore::class)]
final class TrustLifecycleIntegrationTest extends TestCase
{
    public function testLifecycleSerializationIsReentrantOnEveryDatabasePlatform(): void
    {
        $trust = TestKernelFactory::create(Environment::fromGlobals())->get(TrustStore::class);
        self::assertInstanceOf(TrustStore::class, $trust);
        $calls = 0;

        $result = $trust->synchronizedLifecycle(function () use ($trust, &$calls): string {
            ++$calls;
            return $trust->synchronizedLifecycle(function () use (&$calls): string {
                ++$calls;
                return 'nested';
            });
        });

        self::assertSame('nested', $result);
        self::assertSame(2, $calls);
    }

    public function testEmergencyRevocationQuarantinesActiveReleaseAcrossDatabasePlatforms(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $trust = $container->get(TrustStore::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(TrustStore::class, $trust);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $marker = strtolower(str_replace('-', '', Uuid::uuid7()->toString()));
        $keyId = 'integration.' . $marker;
        $identifier = 'integration/' . substr($marker, 0, 20);
        $context = TestKernelFactory::administratorContext($container);
        $trust->add(
            $context,
            $keyId,
            base64_encode(str_repeat('k', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
            'integration',
            '*',
            new DateTimeImmutable('+1 year'),
        );
        $extensionId = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable();
        $database->insert($tables->raw('extensions'), [
            'id' => $extensionId,
            'identifier' => $identifier,
            'extension_type' => 'plugin',
            'installed_version' => '1.0.0',
            'status' => 'active',
            'service_provider' => 'Integration\\Provider',
            'runtime_path' => $identifier . '/1.0.0',
            'registry_version' => 1,
            'installed_at' => $now,
            'updated_at' => $now,
        ], ['installed_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        $database->insert($tables->raw('extension_releases'), [
            'id' => Uuid::uuid7()->toString(),
            'extension_id' => $extensionId,
            'version' => '1.0.0',
            'manifest' => self::manifest($identifier, 'Integration\\Provider'),
            'package_sha256' => str_repeat('a', 64),
            'artifact_sha256' => str_repeat('a', 64),
            'deployed_tree_sha256' => str_repeat('b', 64),
            'trust_state' => 'verified',
            'signature_algorithm' => 'ed25519',
            'signing_key_id' => $keyId,
            'signature_base64' => base64_encode(str_repeat('s', SODIUM_CRYPTO_SIGN_BYTES)),
            'released_at' => $now,
            'installed_at' => $now,
        ], [
            'manifest' => Types::JSON,
            'released_at' => Types::DATETIME_IMMUTABLE,
            'installed_at' => Types::DATETIME_IMMUTABLE,
        ]);

        self::assertSame(
            [$identifier],
            $trust->emergencyRevoke($context, $keyId, 'integration compromise exercise'),
        );
        self::assertSame('quarantined', $database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE identifier = ?',
            $tables->quoted('extensions'),
        ), [$identifier]));
    }

    public function testMissingActiveReleaseIsQuarantinedBeforeReadiness(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $trust = $container->get(TrustStore::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(TrustStore::class, $trust);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $identifier = 'missing/' . substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 20);
        $now = new DateTimeImmutable();
        $database->insert($tables->raw('extensions'), [
            'id' => Uuid::uuid7()->toString(),
            'identifier' => $identifier,
            'extension_type' => 'plugin',
            'installed_version' => '1.0.0',
            'status' => 'active',
            'service_provider' => 'Missing\\Provider',
            'runtime_path' => $identifier . '/1.0.0',
            'registry_version' => 1,
            'installed_at' => $now,
            'updated_at' => $now,
        ], ['installed_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);

        self::assertFalse($trust->ready());
        self::assertSame('quarantined', $database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE identifier = ?',
            $tables->quoted('extensions'),
        ), [$identifier]));
    }

    public function testFinalRotationRefusesDisabledCurrentRelease(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $trust = $container->get(TrustStore::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(TrustStore::class, $trust);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $marker = strtolower(str_replace('-', '', Uuid::uuid7()->toString()));
        $keyId = 'disabled.' . $marker;
        $identifier = 'disabled/' . substr($marker, 0, 20);
        $context = TestKernelFactory::administratorContext($container);
        $trust->add(
            $context,
            $keyId,
            base64_encode(str_repeat('d', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
            'disabled',
            '*',
            new DateTimeImmutable('+1 year'),
        );
        $extensionId = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable();
        $database->insert($tables->raw('extensions'), [
            'id' => $extensionId,
            'identifier' => $identifier,
            'extension_type' => 'plugin',
            'installed_version' => '1.0.0',
            'status' => 'disabled',
            'service_provider' => 'Disabled\\Provider',
            'runtime_path' => $identifier . '/1.0.0',
            'registry_version' => 1,
            'installed_at' => $now,
            'updated_at' => $now,
        ], ['installed_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        $database->insert($tables->raw('extension_releases'), [
            'id' => Uuid::uuid7()->toString(),
            'extension_id' => $extensionId,
            'version' => '1.0.0',
            'manifest' => self::manifest($identifier, 'Disabled\\Provider'),
            'package_sha256' => str_repeat('a', 64),
            'artifact_sha256' => str_repeat('a', 64),
            'deployed_tree_sha256' => str_repeat('b', 64),
            'trust_state' => 'verified',
            'signature_algorithm' => 'ed25519',
            'signing_key_id' => $keyId,
            'signature_base64' => base64_encode(str_repeat('s', SODIUM_CRYPTO_SIGN_BYTES)),
            'released_at' => $now,
            'installed_at' => $now,
        ], [
            'manifest' => Types::JSON,
            'released_at' => Types::DATETIME_IMMUTABLE,
            'installed_at' => Types::DATETIME_IMMUTABLE,
        ]);

        try {
            $trust->finalizeRotation($context, $keyId, 'rotation complete');
            self::fail('A disabled current release still requires upgrade or quarantine.');
        } catch (\InvalidArgumentException) {
        }
        $key = array_values(array_filter(
            $trust->keys($context),
            static fn (array $row): bool => ($row['key_id'] ?? null) === $keyId,
        ));
        self::assertCount(1, $key);
        self::assertTrue((bool) $key[0]['enabled']);
        self::assertSame([$identifier], $key[0]['affected_extensions']);
    }

    public function testKeyRevocationSerializesWithInstallPersistence(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('The process-control extension is required for the trust race test.');
        }
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $trust = $container->get(TrustStore::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(TrustStore::class, $trust);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $marker = strtolower(str_replace('-', '', Uuid::uuid7()->toString()));
        $keyId = 'race.' . $marker;
        $identifier = 'race/' . substr($marker, 0, 20);
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $checksum = PackageChecksum::calculate('race-package');
        $signature = PackageSignature::ed25519(
            $keyId,
            base64_encode(sodium_crypto_sign_detached((string) $checksum, $secretKey)),
        );
        $context = TestKernelFactory::administratorContext($container);
        $trust->add($context, $keyId, base64_encode($publicKey), 'race', '*', new DateTimeImmutable('+1 year'));
        $directory = sys_get_temp_dir() . '/kumwe-trust-race-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $locked = $directory . '/locked';
        $release = $directory . '/release';
        $revokerStarted = $directory . '/revoker-started';
        $extensionId = Uuid::uuid7()->toString();
        $installer = pcntl_fork();
        if ($installer === 0) {
            try {
                $child = TestKernelFactory::create(Environment::fromGlobals());
                $childTrust = $child->get(TrustStore::class);
                $childDatabase = $child->get(Connection::class);
                $childTables = $child->get(TableNames::class);
                if (
                    !$childTrust instanceof TrustStore || !$childDatabase instanceof Connection
                    || !$childTables instanceof TableNames
                ) {
                    throw new \RuntimeException('Installer dependencies are unavailable.');
                }
                $childTrust->synchronizedLifecycle(function () use (
                    $childTrust,
                    $childDatabase,
                    $childTables,
                    $checksum,
                    $signature,
                    $identifier,
                    $extensionId,
                    $keyId,
                    $locked,
                    $release,
                ): void {
                    $childTrust->assertTrusted(
                        $checksum,
                        $signature,
                        ExtensionIdentifier::fromString($identifier),
                        true,
                    );
                    $ddlTable = $childTables->raw(
                        'extension_race_' . substr(str_replace('-', '', $extensionId), 0, 8),
                    );
                    $childDatabase->executeStatement(sprintf(
                        'CREATE TABLE %s (id INTEGER NOT NULL)',
                        $childDatabase->quoteSingleIdentifier($ddlTable),
                    ));
                    touch($locked);
                    self::waitForFile($release);
                    $now = new DateTimeImmutable();
                    $childDatabase->insert($childTables->raw('extensions'), [
                        'id' => $extensionId,
                        'identifier' => $identifier,
                        'extension_type' => 'plugin',
                        'installed_version' => '1.0.0',
                        'status' => 'active',
                        'service_provider' => 'Race\\Provider',
                        'runtime_path' => $identifier . '/1.0.0',
                        'registry_version' => 1,
                        'installed_at' => $now,
                        'updated_at' => $now,
                    ], ['installed_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
                    $childDatabase->insert($childTables->raw('extension_releases'), [
                        'id' => Uuid::uuid7()->toString(),
                        'extension_id' => $extensionId,
                        'version' => '1.0.0',
                        'manifest' => self::manifest($identifier, 'Race\\Provider'),
                        'package_sha256' => (string) $checksum,
                        'artifact_sha256' => (string) $checksum,
                        'deployed_tree_sha256' => str_repeat('b', 64),
                        'trust_state' => 'verified',
                        'signature_algorithm' => 'ed25519',
                        'signing_key_id' => $keyId,
                        'signature_base64' => $signature->asBase64(),
                        'released_at' => $now,
                        'installed_at' => $now,
                    ], [
                        'manifest' => Types::JSON,
                        'released_at' => Types::DATETIME_IMMUTABLE,
                        'installed_at' => Types::DATETIME_IMMUTABLE,
                    ]);
                    $childDatabase->executeStatement(sprintf(
                        'DROP TABLE %s',
                        $childDatabase->quoteSingleIdentifier($ddlTable),
                    ));
                });
                file_put_contents($directory . '/installer-result', 'committed');
            } catch (\Throwable $exception) {
                file_put_contents($directory . '/installer-result', 'failed:' . $exception->getMessage());
            }
            exit(0);
        }
        self::assertGreaterThan(0, $installer);
        $revoker = pcntl_fork();
        if ($revoker === 0) {
            try {
                self::waitForFile($locked);
                $child = TestKernelFactory::create(Environment::fromGlobals());
                $childTrust = $child->get(TrustStore::class);
                if (!$childTrust instanceof TrustStore) {
                    throw new \RuntimeException('Revoker trust store is unavailable.');
                }
                touch($revokerStarted);
                $childTrust->emergencyRevoke(
                    TestKernelFactory::administratorContext($child),
                    $keyId,
                    'race compromise',
                );
                file_put_contents($directory . '/revoker-result', 'revoked');
            } catch (\Throwable $exception) {
                file_put_contents($directory . '/revoker-result', 'failed:' . $exception->getMessage());
            }
            exit(0);
        }
        self::assertGreaterThan(0, $revoker);
        self::waitForFile($revokerStarted);
        touch($release);
        pcntl_waitpid($installer, $installerStatus);
        pcntl_waitpid($revoker, $revokerStatus);
        self::assertTrue(pcntl_wifexited($installerStatus));
        self::assertTrue(pcntl_wifexited($revokerStatus));
        $database->close();
        self::assertSame('committed', file_get_contents($directory . '/installer-result'));
        self::assertSame('revoked', file_get_contents($directory . '/revoker-result'));
        self::assertSame('quarantined', $database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE identifier = ?',
            $tables->quoted('extensions'),
        ), [$identifier]));
    }

    private static function waitForFile(string $file): void
    {
        $deadline = hrtime(true) + 15_000_000_000;
        while (!is_file($file)) {
            if (hrtime(true) >= $deadline) {
                throw new \RuntimeException('A trust lifecycle race synchronization point timed out.');
            }
            usleep(1_000);
        }
    }

    /** @return array<string, mixed> */
    private static function manifest(string $identifier, string $provider): array
    {
        return [
            'schema' => 1,
            'name' => $identifier,
            'type' => 'plugin',
            'version' => '1.0.0',
            'provider' => $provider,
            'autoload' => ['psr-4' => [str_replace('/', '\\', $identifier) . '\\' => 'src/']],
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
        ];
    }
}
