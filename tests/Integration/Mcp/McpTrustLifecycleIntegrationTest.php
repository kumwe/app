<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Mcp;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\McpMutationGuard;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversClass(KumweMcpHandlers::class)]
final class McpTrustLifecycleIntegrationTest extends TestCase
{
    public function testEmergencyRevocationHoldsLifecycleLockUntilMcpTransactionCommitsOnMySql(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('The process-control extension is required for the MCP trust race test.');
        }
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $trust = $container->get(TrustStore::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(TrustStore::class, $trust);
        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('This test exercises MySQL/MariaDB implicit-commit lifecycle serialization.');
        }

        $marker = strtolower(str_replace('-', '', Uuid::uuid7()->toString()));
        $keyId = 'mcprace.' . $marker;
        $identifier = 'mcprace/' . substr($marker, 0, 20);
        $operationId = 'mcp-trust-race-' . Uuid::uuid7()->toString();
        $startedLock = 'kumwe:mcp-started:' . substr($marker, 0, 24);
        $releaseLock = 'kumwe:mcp-release:' . substr($marker, 0, 24);
        $ddlTable = $tables->raw('mcp_race_' . substr($marker, 0, 20));
        $resultFile = sys_get_temp_dir() . '/kumwe-mcp-trust-' . $marker;
        $readyFile = $resultFile . '-ready';
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $checksum = PackageChecksum::calculate('mcp-race-package-' . $marker);
        $signature = PackageSignature::ed25519(
            $keyId,
            base64_encode(sodium_crypto_sign_detached((string) $checksum, $secretKey)),
        );
        $context = TestKernelFactory::administratorContext($container);
        $trust->add(
            $context,
            $keyId,
            base64_encode($publicKey),
            'mcprace',
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
            'service_provider' => 'McpRace\\Provider',
            'runtime_path' => $identifier . '/1.0.0',
            'registry_version' => 1,
            'installed_at' => $now,
            'updated_at' => $now,
        ], ['installed_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        $database->insert($tables->raw('extension_releases'), [
            'id' => Uuid::uuid7()->toString(),
            'extension_id' => $extensionId,
            'version' => '1.0.0',
            'manifest' => self::manifest($identifier, 'McpRace\\Provider'),
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

        $database->close();
        $revoker = pcntl_fork();
        if ($revoker === 0) {
            try {
                self::waitForFile($readyFile);
                $child = TestKernelFactory::create(Environment::fromGlobals());
                $childDatabase = $child->get(Connection::class);
                $childTables = $child->get(TableNames::class);
                $clock = $child->get(ClockInterface::class);
                $transactions = $child->get(TransactionManager::class);
                $handlers = $child->get(KumweMcpHandlers::class);
                if (
                    !$childDatabase instanceof Connection
                    || !$childTables instanceof TableNames
                    || !$clock instanceof ClockInterface
                    || !$transactions instanceof TransactionManager
                    || !$handlers instanceof KumweMcpHandlers
                ) {
                    throw new RuntimeException('MCP lifecycle race services are unavailable.');
                }
                $handlers = self::withMutationGuard($handlers, new McpMutationGuard(
                    $childDatabase,
                    $childTables,
                    $clock,
                    new CommitBarrierTransactionManager(
                        $transactions,
                        $childDatabase,
                        $startedLock,
                        $releaseLock,
                    ),
                ));
                $result = $handlers->forContext(TestKernelFactory::administratorContext($child))->revokeTrustKey(
                    $operationId,
                    $keyId,
                    'MCP lifecycle race compromise exercise',
                    true,
                );
                file_put_contents($resultFile, json_encode($result, JSON_THROW_ON_ERROR));
            } catch (\Throwable $exception) {
                file_put_contents($resultFile, 'failed:' . $exception->getMessage());
            }
            exit(0);
        }
        if ($revoker < 0) {
            self::fail('The MCP trust revoker process could not be started.');
        }

        $releaseAcquired = $database->fetchOne('SELECT GET_LOCK(?, 0)', [$releaseLock]);
        self::assertContains($releaseAcquired, [1, '1', true]);
        self::assertNotFalse(file_put_contents($readyFile, 'ready'));

        $attempt = 'not-run';
        $tableCreated = false;
        $revokerStatus = 0;
        try {
            self::waitForNamedLock($database, $startedLock);
            try {
                $trust->synchronizedLifecycle(function () use (
                    $trust,
                    $database,
                    $checksum,
                    $signature,
                    $identifier,
                    $ddlTable,
                ): void {
                    $trust->assertTrusted(
                        $checksum,
                        $signature,
                        ExtensionIdentifier::fromString($identifier),
                    );
                    $database->executeStatement(sprintf(
                        'CREATE TABLE %s (id INTEGER NOT NULL)',
                        $database->quoteSingleIdentifier($ddlTable),
                    ));
                });
                $attempt = 'escaped';
            } catch (RuntimeException $exception) {
                $attempt = 'blocked:' . $exception->getMessage();
            }
        } finally {
            $database->fetchOne('SELECT RELEASE_LOCK(?)', [$releaseLock]);
            pcntl_waitpid($revoker, $revokerStatus);
            $database->close();
            $tableCreated = $database->createSchemaManager()->tablesExist([$ddlTable]);
            if ($tableCreated) {
                $database->executeStatement(sprintf(
                    'DROP TABLE %s',
                    $database->quoteSingleIdentifier($ddlTable),
                ));
            }
        }

        self::assertTrue(pcntl_wifexited($revokerStatus));
        self::assertSame(0, pcntl_wexitstatus($revokerStatus));
        self::assertStringStartsWith('blocked:Another extension lifecycle operation', $attempt);
        self::assertFalse($tableCreated, 'An installer executed DDL while MCP revocation was uncommitted.');
        $result = json_decode((string) file_get_contents($resultFile), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([$identifier], $result['quarantined'] ?? null);
        self::assertSame('quarantined', $database->fetchOne(sprintf(
            'SELECT status FROM %s WHERE identifier = ?',
            $tables->quoted('extensions'),
        ), [$identifier]));
        self::assertSame('0', (string) $database->fetchOne(sprintf(
            'SELECT enabled FROM %s WHERE key_id = ?',
            $tables->quoted('extension_trust_keys'),
        ), [$keyId]));
        self::assertSame('completed', $database->fetchOne(sprintf(
            'SELECT state FROM %s WHERE operation = ? AND idempotency_key = ?',
            $tables->quoted('idempotency'),
        ), ['mcp.trust-key.emergency-revoke', $operationId]));
    }

    private static function waitForNamedLock(Connection $database, string $name): void
    {
        $deadline = hrtime(true) + 15_000_000_000;
        while ($database->fetchOne('SELECT IS_USED_LOCK(?)', [$name]) === null) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('The MCP trust race synchronization point timed out.');
            }
            usleep(1_000);
        }
    }

    private static function waitForFile(string $file): void
    {
        $deadline = hrtime(true) + 15_000_000_000;
        while (!is_file($file)) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('The MCP trust race parent did not become ready.');
            }
            usleep(1_000);
        }
    }

    private static function withMutationGuard(
        KumweMcpHandlers $handlers,
        McpMutationGuard $mutations,
    ): KumweMcpHandlers {
        $reflection = new \ReflectionClass($handlers);
        $constructor = $reflection->getConstructor()
            ?? throw new RuntimeException('MCP handlers have no constructor.');
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getName() === 'mutations') {
                $arguments[] = $mutations;
                continue;
            }
            $arguments[] = $reflection->getProperty($parameter->getName())->getValue($handlers);
        }
        $result = $reflection->newInstanceArgs($arguments);
        if (!$result instanceof KumweMcpHandlers) {
            throw new RuntimeException('The MCP handlers could not be recomposed for the transaction race.');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function manifest(string $identifier, string $provider): array
    {
        if (preg_match('/^([A-Z][A-Za-z0-9]*)\\\\/', $provider, $matches) !== 1) {
            throw new RuntimeException('The MCP trust-race provider namespace is invalid.');
        }

        return [
            'schema' => 1,
            'name' => $identifier,
            'type' => 'plugin',
            'version' => '1.0.0',
            'provider' => $provider,
            'autoload' => ['psr-4' => [$matches[1] . '\\' => 'src/']],
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
        ];
    }
}

final readonly class CommitBarrierTransactionManager implements TransactionManager
{
    public function __construct(
        private TransactionManager $inner,
        private Connection $database,
        private string $startedLock,
        private string $releaseLock,
    ) {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->inner->transactional(function () use ($operation): mixed {
            $result = $operation();
            $started = $this->database->fetchOne('SELECT GET_LOCK(?, 0)', [$this->startedLock]);
            if (!in_array($started, [1, '1', true], true)) {
                throw new RuntimeException('The MCP commit barrier could not publish its synchronization point.');
            }
            try {
                $released = $this->database->fetchOne('SELECT GET_LOCK(?, 15)', [$this->releaseLock]);
                if (!in_array($released, [1, '1', true], true)) {
                    throw new RuntimeException('The MCP commit barrier timed out.');
                }
            } finally {
                $this->database->fetchOne('SELECT RELEASE_LOCK(?)', [$this->releaseLock]);
                $this->database->fetchOne('SELECT RELEASE_LOCK(?)', [$this->startedLock]);
            }

            return $result;
        });
    }

    public function afterCommit(callable $operation): void
    {
        $this->inner->afterCommit($operation);
    }

    public function afterRollback(callable $operation): void
    {
        $this->inner->afterRollback($operation);
    }
}
