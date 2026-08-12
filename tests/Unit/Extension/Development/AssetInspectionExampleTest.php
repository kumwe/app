<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Development;

use Closure;
use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\ComputationMode;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\PortalOperation;
use Kumwe\CMS\BusinessDefinition\Domain\Sensitivity;
use Kumwe\CMS\BusinessIntegration\Application\PayloadSchemaValidator;
use Kumwe\CMS\BusinessIntegration\Domain\ConsumerIdempotency;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordService;
use Kumwe\CMS\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\CMS\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Extension\Development\DeterministicPackageBuilder;
use Kumwe\CMS\Extension\Development\PackageInspector;
use Kumwe\CMS\Extension\Development\PackageSigner;
use Kumwe\CMS\Extension\Development\ProtectedSigningKeyReader;
use Kumwe\CMS\Extension\Development\StaticConformanceRunner;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Domain\PackageSignature;
use Kumwe\CMS\Extension\Infrastructure\Package\ZipArchiveReader;
use Kumwe\CMS\Extension\Runtime\RestrictedExtensionContainer;
use Kumwe\CMS\Tests\Support\AssetInspectionDeploymentAcceptance;
use KumweExample\AssetInspection\Application\InspectionAccessPolicy;
use KumweExample\AssetInspection\Application\InspectionPolicyProfile;
use KumweExample\AssetInspection\Definitions;
use KumweExample\AssetInspection\Provider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(EntityTypeDefinition::class)]
#[CoversClass(PayloadSchemaValidator::class)]
#[CoversClass(PackageSafetyPolicy::class)]
#[CoversClass(ExtensionContributionRegistrySet::class)]
#[CoversClass(ManifestContributionSet::class)]
#[CoversClass(DeterministicPackageBuilder::class)]
#[CoversClass(PackageInspector::class)]
#[CoversClass(PackageSigner::class)]
#[CoversClass(ProtectedSigningKeyReader::class)]
#[CoversClass(StaticConformanceRunner::class)]
#[CoversClass(ExtensionManifest::class)]
#[CoversClass(RestrictedExtensionContainer::class)]
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
        self::assertCount(2, $contributions->interfaceSurfaces());
        self::assertCount(1, $contributions->portalRoutes());
        self::assertCount(1, $contributions->customBusinessViews());

        $portalRoute = $contributions->portalRoutes()[0];
        $portalNavigation = $contributions->portalNavigation()[0];
        self::assertSame('/', $portalRoute->path);
        self::assertSame('/', $portalNavigation->path);
        self::assertSame(InspectionAccessPolicy::VIEW, $portalRoute->capability);
        self::assertSame(InspectionAccessPolicy::VIEW, $portalNavigation->capability);
        self::assertSame('kumwe.asset-inspection-example.portal.status', $portalRoute->name);
        self::assertSame('kumwe.asset-inspection-example.portal.status', $portalRoute->template);
        self::assertSame('kumwe.asset-inspection-example.portal.status', $portalNavigation->surface);

        $summaryContract = $contributions->customBusinessViews()[0];
        self::assertSame(
            'kumwe.asset-inspection-example.views.inspection-risk-summary',
            $summaryContract->handler,
        );
        self::assertSame(
            'kumwe.asset-inspection-example.schemas.inspection-risk-summary-v1',
            $summaryContract->schema,
        );
        self::assertSame([], $summaryContract->querySchema->toArray()['required']);
        self::assertSame(
            ['heading', 'inspections', 'restricted_fields_disclosed'],
            $summaryContract->resultSchema->toArray()['required'],
        );
        self::assertSame(
            120,
            $summaryContract->resultSchema->toArray()['properties']['inspections']['items']
                ['properties']['risk_score']['maximum'],
        );
        self::assertSame(
            200,
            $summaryContract->resultSchema->toArray()['properties']['inspections']['maxItems'],
        );
        $summaryContract->resultSchema->assertValid([
            'heading' => 'Upper-bound inspection risk',
            'inspections' => [['reference' => 'MAXIMUM-RISK', 'risk_score' => 120]],
            'restricted_fields_disclosed' => false,
        ], 'view result');

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
        self::assertNull($relationships['findings']->inverse);
        self::assertNull($relationships['measurements']->inverse);
        $definitionRelationships = [];
        foreach ($contributions->businessDefinitions() as $definition) {
            $definitionRelationships[$definition->handle] = $definition->relationships();
        }
        self::assertFalse($definitionRelationships['kumwe.asset-inspection-example.location'][0]->ordered);
        self::assertFalse($definitionRelationships['kumwe.asset-inspection-example.asset'][1]->ordered);
        self::assertSame([], $definitionRelationships['kumwe.asset-inspection-example.finding']);
        self::assertSame([], $definitionRelationships['kumwe.asset-inspection-example.measurement']);
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
        $views = [];
        foreach ($inspection->views() as $view) {
            $views[$view->handle] = $view;
        }
        self::assertArrayHasKey('inspection_risk_summary', $views);
        self::assertTrue($views['inspection_risk_summary']->administrator);
        self::assertTrue($views['inspection_risk_summary']->portal);
        self::assertSame($summaryContract->handler, $views['inspection_risk_summary']->handler);
        self::assertSame($summaryContract->schema, $views['inspection_risk_summary']->schema);

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
     * Prove SPI 2 rejects declaration-only ordering without narrowing the readable SPI-1 contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSpiTwoRequiresOrderedOneToManyToOwnItsJunction(): void
    {
        $contributions = self::manifest()->contributions();
        $location = null;
        foreach ($contributions->businessDefinitions() as $definition) {
            if ($definition->handle === 'kumwe.asset-inspection-example.location') {
                $location = $definition->toArray();
                break;
            }
        }
        self::assertIsArray($location);
        $relationships = $location['relationships'] ?? null;
        self::assertIsArray($relationships);
        self::assertArrayHasKey(0, $relationships);
        self::assertIsArray($relationships[0]);
        $relationships[0]['ordered'] = true;
        $location['relationships'] = $relationships;
        $invalid = EntityTypeDefinition::fromArray($location);

        $legacy = new ManifestContributionSet(
            owner: $contributions->owner,
            businessDefinitions: [$invalid],
            spiVersion: ManifestContributionSet::SPI_VERSION,
        );
        self::assertCount(1, $legacy->businessDefinitions());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must own inverse-free junction storage');
        new ManifestContributionSet(
            owner: $contributions->owner,
            businessDefinitions: [$invalid],
            spiVersion: ManifestContributionSet::CURRENT_SPI_VERSION,
        );
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
        self::assertFalse($profile->fields()->allows(FieldAccessUsage::Search, 'reference'));
        self::assertTrue($profile->fields()->allows(FieldAccessUsage::Relation, 'reference'));
        self::assertFalse($profile->fields()->allows(FieldAccessUsage::Relation, 'id'));
        self::assertTrue($profile->fields()->allows(FieldAccessUsage::PublicReference, 'id'));
        self::assertFalse($profile->fields()->allows(FieldAccessUsage::PublicReference, 'reference'));
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
     * Prove deployment seeding uses exact, site-scoped, least-authority operation policies.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeploymentSeedPoliciesCloseTheExampleGraphWithoutWideningTheViewerProfile(): void
    {
        $method = new ReflectionMethod(AssetInspectionDeploymentAcceptance::class, 'seedPolicyRequests');
        $requests = $method->invoke(null);
        if (!is_array($requests) || !array_is_list($requests)) {
            self::fail('The deployment seed-policy builder did not return a list.');
        }
        self::assertCount(14, $requests);

        $byCode = [];
        $operationCounts = [];
        foreach ($requests as $request) {
            if (!is_array($request) || array_is_list($request)) {
                self::fail('A deployment seed-policy request is not an object.');
            }
            $code = $request['policyCode'] ?? null;
            $operation = $request['operation'] ?? null;
            self::assertIsString($code);
            self::assertIsString($operation);
            self::assertNull($request['organizationId'] ?? null);
            self::assertSame(100, $request['priority'] ?? null);
            $byCode[$code] = $request;
            $operationCounts[$operation] = ($operationCounts[$operation] ?? 0) + 1;
        }
        self::assertSame([
            'business.record.create' => 5,
            'business.record.relate' => 5,
            'business.record.read' => 4,
        ], $operationCounts);
        $createFields = [
            'location' => ['id', 'name', 'zone'],
            'asset' => ['id', 'asset_tag', 'name', 'active'],
            'inspection' => ['id', 'reference', 'inspection_date', 'raw_score', 'adjustment', 'internal_note'],
            'finding' => ['id', 'summary', 'severity', 'remediation'],
            'measurement' => ['id', 'metric', 'value', 'unit', 'acceptable'],
        ];
        $readFields = $createFields;
        unset($readFields['inspection']);
        foreach ($createFields as $definition => $fields) {
            $prefix = 'asset-inspection-acceptance.' . $definition;
            self::assertSame(
                ['create' => $fields, 'actions' => []],
                $byCode[$prefix . '.create']['fieldRules'],
            );
            self::assertSame(
                $definition === 'location'
                    ? ['actions' => []]
                    : ['public_reference' => ['id'], 'actions' => []],
                $byCode[$prefix . '.relate']['fieldRules'],
            );
        }
        foreach ($readFields as $definition => $fields) {
            $rules = ['detail' => $fields, 'actions' => []];
            if ($definition !== 'location') {
                $rules['include'] = $fields;
                $rules['public_reference'] = ['id'];
            }
            self::assertSame($rules, $byCode['asset-inspection-acceptance.' . $definition . '.read']['fieldRules']);
        }
        self::assertSame('comparison', $byCode['asset-inspection-acceptance.inspection.relate']['predicateType']);
        self::assertSame('risk_score', $byCode['asset-inspection-acceptance.inspection.relate']['field']);
        self::assertSame('70', $byCode['asset-inspection-acceptance.inspection.relate']['value']);
        self::assertArrayNotHasKey('asset-inspection-acceptance.inspection.read', $byCode);

        foreach ($byCode as $code => $request) {
            if ($code === 'asset-inspection-acceptance.inspection.relate') {
                continue;
            }
            self::assertSame('constant', $request['predicateType']);
            self::assertNull($request['field']);
            self::assertNull($request['operator']);
            self::assertNull($request['valueType']);
            self::assertSame('true', $request['value']);
        }
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
        $records = (new \ReflectionClass(BusinessRecordService::class))->newInstanceWithoutConstructor();
        $container = new RestrictedExtensionContainer(Definitions::OWNER, [
            BusinessRecordService::class => $records,
        ]);
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
        self::assertCount(1, $inventory['business']['view_handlers']);
        self::assertNotNull($registries->customBusinessViewHandlers()->contract(
            DefinitionOwner::extension(Definitions::OWNER),
            'kumwe.asset-inspection-example.views.inspection-risk-summary',
            'kumwe.asset-inspection-example.schemas.inspection-risk-summary-v1',
        ));
        self::assertCount(2, $inventory['interface']['surfaces']);
        self::assertSame(
            [[
                'name' => 'kumwe.asset-inspection-example.portal.status',
                'path' => '/',
                'methods' => ['GET'],
                'capability' => InspectionAccessPolicy::VIEW,
                'template' => 'kumwe.asset-inspection-example.portal.status',
                'registered_name' => 'portal.extension.kumwe.asset-inspection-example.portal.status',
                'registered_path' => '/portal/extensions/kumwe/asset-inspection-example',
            ]],
            $registries->portalRoutes()->ownedBy($declarations->owner),
        );
        self::assertCount(1, $inventory['integration']['domain_listeners']);
        self::assertCount(1, $inventory['integration']['consumers']);
        self::assertCount(1, $inventory['integration']['jobs']);
        self::assertCount(1, $inventory['integration']['schedules']);
        self::assertCount(1, $inventory['integration']['projections']);
        self::assertCount(1, $inventory['integration']['reports']);

        $registries->remove($declarations->owner);
        self::assertSame([], $registries->inventory($declarations->owner)['business']['view_handlers']);
        self::assertNull($registries->customBusinessViewHandlers()->contract(
            DefinitionOwner::extension(Definitions::OWNER),
            'kumwe.asset-inspection-example.views.inspection-risk-summary',
            'kumwe.asset-inspection-example.schemas.inspection-risk-summary-v1',
        ));
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
