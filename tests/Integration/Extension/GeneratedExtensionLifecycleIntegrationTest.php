<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use FilesystemIterator;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Migration\ScopedExtensionTableNames;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionEvent;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionWriter;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ProjectionDefinition;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Toolchain\ComponentScaffolder;
use Kumwe\Extension\Toolchain\DeterministicPackageBuilder;
use Kumwe\Extension\Toolchain\PackageInspector;
use Kumwe\Extension\Toolchain\PackageSigner;
use Kumwe\Extension\Toolchain\ProtectedSigningKeyReader;
use Kumwe\Extension\Toolchain\ScaffoldRequest;
use Kumwe\Extension\Toolchain\StaticConformanceRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Proves one unedited SDK scaffold crosses the complete production extension lifecycle.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineExtensionManager::class)]
final class GeneratedExtensionLifecycleIntegrationTest extends TestCase
{
    /**
     * Scaffold, build, sign, install, activate, execute, disable and uninstall the same package.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalSdkScaffoldCompletesTheRealApplicationLifecycle(): void
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

        $marker = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $identifier = 'integration/generated-' . $marker;
        $dotted = str_replace('/', '.', $identifier);
        $keyId = 'integration.generated.' . $marker;
        $temporary = sys_get_temp_dir() . '/kumwe-generated-lifecycle-' . $marker;
        $source = $temporary . '/component';
        $archive = $temporary . '/component.zip';
        $keyFile = $temporary . '/signing.seed';
        $installed = false;
        $trusted = false;
        $componentTable = null;

        self::assertTrue(mkdir($temporary, 0700));
        try {
            $scaffold = (new ComponentScaffolder())->scaffold(new ScaffoldRequest(
                $identifier,
                'Integration\\Generated' . ucfirst($marker),
                $source,
                'Generated lifecycle ' . $marker,
            ));
            self::assertSame($source, $scaffold->directory);

            $inspector = new PackageInspector();
            $build = (new DeterministicPackageBuilder($inspector))->build($source, $archive);
            $report = (new StaticConformanceRunner($inspector))->run($build->archive);
            self::assertTrue($report->conforms());

            $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
            self::assertSame(64, file_put_contents($keyFile, bin2hex($seed), LOCK_EX));
            self::assertTrue(chmod($keyFile, 0600));
            $signature = (new PackageSigner(new ProtectedSigningKeyReader(), $inspector))->sign(
                $build->archive,
                $keyId,
                $keyFile,
            );
            $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
            $trust->add(
                $context,
                $keyId,
                base64_encode($publicKey),
                'integration',
                'generated-' . $marker,
                new DateTimeImmutable('+1 year'),
            );
            $trusted = true;

            $result = $manager->install($build->archive, $context, $keyId, $signature->base64Signature);
            $installed = true;
            self::assertSame('disabled', $result['status']);
            $componentTable = (new ScopedExtensionTableNames(
                $tables->raw(...),
                static fn (string $part): string => $database->getDatabasePlatform()->quoteSingleIdentifier($part),
                ExtensionIdentifier::fromString($identifier),
            ))->raw('component_records');
            self::assertTrue($database->createSchemaManager()->tablesExist([$componentTable]));

            $manager->activate($identifier, $context);
            $trust->synchronizeRuntimeMaterialization();
            $runtime = TestKernelFactory::create($environment);
            $active = $runtime->get(ActiveExtensionSet::class);
            $registries = $runtime->get(ExtensionContributionRegistrySet::class);
            self::assertInstanceOf(ActiveExtensionSet::class, $active);
            self::assertInstanceOf(ExtensionContributionRegistrySet::class, $registries);
            self::assertGreaterThanOrEqual(1, $active->count());

            $owner = ContributionOwner::extension($identifier);
            $projectionIdentifier = $dotted . '.item_projection';
            $definition = $registries->projections()->definition($owner, $projectionIdentifier);
            $projection = $registries->projections()->implementation($owner, $projectionIdentifier);
            self::assertInstanceOf(ProjectionDefinition::class, $definition);
            self::assertInstanceOf(ProjectionBuilder::class, $projection);
            $writer = new GeneratedLifecycleProjectionWriter();
            $projection->apply(
                $definition,
                new GeneratedLifecycleProjectionEvent($dotted . '.item_observed'),
                $writer,
            );
            self::assertSame([[
                'key' => ['item_id' => 'generated-item'],
                'values' => ['item_id' => 'generated-item', 'title' => 'Canonical lifecycle execution'],
            ]], $writer->writes);

            // Withdrawal is a per-process contract: the process that performs a lifecycle mutation
            // observes its resident graph go stale and withdraws it, so the lifecycle tail runs
            // through the runtime container's own manager rather than the pre-activation one.
            $runtimeManager = $runtime->get(ExtensionManager::class);
            self::assertInstanceOf(ExtensionManager::class, $runtimeManager);
            $runtimeManager->disable($identifier, $context);
            $disabled = array_values(array_filter(
                $runtimeManager->installed($context),
                static fn (array $extension): bool => ($extension['identifier'] ?? null) === $identifier,
            ));
            self::assertCount(1, $disabled);
            self::assertSame('disabled', $disabled[0]['status'] ?? null);
            self::assertNull($registries->projections()->definition($owner, $projectionIdentifier));
            $runtimeManager->uninstall($identifier, $context);
            $installed = false;
            self::assertSame([], array_values(array_filter(
                $runtimeManager->installed($context),
                static fn (array $extension): bool => ($extension['identifier'] ?? null) === $identifier,
            )));
            $trust->revoke($context, $keyId, 'Generated lifecycle acceptance completed.');
            $trusted = false;
        } finally {
            if ($installed) {
                try {
                    $manager->disable($identifier, $context);
                } catch (Throwable) {
                }
                try {
                    $manager->uninstall($identifier, $context);
                } catch (Throwable) {
                }
            }
            if ($trusted) {
                try {
                    $trust->revoke($context, $keyId, 'Generated lifecycle acceptance cleanup.');
                } catch (Throwable) {
                }
            }
            if (
                is_string($componentTable)
                && $database->createSchemaManager()->tablesExist([$componentTable])
            ) {
                $database->createSchemaManager()->dropTable($componentTable);
            }
            self::removeTree($temporary);
        }
    }

    /**
     * Remove only the private generated-lifecycle directory created by this test.
     *
     * @param   string  $directory  Absolute test-owned directory under the system temporary root.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the target is outside the expected test-owned prefix.
     *
     * @since   2.0.0
     */
    private static function removeTree(string $directory): void
    {
        $prefix = rtrim(sys_get_temp_dir(), '/') . '/kumwe-generated-lifecycle-';
        if (!str_starts_with($directory, $prefix)) {
            throw new RuntimeException('The generated lifecycle cleanup target is outside its private prefix.');
        }
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}

/**
 * Immutable host-issued event used to execute the generated projection through its canonical SDK contract.
 *
 * @since  2.0.0
 */
final readonly class GeneratedLifecycleProjectionEvent implements ProjectionEvent
{
    /**
     * Retain the exact package-owned event type generated by the scaffold.
     *
     * @param  string  $type  Manifest-declared event type.
     *
     * @since  2.0.0
     */
    public function __construct(private string $type)
    {
    }

    /**
     * Return the first ordered source position.
     *
     * @return  int  Stable positive event sequence.
     *
     * @since   2.0.0
     */
    public function sequence(): int
    {
        return 1;
    }

    /**
     * Return the immutable test event identity.
     *
     * @return  string  Stable event identifier.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return 'generated-lifecycle-event';
    }

    /**
     * Return the package-owned event type from the generated manifest.
     *
     * @return  string  Exact signed event type.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Return the signed schema generation.
     *
     * @return  int  Schema version one.
     *
     * @since   2.0.0
     */
    public function schemaVersion(): int
    {
        return 1;
    }

    /**
     * Return a deterministic event time.
     *
     * @return  DateTimeImmutable  Fixed UTC occurrence time.
     *
     * @since   2.0.0
     */
    public function occurredAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-29T00:00:00+00:00');
    }

