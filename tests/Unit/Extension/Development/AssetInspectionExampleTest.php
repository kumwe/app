<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Development;

use Closure;
use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\ComputationMode;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\PortalOperation;
use Kumwe\CMS\BusinessDefinition\Domain\Sensitivity;
use Kumwe\CMS\BusinessIntegration\Application\PayloadSchemaValidator;
use Kumwe\CMS\BusinessIntegration\Domain\ConsumerIdempotency;
use Kumwe\CMS\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\CMS\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Development\DeterministicPackageBuilder;
use Kumwe\CMS\Extension\Development\PackageInspector;
use Kumwe\CMS\Extension\Development\PackageSigner;
use Kumwe\CMS\Extension\Development\ProtectedSigningKeyReader;
use Kumwe\CMS\Extension\Development\StaticConformanceRunner;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Domain\PackageSignature;
use Kumwe\CMS\Extension\Infrastructure\Package\ZipArchiveReader;
use Kumwe\CMS\Extension\Runtime\RestrictedExtensionContainer;
use KumweExample\AssetInspection\Application\InspectionPolicyProfile;
use KumweExample\AssetInspection\Definitions;
use KumweExample\AssetInspection\Provider;
use PHPUnit\Framework\TestCase;

/**
 * Proves the committed asset-inspection source is a complete, reconciled, signable SPI-v2 package.
 *
 * @since  2.0.0
 */
final class AssetInspectionExampleTest extends TestCase
{
    /**
     * Example-namespace autoloader installed only while this test class runs.
     *
     * @var    ?Closure(string):void
     * @since  2.0.0
     */
    private static ?Closure $exampleLoader = null;

