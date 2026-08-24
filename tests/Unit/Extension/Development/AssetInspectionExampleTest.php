<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Development;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessDefinition\Domain\ComputationMode;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\PortalOperation;
use Kumwe\App\BusinessDefinition\Domain\Sensitivity;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\App\BusinessIntegration\Application\PayloadSchemaValidator;
use Kumwe\App\BusinessIntegration\Domain\ConsumerIdempotency;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\App\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\App\BusinessIntegration\Domain\WebhookContributionDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\MoneyRateProvider;
use Kumwe\App\BusinessRecord\Application\UnitConversionProvider;
use Kumwe\App\BusinessRecord\Domain\ExactDecimalArithmetic;
use Kumwe\App\BusinessRecord\Domain\MoneyConversionRequest;
use Kumwe\App\BusinessRecord\Domain\MoneyExchangeRate;
use Kumwe\App\BusinessRecord\Domain\MoneyRateProviderDefinition;
use Kumwe\App\BusinessRecord\Domain\MoneyRoundingMode;
use Kumwe\App\BusinessRecord\Domain\MoneyValue;
use Kumwe\App\BusinessRecord\Domain\QuantityRoundingMode;
use Kumwe\App\BusinessRecord\Domain\QuantityValue;
use Kumwe\App\BusinessRecord\Domain\UnitConversionFactor;
use Kumwe\App\BusinessRecord\Domain\UnitConversionRequest;
use Kumwe\App\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionCommand;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionContract;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionHandler;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionResult;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessSchema;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentation;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationContext;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationContribution;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationRequest;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresenter;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldWidget;
use Kumwe\App\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\App\Extension\Contribution\CompositionBlockDeclaration;
use Kumwe\App\Extension\Contribution\CompositionDesignVocabularyDeclaration;
use Kumwe\App\Extension\Contribution\CompositionFieldControlDeclaration;
use Kumwe\App\Extension\Contribution\CompositionInspectorDeclaration;
use Kumwe\App\Extension\Contribution\CompositionMigrationDeclaration;
use Kumwe\App\Extension\Contribution\CompositionPatternDeclaration;
use Kumwe\App\Extension\Contribution\CompositionPropertySchema;
use Kumwe\App\Extension\Contribution\CompositionPropertyType;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\ManifestContributionSet;
use Kumwe\App\Extension\Contribution\OwnedExtensionContributionRegistrar;
use Kumwe\App\Extension\Contribution\TranslationGroupDeclaration;
use Kumwe\App\Extension\Contribution\UnitConversionProviderDefinition;
use Kumwe\App\Extension\Development\DeterministicPackageBuilder;
use Kumwe\App\Extension\Development\PackageInspector;
use Kumwe\App\Extension\Development\PackageSigner;
use Kumwe\App\Extension\Development\ProtectedSigningKeyReader;
use Kumwe\App\Extension\Development\StaticConformanceRunner;
use Kumwe\App\Extension\Domain\ExtensionIdentifier;
use Kumwe\App\Extension\Domain\ExtensionManifest;
use Kumwe\App\Extension\Domain\PackageSignature;
use Kumwe\App\Extension\Infrastructure\Package\ZipArchiveReader;
use Kumwe\App\Extension\Runtime\RestrictedExtensionContainer;
use Kumwe\App\Identity\Domain\Capability;
use Kumwe\App\Tests\Support\AssetInspectionDeploymentAcceptance;
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
 * @phpstan-type FullRegistryAdditions array{
 *     field_type: FieldTypeDefinition,
 *     field_presentation: FieldPresentationContribution,
 *     business_definitions: list<EntityTypeDefinition>,
 *     action_definition: EntityTypeDefinition,
 *     custom_action: CustomBusinessActionContract,
 *     event_schema: EventSchemaDefinition,
 *     webhook: WebhookContributionDefinition,
 *     money_rate: MoneyRateProviderDefinition,
 *     unit_conversion: UnitConversionProviderDefinition,
 *     translation_group: TranslationGroupDeclaration,
 *     composition_block: CompositionBlockDeclaration,
 *     composition_pattern: CompositionPatternDeclaration,
 *     composition_control: CompositionFieldControlDeclaration,
 *     composition_inspector: CompositionInspectorDeclaration,
 *     composition_vocabulary: CompositionDesignVocabularyDeclaration,
 *     composition_migration: CompositionMigrationDeclaration
 * }
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
     * Prove one non-core fixture occupies every registry and keeps declarations, code, and authority aligned.
     *
     * The registry key list is read from the live registry set rather than repeated here. A newly added
     * registry therefore makes this test fail until the fixture declares and registers a real entry for it.
     * Executable integration entries, the semantic field presenter, and the custom action are resolved from
     * their live registries after complete graph validation; declarative schedules are tied back to the job
     * and queue they name. Navigation and action capabilities are checked against the operational policy
     * registry, so a metadata-only contribution cannot masquerade as an authorized use case.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFullRegistryFixtureHasLiveDeclarationExecutionAndAuthorizationParity(): void
    {
        $runtime = $this->fullRegistryFixture();
        $declarations = $runtime['declarations'];
        $registries = $runtime['registries'];
        $additions = $runtime['additions'];
        $owner = $declarations->owner;
        $manifest = $declarations->toArray();
        $inventory = $registries->inventory($owner);

        $canonical = $runtime['canonical'];
        $canonicalManifest = $canonical->toArray();
        $canonicalInventory = $registries->inventory($canonical->owner);

        foreach ($registries->surfaceKeys() as $surface) {
            $manifestPath = match ($surface) {
                'integration.money_rate_providers' => 'integration.rate_providers',
                'integration.unit_conversion_providers' => 'integration.unit_converters',
                default => $surface,
            };
            // The canonical Studio surfaces belong to the companion SPI-v4 owner; every other
            // surface stays with the paraphrase owner the committed example package declares.
            $onCanonicalSurface = in_array(
                $surface,
                ['composition.documents', 'composition.host_bindings'],
                true,
            );
            $expected = self::contributionList($onCanonicalSurface ? $canonicalManifest : $manifest, $manifestPath);
            $actual = self::contributionList($onCanonicalSurface ? $canonicalInventory : $inventory, $surface);
            self::assertNotSame([], $expected, sprintf('The full fixture left %s vacuous.', $surface));
            self::assertCount(count($expected), $actual, sprintf('Live registry %s drifted.', $surface));
            foreach ($expected as $index => $declaration) {
                if ($surface === 'administrator.navigation') {
                    $declaredPath = $declaration['path'] ?? null;
                    self::assertIsString($declaredPath);
                    self::assertSame(
                        '/administrator/extensions/' . $owner->identifier()
                            . ($declaredPath === '/' ? '' : $declaredPath),
                        $actual[$index]['href'] ?? null,
                        'Live administrator navigation does not preserve its confined declared path.',
                    );
                    unset($declaration['path']);
                }
                self::assertSame(
                    $declaration,
                    array_intersect_key($actual[$index], $declaration),
                    sprintf('Live registry %s does not match its signed declaration.', $surface),
                );
            }
        }

        $executableRegistries = [
            [$declarations->domainListeners(), $registries->domainListeners()],
            [$declarations->eventConsumers(), $registries->eventConsumers()],
            [$declarations->jobs(), $registries->jobs()],
            [$declarations->projections(), $registries->projections()],
            [$declarations->webhooks(), $registries->webhooks()],
            [$declarations->moneyRateProviders(), $registries->moneyRateProviders()],
            [$declarations->unitConversionProviders(), $registries->unitConversionProviders()],
        ];
        foreach ($executableRegistries as [$declared, $registry]) {
            foreach ($declared as $definition) {
                self::assertIsObject($registry->implementation($owner, $definition->identifier()));
            }
            $owned = array_values(array_filter(
                $registry->executableEntries(),
                static fn (array $entry): bool => $entry['owner']->identifier() === $owner->identifier(),
            ));
            self::assertNotSame([], $declared);
            self::assertCount(count($declared), $owned);
        }

        $asAt = new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00');
        $moneyRequest = new MoneyConversionRequest(
            new MoneyValue(ExactDecimalArithmetic::fromLiteral('10.00'), 'EUR'),
            'NAD',
            $asAt,
            18,
            2,
            MoneyRoundingMode::HalfEven,
        );
        $moneyProvider = $registries->moneyRateProviders()->implementation(
            $owner,
            $additions['money_rate']->identifier(),
        );
        self::assertInstanceOf(MoneyRateProvider::class, $moneyProvider);
        self::assertTrue($moneyProvider->supports($moneyRequest));
        self::assertTrue($moneyRequest->answeredBy($moneyProvider->rateFor($moneyRequest)));

        $unitRequest = new UnitConversionRequest(
            new QuantityValue(ExactDecimalArithmetic::fromLiteral('2.00'), 'case'),
            'unit',
            $asAt,
            18,
            2,
            QuantityRoundingMode::HalfUp,
        );
        $unitProvider = $registries->unitConversionProviders()->implementation(
            $owner,
            $additions['unit_conversion']->identifier(),
        );
        self::assertInstanceOf(UnitConversionProvider::class, $unitProvider);
        self::assertTrue($unitProvider->supports($unitRequest));
        self::assertTrue($unitRequest->answeredBy($unitProvider->factorFor($unitRequest)));

        $webhookTransport = $registries->webhooks()->implementation(
            $owner,
            $additions['webhook']->identifier(),
        );
        self::assertInstanceOf(IntegrationEventTransport::class, $webhookTransport);
        $webhookTransport->publish(new IntegrationEvent(
            $additions['event_schema']->eventType(),
            $additions['event_schema']->schemaVersion(),
            '019bc200-0000-7000-8000-000000000100',
            $asAt,
            null,
            SystemIdentity::Worker->value,
            'default',
            null,
            'registry-parity',
            'registry-parity-aggregate',
            1,
            'registry-parity-correlation',
            'registry-parity-causation',
            EventSensitivity::INTERNAL,
            ['status' => 'accepted'],
        ));

        $scheduleCount = 0;
        foreach ($declarations->schedules() as $schedule) {
            ++$scheduleCount;
            self::assertNotNull($registries->jobs()->definition($owner, $schedule->jobType()));
            self::assertNotNull($registries->queues()->definition($owner, $schedule->queue()));
            self::assertIsObject($registries->jobs()->implementation($owner, $schedule->jobType()));
        }
        self::assertGreaterThan(0, $scheduleCount, 'The durable schedule parity proof is vacuous.');

        $definitionOwner = DefinitionOwner::extension($owner->identifier());
        $action = $additions['custom_action'];
        $operation = IdempotencyKey::fromString('registry-parity-operation');
        $result = $registries->customBusinessActionHandlers()->execute(
            $definitionOwner,
            $action->handler,
            $action->schema,
            new CustomBusinessActionCommand(
                ExecutionContext::issueSystem(
                    new \stdClass(),
                    SystemIdentity::Worker,
                    SiteContext::default(),
                    'registry-parity-request',
                ),
                $additions['action_definition']->handle,
                '019bc200-0000-7000-8000-000000000099',
                1,
                'acknowledge',
                $operation,
                ['reason' => 'Reviewed'],
            ),
        );
        self::assertSame(['status' => 'accepted'], $result->data);
        self::assertTrue($result->operationId->equals($operation));

        $severityField = null;
        foreach ($additions['action_definition']->fields() as $field) {
            if ($field->handle === 'severity') {
                $severityField = $field;
                break;
            }
        }
        self::assertNotNull($severityField);
        $presentation = $registries->fieldPresentations()->present(new FieldPresentationRequest(
            $severityField,
            $additions['field_type'],
            FieldPresentationContext::Detail,
            'warning',
        ));
        self::assertSame('warning', $presentation->display);
        self::assertSame(FieldWidget::Output, $presentation->widget);
        $editor = $registries->fieldPresentations()->present(new FieldPresentationRequest(
            $severityField,
            $additions['field_type'],
            FieldPresentationContext::Update,
            'critical',
            editable: true,
        ));
        self::assertSame(FieldWidget::Select, $editor->widget);
        self::assertTrue($editor->editable);
        self::assertSame('critical', $editor->inputValue);
        self::assertContains(['value' => 'critical', 'label' => 'critical'], $editor->options);

        $policies = $registries->authorizationPolicies();
        $surfaceDefinitions = [];
        foreach ($declarations->interfaceSurfaces() as $surface) {
            $surfaceDefinitions[$surface->identifier()] = $surface;
        }
        $navigationCount = 0;
        foreach ($declarations->navigation() as $navigation) {
            ++$navigationCount;
            $surface = $surfaceDefinitions[$navigation->surface ?? ''] ?? null;
            self::assertNotNull($surface);
            self::assertContains($navigation->capability, array_map(
                static fn (Capability $capability): string => $capability->value(),
                $surface->declaration->capabilities,
            ));
            self::assertTrue($policies->supports(
                Capability::fromString($navigation->capability),
                AuthorizationResource::item('administrator_session', 'registry-parity'),
            ));
            self::assertContains(
                $navigation->id,
                array_column($registries->navigation()->visible([$navigation->capability => true]), 'id'),
            );
        }
        foreach ($declarations->portalNavigation() as $navigation) {
            ++$navigationCount;
            $surface = $surfaceDefinitions[$navigation->surface ?? ''] ?? null;
            self::assertNotNull($surface);
            self::assertContains($navigation->capability, array_map(
                static fn (Capability $capability): string => $capability->value(),
                $surface->declaration->capabilities,
            ));
            self::assertTrue($policies->supports(
                Capability::fromString($navigation->capability),
                AuthorizationResource::item('portal_session', 'registry-parity'),
            ));
            self::assertContains(
                $navigation->id,
                array_column($registries->portalNavigation()->visible([$navigation->capability => true]), 'id'),
            );
        }
        self::assertGreaterThan(0, $navigationCount, 'The non-core navigation parity proof is vacuous.');

        $actionCount = 0;
        foreach ($declarations->businessDefinitions() as $definition) {
            foreach ($definition->actions() as $definitionAction) {
                ++$actionCount;
                self::assertTrue($policies->supports(
                    Capability::fromString($definitionAction->capability),
                    AuthorizationResource::item('business_record', $definition->id),
                ));
            }
        }
        self::assertGreaterThan(0, $actionCount, 'The non-core action parity proof is vacuous.');

        $definitionHandles = array_fill_keys(array_map(
            static fn (EntityTypeDefinition $definition): string => $definition->handle,
            $declarations->businessDefinitions(),
        ), true);
        $reportCount = 0;
        foreach ($declarations->reports() as $report) {
            ++$reportCount;
            self::assertArrayHasKey($report->sourceDefinition, $definitionHandles);
            self::assertNotNull($registries->reports()->definition($owner, $report->identifier()));
            self::assertTrue($policies->supports(
                Capability::fromString($report->requiredCapability),
                AuthorizationResource::item('business_report', $report->identifier()),
            ));
        }
        self::assertGreaterThan(0, $reportCount, 'The report authorization parity proof is vacuous.');

        $registries->remove($owner);
        $removed = $registries->inventory($owner);
        foreach ($registries->surfaceKeys() as $surface) {
            self::assertSame([], self::contributionList($removed, $surface));
        }
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
     * Materialize the deliberately complete non-core contribution fixture through the real provider path.
     *
     * @return  array{
     *              declarations: ManifestContributionSet,
     *              registries: ExtensionContributionRegistrySet,
     *              additions: FullRegistryAdditions,
     *              canonical: ManifestContributionSet
     *          }  Reconciled declarations, validated live registries, the added executable contracts,
     *          and the companion canonical composition set carrying the Studio document surface.
     *
     * @since   2.0.0
     */
    private function fullRegistryFixture(): array
    {
        $base = self::manifest()->contributions();
        $additions = self::fullRegistryAdditions();
        $declarations = self::fullRegistryDeclarations($base, $additions);
        $registries = new ExtensionContributionRegistrySet();
        $records = (new \ReflectionClass(BusinessRecordService::class))->newInstanceWithoutConstructor();
        $container = new RestrictedExtensionContainer(Definitions::OWNER, [
            BusinessRecordService::class => $records,
        ]);
        $provider = new Provider();
        $provider->register($container);
        $registrar = $registries->registrar($declarations->owner, $declarations);
        $provider->contribute($registrar, $container);
        $this->contributeFullRegistryAdditions($registrar, $additions);
        $registrar->complete();
        // One manifest carries one composition grammar, so the canonical Studio document surface is
        // filled by a companion SPI-v4 owner instead of widening the paraphrase owner's declarations.
        $canonical = self::canonicalCompositionFixture();
        $canonicalRegistrar = $registries->registrar($canonical->owner, $canonical);
        foreach ($canonical->capabilities() as $capability) {
            $canonicalRegistrar->capability($capability);
        }
        foreach ($canonical->canonicalCompositionDocuments() as $document) {
            $canonicalRegistrar->canonicalCompositionDocument($document);
        }
        $canonicalRegistrar->complete();
        $registries->validateBusinessDefinitions();
        $registries->validateIntegrationContributions();

        return [
            'declarations' => $declarations,
            'registries' => $registries,
            'additions' => $additions,
            'canonical' => $canonical,
        ];
    }

    /**
     * Parse the signed manifest-6 fixture into the canonical composition declaration set.
     *
     * The canonical Studio surface is deliberately unshareable with the schema-5 paraphrase
     * vocabulary: a package declares one grammar or the other, never both. The exhaustive runtime
     * fixture therefore pairs the SPI-v3 owner with this companion SPI-v4 owner so every registry
     * surface still carries a non-core contribution.
     *
     * @return  ManifestContributionSet  The manifest-6 generation fixture's declared contributions.
     *
     * @since   2.0.0
     */
    private static function canonicalCompositionFixture(): ManifestContributionSet
    {
        $manifest = file_get_contents(
            dirname(__DIR__, 3) . '/Fixtures/ExtensionApi/generations/manifest-6/kumwe.json',
        );
        self::assertIsString($manifest);
        $document = json_decode($manifest, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $contributions = $document['contributions'] ?? null;
        self::assertIsArray($contributions);

        return ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('kumwe/contract-manifest-six'),
            $contributions,
            6,
        );
    }

    /**
     * Build the entries absent from the committed SPI-v2 example so every registry has a non-core owner.
     *
     * The additional business definitions reuse the independently maintained announcements fixture after
     * owner substitution. One typed action is appended to that definition graph, while the remaining
     * declarations are the smallest valid representatives of their public extension contracts.
     *
     * @return  FullRegistryAdditions  Typed declarations that make the runtime fixture exhaustive.
     *
     * @since   2.0.0
     */
    private static function fullRegistryAdditions(): array
    {
        $commandSchema = new CustomBusinessSchema([
            'type' => 'object',
            'properties' => [
                'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
            ],
            'required' => ['reason'],
            'additionalProperties' => false,
        ]);
        $resultSchema = new CustomBusinessSchema([
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['accepted'], 'maxLength' => 16],
            ],
            'required' => ['status'],
            'additionalProperties' => false,
        ]);
        $customAction = new CustomBusinessActionContract(
            'kumwe.asset-inspection-example.actions.acknowledge',
            'kumwe.asset-inspection-example.schemas.acknowledge-v1',
            $commandSchema,
            $resultSchema,
        );

        $announcements = file_get_contents(
            dirname(__DIR__, 4) . '/examples/extensions/announcements/kumwe.json',
        );
        self::assertIsString($announcements);
        $announcements = strtr($announcements, [
            'kumwe/announcements-example' => Definitions::OWNER,
            'kumwe.announcements-example' => 'kumwe.asset-inspection-example',
        ]);
        $document = json_decode($announcements, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $contributions = $document['contributions'] ?? null;
        self::assertIsArray($contributions);
        $business = $contributions['business'] ?? null;
        self::assertIsArray($business);
        $fieldTypes = $business['field_types'] ?? null;
        $definitionDocuments = $business['definitions'] ?? null;
        self::assertIsArray($fieldTypes);
        self::assertIsArray($definitionDocuments);
        self::assertArrayHasKey(0, $fieldTypes);
        self::assertIsArray($fieldTypes[0]);
        $fieldType = FieldTypeDefinition::fromArray($fieldTypes[0]);

        $businessDefinitions = [];
        $actionDefinition = null;
        foreach ($definitionDocuments as $definitionDocument) {
            self::assertIsArray($definitionDocument);
            $carriesCustomAction = ($definitionDocument['handle'] ?? null)
                === 'kumwe.asset-inspection-example.announcement';
            if ($carriesCustomAction) {
                $actions = $definitionDocument['actions'] ?? null;
                self::assertIsArray($actions);
                $actions[] = [
                    'handle' => 'acknowledge',
                    'label' => 'Acknowledge',
                    'capability' => InspectionAccessPolicy::MANAGE,
                    'bulk' => false,
                    'administrator' => true,
                    'portal' => false,
                    'public' => false,
                    'high_impact' => false,
                    'condition' => null,
                    'transition' => null,
                    'handler' => $customAction->handler,
                    'schema' => $customAction->schema,
                ];
                $definitionDocument['actions'] = $actions;
            }
            $definition = EntityTypeDefinition::fromArray($definitionDocument);
            $businessDefinitions[] = $definition;
            if ($carriesCustomAction) {
                $actionDefinition = $definition;
            }
        }
        self::assertCount(2, $businessDefinitions);
        self::assertInstanceOf(EntityTypeDefinition::class, $actionDefinition);

        $eventSchema = new EventSchemaDefinition(
            'kumwe.asset-inspection-example.registry-parity',
            1,
            EventSensitivity::INTERNAL,
            [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'maxLength' => 16],
                ],
                'required' => ['status'],
                'additionalProperties' => false,
            ],
            4096,
        );
        $webhook = new WebhookContributionDefinition(
            'kumwe.asset-inspection-example.registry-parity-webhook',
            [$eventSchema->eventType()],
            [$eventSchema->schemaVersion()],
            '1.0.0',
            'kumwe.asset-inspection-example.integration',
        );
        $block = new CompositionBlockDeclaration(
            'kumwe.asset-inspection-example.registry-card',
            new CompositionPropertySchema([
                'heading' => [
                    'type' => CompositionPropertyType::String->value,
                    'required' => true,
                    'maximum_length' => 120,
                ],
            ]),
            ['body'],
            'kumwe.asset-inspection-example.registry-card-renderer',
            2,
        );

        return [
            'field_type' => $fieldType,
            'field_presentation' => new FieldPresentationContribution(
                $fieldType->id,
                FieldPresentationContext::cases(),
            ),
            'business_definitions' => $businessDefinitions,
            'action_definition' => $actionDefinition,
            'custom_action' => $customAction,
            'event_schema' => $eventSchema,
            'webhook' => $webhook,
            'money_rate' => new MoneyRateProviderDefinition(
                'kumwe.asset-inspection-example.registry-rates',
                ['EUR', 'NAD'],
            ),
            'unit_conversion' => new UnitConversionProviderDefinition(
                'kumwe.asset-inspection-example.registry-units',
                ['case', 'unit'],
            ),
            'translation_group' => new TranslationGroupDeclaration(
                'kumwe.asset-inspection-example.registry-guides',
                ['en-GB', 'de'],
                'en-GB',
            ),
            'composition_block' => $block,
            'composition_pattern' => new CompositionPatternDeclaration(
                'kumwe.asset-inspection-example.registry-layout',
                [$block->identifier()],
            ),
            'composition_control' => new CompositionFieldControlDeclaration(
                'kumwe.asset-inspection-example.registry-text-control',
                CompositionPropertyType::String,
            ),
            'composition_inspector' => new CompositionInspectorDeclaration(
                'kumwe.asset-inspection-example.registry-card-inspector',
                $block->identifier(),
            ),
            'composition_vocabulary' => new CompositionDesignVocabularyDeclaration(
                'kumwe.asset-inspection-example.registry-vocabulary',
                ['accent'],
                [],
                ['measure'],
            ),
            'composition_migration' => new CompositionMigrationDeclaration(
                'kumwe.asset-inspection-example.registry-card-1-2',
                $block->identifier(),
                1,
                2,
                [['action' => 'rename', 'property' => 'title', 'to' => 'heading']],
            ),
        ];
    }

    /**
     * Merge the complete test additions with every declaration from the signed asset-inspection package.
     *
     * @param   ManifestContributionSet  $base       Parsed signed package declarations.
     * @param   FullRegistryAdditions    $additions  Entries filling every otherwise-empty registry.
     *
     * @return  ManifestContributionSet  One strict owner-bound declaration graph with every surface populated.
     *
     * @since   2.0.0
     */
    private static function fullRegistryDeclarations(
        ManifestContributionSet $base,
        array $additions,
    ): ManifestContributionSet {
        return new ManifestContributionSet(
            owner: $base->owner,
            capabilities: $base->capabilities(),
            workspaces: $base->workspaces(),
            navigation: $base->navigation(),
            routes: $base->routes(),
            views: $base->views(),
            fieldTypes: array_merge($base->fieldTypes(), [$additions['field_type']]),
            businessDefinitions: array_merge(
                $base->businessDefinitions(),
                $additions['business_definitions'],
            ),
            resourcePolicies: $base->resourcePolicies(),
            portalWorkspaces: $base->portalWorkspaces(),
            portalNavigation: $base->portalNavigation(),
            portalRoutes: $base->portalRoutes(),
            portalTemplates: $base->portalTemplates(),
            customBusinessViews: $base->customBusinessViews(),
            customBusinessActions: [$additions['custom_action']],
            fieldPresentations: [$additions['field_presentation']],
            eventSchemas: array_merge($base->eventSchemas(), [$additions['event_schema']]),
            domainListeners: $base->domainListeners(),
            eventConsumers: $base->eventConsumers(),
            jobs: $base->jobs(),
            queues: $base->queues(),
            schedules: $base->schedules(),
            projections: $base->projections(),
            reports: $base->reports(),
            webhooks: [$additions['webhook']],
            spiVersion: ManifestContributionSet::COMPOSITION_SPI_VERSION,
            interfaceSurfaces: $base->interfaceSurfaces(),
            moneyRateProviders: [$additions['money_rate']],
            unitConverters: [$additions['unit_conversion']],
            contentTranslationGroups: [$additions['translation_group']],
            compositionBlocks: [$additions['composition_block']],
            compositionPatterns: [$additions['composition_pattern']],
            compositionControls: [$additions['composition_control']],
            compositionInspectors: [$additions['composition_inspector']],
            compositionVocabularies: [$additions['composition_vocabulary']],
            compositionMigrations: [$additions['composition_migration']],
        );
    }

    /**
     * Register every executable and declarative addition through the same strict registrar as a package.
     *
     * @param   OwnedExtensionContributionRegistrar  $registrar  Open registrar bound to the complete fixture.
     * @param   FullRegistryAdditions                $additions  Added declarations and executable contracts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function contributeFullRegistryAdditions(
        OwnedExtensionContributionRegistrar $registrar,
        array $additions,
    ): void {
        $registrar->fieldType($additions['field_type']);
        $registrar->fieldPresentation(
            $additions['field_presentation'],
            new class implements FieldPresenter {
                /**
                 * Return a semantic output or bounded select while preserving all caller-owned metadata.
                 *
                 * @param   FieldPresentationRequest  $request  Validated semantic presentation request.
                 *
                 * @return  FieldPresentation  Markup-free output model for registry execution proof.
                 *
                 * @since   2.0.0
                 */
                public function present(FieldPresentationRequest $request): FieldPresentation
                {
                    $editable = $request->permitsEditing();
                    $options = [];
                    $declaredOptions = $request->field->configuration['options'] ?? [];
                    if (is_array($declaredOptions)) {
                        foreach ($declaredOptions as $option) {
                            if (is_string($option)) {
                                $options[] = ['value' => $option, 'label' => $option];
                            }
                        }
                    }

                    return new FieldPresentation(
                        $request->field->handle,
                        $request->field->label,
                        $request->context,
                        $editable ? FieldWidget::Select : FieldWidget::Output,
                        is_string($request->value) ? $request->value : '',
                        $editable ? $request->value : null,
                        $editable,
                        $request->field->required,
                        $request->errors,
                        $editable ? $options : [],
                    );
                }
            },
        );
        $registrar->customBusinessActionHandler(
            $additions['custom_action'],
            new class implements CustomBusinessActionHandler {
                /**
                 * Return a bounded result tied to the exact idempotency identity the command supplied.
                 *
                 * @param   CustomBusinessActionCommand  $command  Validated command and replay guard.
                 *
                 * @return  CustomBusinessActionResult  Contract-shaped deterministic acknowledgement.
                 *
                 * @since   2.0.0
                 */
                public function handle(CustomBusinessActionCommand $command): CustomBusinessActionResult
                {
                    return new CustomBusinessActionResult(
                        ['status' => 'accepted'],
                        $command->expectedVersion + 1,
                        $command->idempotencyKey,
                    );
                }
            },
        );
        foreach ($additions['business_definitions'] as $definition) {
            $registrar->businessDefinition($definition);
        }
        $registrar->eventSchema($additions['event_schema']);
        $webhook = $additions['webhook'];
        $registrar->webhook(
            $webhook,
            new class ($webhook) implements IntegrationEventTransport {
                /**
                 * Bind this validating transport to the exact signed webhook definition.
                 *
                 * @param  WebhookContributionDefinition  $definition  Declaration reconciled by the registrar.
                 *
                 * @since  2.0.0
                 */
                public function __construct(private readonly WebhookContributionDefinition $definition)
                {
                }

                /**
                 * Return the signed adapter identity.
                 *
                 * @return  string  Exact webhook contribution identifier.
                 *
                 * @since   2.0.0
                 */
                public function identifier(): string
                {
                    return $this->definition->identifier();
                }

                /**
                 * Return the signed disclosure ceiling.
                 *
                 * @return  EventSensitivity  Maximum sensitivity admitted by the transport.
                 *
                 * @since   2.0.0
                 */
                public function sensitivityCeiling(): EventSensitivity
                {
                    return $this->definition->sensitivityCeiling();
                }

                /**
                 * Accept only events inside the signed contract; the fixture performs no external effect.
                 *
                 * @param   IntegrationEvent  $event  Validated event the real adapter would publish.
                 *
                 * @return  void
                 *
                 * @since   2.0.0
                 */
                public function publish(IntegrationEvent $event): void
                {
                    if (
                        !$this->definition->accepts($event->eventType(), $event->schemaVersion())
                        || !$event->sensitivity()->allowedBy($this->definition->sensitivityCeiling())
                    ) {
                        throw new LogicException('The registry-parity webhook rejected an undeclared event.');
                    }
                }
            },
        );
        $moneyRate = $additions['money_rate'];
        $registrar->moneyRateProvider(
            $moneyRate,
            new class ($moneyRate->identifier()) implements MoneyRateProvider {
                /**
                 * Hold the exact identity the manifest attributes conversions to.
                 *
                 * @param  string  $identifier  Signed provider identifier.
                 *
                 * @since  2.0.0
                 */
                public function __construct(private readonly string $identifier)
                {
                }

                /**
                 * Return the signed rate-provider identity.
                 *
                 * @return  string  Exact provider identifier.
                 *
                 * @since   2.0.0
                 */
                public function identifier(): string
                {
                    return $this->identifier;
                }

                /**
                 * Accept the fixture's declared EUR-to-NAD pair.
                 *
                 * @param   MoneyConversionRequest  $request  Candidate conversion request.
                 *
                 * @return  bool  True for the exercised pair, false otherwise.
                 *
                 * @since   2.0.0
                 */
                public function supports(MoneyConversionRequest $request): bool
                {
                    return $request->amount->currency === 'EUR' && $request->targetCurrency === 'NAD';
                }

                /**
                 * Supply one exact, attributed rate for the supported pair.
                 *
                 * @param   MoneyConversionRequest  $request  Candidate conversion request.
                 *
                 * @return  MoneyExchangeRate  Exact rate answering the caller's as-at request.
                 *
                 * @throws  LogicException  When callers bypass `supports()` for another pair.
                 *
                 * @since   2.0.0
                 */
                public function rateFor(MoneyConversionRequest $request): MoneyExchangeRate
                {
                    if (!$this->supports($request)) {
                        throw new LogicException('The registry-parity rate provider does not price this pair.');
                    }

                    return new MoneyExchangeRate(
                        $request->amount->currency,
                        $request->targetCurrency,
                        ExactDecimalArithmetic::fromLiteral('20.0000'),
                        $request->asAt,
                        $this->identifier,
                    );
                }
            },
        );
        $unitConversion = $additions['unit_conversion'];
        $registrar->unitConversionProvider(
            $unitConversion,
            new class ($unitConversion->identifier()) implements UnitConversionProvider {
                /**
                 * Hold the exact identity the manifest attributes conversion factors to.
                 *
                 * @param  string  $identifier  Signed provider identifier.
                 *
                 * @since  2.0.0
                 */
                public function __construct(private readonly string $identifier)
                {
                }

                /**
                 * Return the signed conversion-provider identity.
                 *
                 * @return  string  Exact provider identifier.
                 *
                 * @since   2.0.0
                 */
                public function identifier(): string
                {
                    return $this->identifier;
                }

                /**
                 * Accept the fixture's declared case-to-unit conversion.
                 *
                 * @param   UnitConversionRequest  $request  Candidate unit conversion request.
                 *
                 * @return  bool  True for the exercised conversion, false otherwise.
                 *
                 * @since   2.0.0
                 */
                public function supports(UnitConversionRequest $request): bool
                {
                    return $request->quantity->unit === 'case' && $request->targetUnit === 'unit';
                }

                /**
                 * Supply one exact, attributed factor for the supported pair.
                 *
                 * @param   UnitConversionRequest  $request  Candidate unit conversion request.
                 *
                 * @return  UnitConversionFactor  Exact factor answering the caller's as-at request.
                 *
                 * @throws  LogicException  When callers bypass `supports()` for another pair.
                 *
                 * @since   2.0.0
                 */
                public function factorFor(UnitConversionRequest $request): UnitConversionFactor
                {
                    if (!$this->supports($request)) {
                        throw new LogicException('The registry-parity unit provider does not relate this pair.');
                    }

                    return new UnitConversionFactor(
                        $request->quantity->unit,
                        $request->targetUnit,
                        ExactDecimalArithmetic::fromLiteral('12.0000'),
                        $request->asAt,
                        $this->identifier,
                    );
                }
            },
        );
        $registrar->contentTranslationGroup($additions['translation_group']);
        $registrar->compositionBlock($additions['composition_block']);
        $registrar->compositionPattern($additions['composition_pattern']);
        $registrar->compositionFieldControl($additions['composition_control']);
        $registrar->compositionInspector($additions['composition_inspector']);
        $registrar->compositionDesignVocabulary($additions['composition_vocabulary']);
        $registrar->compositionMigration($additions['composition_migration']);
    }

    /**
     * Resolve one dotted contribution path and require a canonical list of declaration objects.
     *
     * @param   array<string, mixed>  $document  Manifest or live inventory document.
     * @param   string                $path      Dotted registry path supplied by the live registry set.
     *
     * @return  list<array<string, mixed>>  Contribution documents at the requested path.
     *
     * @since   2.0.0
     */
    private static function contributionList(array $document, string $path): array
    {
        $value = $document;
        foreach (explode('.', $path) as $segment) {
            self::assertArrayHasKey($segment, $value, sprintf('Contribution path %s is absent.', $path));
            $value = $value[$segment];
            self::assertIsArray($value, sprintf('Contribution path %s is not an array.', $path));
        }
        self::assertTrue(array_is_list($value), sprintf('Contribution path %s is not a list.', $path));
        foreach ($value as $entry) {
            self::assertIsArray($entry, sprintf('Contribution path %s contains a non-object.', $path));
        }

        /** @var list<array<string, mixed>> $value */
        return $value;
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