    /**
     * Return the exact payload admitted by the generated event schema.
     *
     * @return  array{item_id: string, title: string}  One bounded item projection input.
     *
     * @since   2.0.0
     */
    public function payload(): array
    {
        return ['item_id' => 'generated-item', 'title' => 'Canonical lifecycle execution'];
    }

    /**
     * Fingerprint the immutable projection input.
     *
     * @return  string  Lowercase SHA-256 of the event payload.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        return hash('sha256', (string) json_encode($this->payload(), JSON_THROW_ON_ERROR));
    }
}

/**
 * Bounded test-owned writer exposing only the rows emitted through the canonical projection port.
 *
 * @since  2.0.0
 */
final class GeneratedLifecycleProjectionWriter implements ProjectionWriter
{
    /**
     * Rows written by the generated projection.
     *
     * @var    list<array{key: array<string, bool|int|string>, values: array<string, bool|int|string|null>}>
     * @since  2.0.0
     */
    public array $writes = [];

    /**
     * Capture one deterministic projection upsert.
     *
     * @param   array<string, bool|int|string>       $key     Exact derived-row key.
     * @param   array<string, bool|int|string|null>  $values  Exact derived-row values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function put(array $key, array $values): void
    {
        $this->writes[] = ['key' => $key, 'values' => $values];
    }

    /**
     * Refuse an unexpected delete from the generated upsert-only projection.
     *
     * @param   array<string, bool|int|string>  $key  Candidate derived-row key.
     *
     * @return  void
     *
     * @throws  RuntimeException  Always; this acceptance event must produce one upsert.
     *
     * @since   2.0.0
     */
    public function remove(array $key): void
    {
        throw new RuntimeException('The generated lifecycle projection unexpectedly removed a row.');
    }
}