    /**
     * Register the package's own PSR-4 mapping without adding examples to the application autoloader.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $source = self::sourceDirectory() . '/src/';
        self::$exampleLoader = static function (string $class) use ($source): void {
            $prefix = 'KumweExample\\AssetInspection\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = $source . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        };
        spl_autoload_register(self::$exampleLoader, true, true);
    }

    /**
     * Remove the temporary example autoloader after this class completes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$exampleLoader instanceof Closure) {
            spl_autoload_unregister(self::$exampleLoader);
            self::$exampleLoader = null;
        }
        parent::tearDownAfterClass();
    }

    /**
     * Parse the signed declaration and prove every required neutral business and integration feature.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testManifestCarriesCompleteBusinessIntegrationProof(): void
    {
        $manifest = self::manifest();
        $contributions = $manifest->contributions();

        self::assertSame(4, $manifest->schemaVersion());
        self::assertSame(2, $contributions->spiVersion());
        self::assertSame('kumwe/asset-inspection-example', $manifest->identifier()->value());
        self::assertSame(['policies/inspection-viewer.json'], $manifest->assets());
        self::assertCount(5, $contributions->businessDefinitions());
        self::assertCount(2, $contributions->capabilities());
        self::assertCount(1, $contributions->portalRoutes());

        $inspection = self::inspection($contributions->businessDefinitions());
        $fields = [];
        foreach ($inspection->fields() as $field) {
            $fields[$field->handle] = $field;
        }
        self::assertTrue($fields['risk_score']->computed);
        self::assertTrue($fields['risk_score']->readOnly);
        self::assertSame(ComputationMode::Stored, $fields['risk_score']->computationMode);
        self::assertSame(['adjustment', 'raw_score'], $fields['risk_score']->formula?->dependencies());
        self::assertSame(Sensitivity::Restricted, $fields['internal_note']->sensitivity);
        self::assertFalse($fields['internal_note']->reportable);
        self::assertFalse($fields['internal_note']->exportable);
        self::assertNotNull($fields['internal_note']->visibilityCondition);
        self::assertNotNull($fields['internal_note']->editabilityCondition);

        $relationships = [];
        foreach ($inspection->relationships() as $relationship) {
            $relationships[$relationship->handle] = $relationship;
        }
        self::assertTrue($relationships['findings']->ordered);
        self::assertTrue($relationships['measurements']->ordered);
        self::assertSame(['draft', 'submitted', 'verified', 'closed'], $inspection->workflow?->states);
        self::assertSame(
            ['submit', 'verify', 'close'],
            array_map(
                static fn (array $transition): string => $transition['handle'],
                $inspection->workflow?->transitions ?? []
            ),
        );
        self::assertSame(
            [PortalOperation::Browse, PortalOperation::Export, PortalOperation::Read, PortalOperation::Report],
            $inspection->portalOperations(),
        );

        $listener = $contributions->domainListeners()[0];
        $consumer = $contributions->eventConsumers()[0];
        self::assertSame('core.business_record.mutated', $listener->eventType());
        self::assertSame([1], $listener->schemaVersions());
        self::assertSame('core.business_record.mutated', $consumer->eventType());
        self::assertSame(ConsumerIdempotency::AGGREGATE_VERSION, $consumer->idempotency());
        self::assertTrue($consumer->aggregateOrdered());
        self::assertSame([1], $contributions->projections()[0]->sources[0]->schemaVersions);
        self::assertSame('kumwe.asset-inspection-example.review-overdue', $contributions->jobs()[0]->identifier());
        self::assertTrue($contributions->reports()[0]->portalVisible);
        self::assertSame('kumwe.asset-inspection-example.view', $contributions->reports()[0]->requiredCapability);

        $job = $contributions->jobs()[0]->toArray();
        $schedule = $contributions->schedules()[0]->toArray();
        $validator = new PayloadSchemaValidator();
        self::assertIsArray($job['payload_schema']);
        self::assertIsArray($schedule['payload']);
        $validator->assertSchema($job['payload_schema']);
        $validator->assertPayload($job['payload_schema'], $schedule['payload']);
    }

    /**
     * Parse the signed operator profile through real row and field policy domain models.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSignedPolicyProfileIsExecutableAndDefaultDeny(): void
    {
        $json = file_get_contents(self::sourceDirectory() . '/policies/inspection-viewer.json');
        self::assertIsString($json);
        $profile = InspectionPolicyProfile::fromJson($json);

        self::assertFalse($profile->records()->allows(['risk_score' => 69]));
        self::assertTrue($profile->records()->allows(['risk_score' => 70]));
        self::assertTrue($profile->records()->allows(['risk_score' => 82]));
        self::assertFalse($profile->records()->allows([]));
        self::assertTrue($profile->fields()->allows(FieldAccessUsage::Detail, 'reference'));
        self::assertTrue($profile->fields()->allows(FieldAccessUsage::Report, 'risk_score'));
        foreach (FieldAccessUsage::cases() as $usage) {
            self::assertFalse($profile->fields()->allows($usage, 'internal_note'));
        }

        $requests = $profile->administrationRequests();
        self::assertSame([
            'business.record.browse',
            'business.record.export',
            'business.record.read',
            'business.record.report',
        ], array_column($requests, 'operation'));
        self::assertSame(
            array_fill(0, 4, Definitions::INSPECTION_DEFINITION_ID),
            array_column($requests, 'definitionId'),
        );
        self::assertSame([
            'policyCode', 'operation', 'effect', 'organizationId', 'definitionId', 'predicateType',
            'field', 'operator', 'valueType', 'value', 'fieldRules', 'priority',
        ], array_keys($requests[0]));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $profile->checksum());
    }

    /**
     * Prove package policy parsing cannot be widened to disclose the restricted inspection note.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSignedPolicyProfileRejectsRestrictedFieldDisclosure(): void
    {
        $json = file_get_contents(self::sourceDirectory() . '/policies/inspection-viewer.json');
        self::assertIsString($json);
        $document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $fieldPolicy = $document['field_policy'] ?? null;
        self::assertIsArray($fieldPolicy);
        $detail = $fieldPolicy['detail'] ?? null;
        self::assertIsArray($detail);
        $detail[] = 'internal_note';
        $fieldPolicy['detail'] = $detail;
        $document['field_policy'] = $fieldPolicy;
        $unsafe = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertIsString($unsafe);

        $this->expectException(InvalidArgumentException::class);
        InspectionPolicyProfile::fromJson($unsafe);
    }

    /**
     * Execute strict provider reconciliation and assembled business/event graph validation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProviderExactlyReconcilesEverySignedContribution(): void
    {
        $declarations = self::manifest()->contributions();
        $registries = new ExtensionContributionRegistrySet();
        $container = new RestrictedExtensionContainer(Definitions::OWNER, []);
        $provider = new Provider();
        $provider->register($container);
        $registrar = $registries->registrar($declarations->owner, $declarations);
        $provider->contribute($registrar, $container);
        $registrar->complete();
        $registries->validateBusinessDefinitions();
        $catalog = $registries->validateIntegrationContributions();

        self::assertSame(
            'kumwe.asset-inspection-example.inspection-mutation-indexer',
            $catalog->consumer('kumwe.asset-inspection-example.inspection-mutation-indexer')->identifier(),
        );
        $inventory = $registries->inventory($declarations->owner);
        self::assertCount(5, $inventory['business']['definitions']);
        self::assertCount(1, $inventory['integration']['domain_listeners']);
        self::assertCount(1, $inventory['integration']['consumers']);
        self::assertCount(1, $inventory['integration']['jobs']);
        self::assertCount(1, $inventory['integration']['schedules']);
        self::assertCount(1, $inventory['integration']['projections']);
        self::assertCount(1, $inventory['integration']['reports']);
    }

    /**
     * Build two identical packages, run code-free conformance, and sign the verified archive.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExampleBuildsReproduciblyAndProducesAVerifiableSignature(): void
    {
        $temporary = sys_get_temp_dir() . '/kumwe-asset-inspection-example-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($temporary, 0700));
        $firstPath = $temporary . '/first.zip';
        $secondPath = $temporary . '/second.zip';
        $keyPath = $temporary . '/release.seed';
        try {
            $inspector = new PackageInspector(new ZipArchiveReader(), new PackageSafetyPolicy());
            $builder = new DeterministicPackageBuilder($inspector);
            $first = $builder->build(self::sourceDirectory(), $firstPath);
            $second = $builder->build(self::sourceDirectory(), $secondPath);
            self::assertSame((string) $first->inspection->checksum, (string) $second->inspection->checksum);
            self::assertTrue((new StaticConformanceRunner($inspector))->run($first->archive)->conforms());

            $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
            self::assertSame(64, file_put_contents($keyPath, bin2hex($seed), LOCK_EX));
            self::assertTrue(chmod($keyPath, 0600));
            $signature = (new PackageSigner(new ProtectedSigningKeyReader(), $inspector))->sign(
                $first->archive,
                'asset-inspection-example-test',
                $keyPath,
            );
            $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
            self::assertTrue(sodium_crypto_sign_verify_detached(
                PackageSignature::ed25519($signature->keyId, $signature->base64Signature)->bytes(),
                $signature->packageSha256,
                $publicKey,
            ));
        } finally {
            foreach ([$firstPath, $secondPath, $keyPath] as $path) {
                if (is_file($path) && !is_link($path)) {
                    unlink($path);
                }
            }
            if (is_dir($temporary) && !is_link($temporary)) {
                rmdir($temporary);
            }
        }
    }

    /**
     * Parse the committed package manifest through the production domain boundary.
     *
     * @return  ExtensionManifest  Strict schema-4 example manifest.
     *
     * @since   2.0.0
     */
    private static function manifest(): ExtensionManifest
    {
        $json = file_get_contents(self::sourceDirectory() . '/kumwe.json');
        self::assertIsString($json);

        return ExtensionManifest::fromJson($json);
    }

    /**
     * Resolve the inspection definition from the five related package definitions.
     *
     * @param   list<EntityTypeDefinition>  $definitions  Parsed signed business declarations.
     *
     * @return  EntityTypeDefinition  Inspection definition with workflow and ordered relations.
     *
     * @since   2.0.0
     */
    private static function inspection(array $definitions): EntityTypeDefinition
    {
        foreach ($definitions as $definition) {
            if ($definition->handle === 'kumwe.asset-inspection-example.inspection') {
                return $definition;
            }
        }
        self::fail('The asset-inspection manifest has no inspection definition.');
    }

    /**
     * Return the canonical committed package source directory.
     *
     * @return  string  Absolute repository path to the example source root.
     *
     * @since   2.0.0
     */
    private static function sourceDirectory(): string
    {
        return dirname(__DIR__, 4) . '/examples/extensions/asset-inspection';
    }
}
