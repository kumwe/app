<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSurface\Application;

use Kumwe\CMS\Application\Authorization\AuthenticatedSurface;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\AuthorizationDecision;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeDefinitionResolver;
use Kumwe\CMS\BusinessDefinition\Domain\BuiltInFieldTypes;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\CMS\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\CMS\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\CMS\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\CMS\BusinessSecurity\Application\FieldDisclosurePlan;
use Kumwe\CMS\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\CMS\BusinessSecurity\Policy\RecordPolicySchema;
use Kumwe\CMS\BusinessSecurity\Policy\RecordPolicySet;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurface;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\CMS\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BusinessSurfaceCatalog::class)]
/**
 * Proves generated metadata uses row authority directly rather than field presence as its proxy.
 *
 * @since  2.0.0
 */
final class BusinessSurfaceCatalogTest extends TestCase
{
    /**
     * Published definition identity shared by catalog authority fixtures.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string DEFINITION_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb702';

    /**
     * Proves an explicit row denial with populated field rules cannot leak definition metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConstantRowDenialOmitsMetadataEvenWhenFieldRulesArePopulated(): void
    {
        $plan = new BusinessRecordAccessPlan(
            self::DEFINITION_ID,
            'business.record.read',
            new RecordPolicySet(
                new RecordPolicySchema([]),
                [new RecordPolicyConstant(true)],
                [new RecordPolicyConstant(true)],
            ),
            new FieldDisclosurePlan(['detail' => ['name']]),
            hash('sha256', 'denied-policy'),
        );
        $catalog = $this->catalog($plan);
        $context = $this->context(BusinessSurface::Api, ['business.record.read']);

        self::assertSame([], $catalog->definitions($context, BusinessSurface::Api, BusinessSurfaceOperation::Read));

        $this->expectException(BusinessRecordDefinitionUnavailable::class);
        $catalog->definition(
            $context,
            BusinessSurface::Api,
            'site.default.catalog_authority_test',
            BusinessSurfaceOperation::Read,
        );
    }

    /**
     * Proves row-authorized fieldless lifecycle metadata remains valid on every generated delivery surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRowAuthorizedFieldlessLifecycleMetadataRemainsAvailableOnEverySurface(): void
    {
        $plan = new BusinessRecordAccessPlan(
            self::DEFINITION_ID,
            'business.record.delete',
            new RecordPolicySet(new RecordPolicySchema([]), [new RecordPolicyConstant(true)]),
            new FieldDisclosurePlan(),
            hash('sha256', 'allowed-policy'),
        );
        $catalog = $this->catalog($plan);

        foreach (BusinessSurface::cases() as $surface) {
            $context = $this->context($surface, ['business.record.delete']);
            $metadata = $catalog->definition(
                $context,
                $surface,
                'site.default.catalog_authority_test',
                BusinessSurfaceOperation::Delete,
            );

            self::assertSame([], $metadata['fields'], $surface->value);
            self::assertSame([], $metadata['views'], $surface->value);
            self::assertSame([], $metadata['actions'], $surface->value);
            self::assertCount(
                1,
                $catalog->definitions($context, $surface, BusinessSurfaceOperation::Delete),
                $surface->value,
            );
            self::assertSame(['delete' => true], $catalog->operations(
                $context,
                $surface,
                'site.default.catalog_authority_test',
            ), $surface->value);
        }
    }

    /**
     * Proves a checker may resolve portal approval exposure without inheriting the maker's action grant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testApprovalExposurePreservesApproverOnlySeparationOfDuties(): void
    {
        $plan = new BusinessRecordAccessPlan(
            self::DEFINITION_ID,
            'business.record.delete',
            new RecordPolicySet(new RecordPolicySchema([]), [new RecordPolicyConstant(true)]),
            new FieldDisclosurePlan(),
            hash('sha256', 'approval-exposure-policy'),
        );
        $catalog = $this->catalog($plan);
        $context = $this->context(BusinessSurface::Portal, ['business.approval.approve']);
        $request = '0191574f-f0b8-7bf3-a9aa-91c6b8244e21';

        self::assertSame([$request => true], $catalog->approvalActions(
            $context,
            BusinessSurface::Portal,
            [[
                'request_id' => $request,
                'definition_id' => self::DEFINITION_ID,
                'action' => 'approve',
            ]],
        ));
    }

    /**
     * Build a shared catalog around one exact access-plan decision.
     *
     * @param   BusinessRecordAccessPlan  $plan  Row and field authority returned for the requested operation.
     *
     * @return  BusinessSurfaceCatalog  Fully executable catalog fixture.
     *
     * @since   2.0.0
     */
    private function catalog(BusinessRecordAccessPlan $plan): BusinessSurfaceCatalog
    {
        $definition = EntityTypeDefinition::fromArray($this->definition());
        $resolved = (new ReflectionClass(ResolvedBusinessDefinition::class))->newInstanceWithoutConstructor();
        (new ReflectionClass(ResolvedBusinessDefinition::class))
            ->getProperty('definition')
            ->setValue($resolved, $definition);
        $definitions = $this->createStub(BusinessRecordDefinitionResolver::class);
        $definitions->method('activeInstalled')->willReturn([$resolved]);
        $access = $this->createStub(BusinessRecordAccessController::class);
        $access->method('plan')->willReturn($plan);
        $fieldTypes = $this->createStub(FieldTypeDefinitionResolver::class);
        $fieldTypes->method('get')->willReturn($this->fieldType('core.text'));
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturnCallback(
            static fn (
                ExecutionContext $_context,
                Capability $capability,
                AuthorizationResource $_resource,
            ): AuthorizationDecision =>
                new AuthorizationDecision(
                    $capability->value() === $plan->operation,
                    'test',
                    $capability->value() === $plan->operation ? 'allowed' : 'denied',
                ),
        );
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return new BusinessSurfaceCatalog(
            $definitions,
            $access,
            $fieldTypes,
            $authorization,
            $transactions,
            RuntimeMaterializationState::unavailable('catalog-authority-test'),
        );
    }

    /**
     * Mint one context whose authenticated provenance exactly matches the generated surface.
     *
     * @param   BusinessSurface  $surface       Generated adapter under test.
     * @param   list<string>     $capabilities  Global capability vocabulary carried by the principal.
     *
     * @return  ExecutionContext  Provenance-bound caller context.
     *
     * @since   2.0.0
     */
    private function context(BusinessSurface $surface, array $capabilities): ExecutionContext
    {
        $authenticated = match ($surface) {
            BusinessSurface::Administrator => AuthenticatedSurface::Administrator,
            BusinessSurface::Portal => AuthenticatedSurface::Portal,
            BusinessSurface::Api => AuthenticatedSurface::Api,
            BusinessSurface::Cli => AuthenticatedSurface::Cli,
            BusinessSurface::Mcp => AuthenticatedSurface::Mcp,
        };

        return AuthorizationContext::principal($capabilities)->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'business-catalog-authority-test-0001',
            surface: $authenticated,
        );
    }

    /**
     * Return a published definition that exposes delete on every generated surface.
     *
     * @return  array<string, mixed>  Valid published definition document.
     *
     * @since   2.0.0
     */
    private function definition(): array
    {
        return [
            'id' => self::DEFINITION_ID,
            'owner' => ['type' => 'site', 'identifier' => 'default'],
            'site' => 'default',
            'handle' => 'site.default.catalog_authority_test',
            'singular_label' => 'Catalog authority test',
            'plural_label' => 'Catalog authority tests',
            'status' => 'published',
            'definition_version' => 2,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                [
                    'handle' => 'id',
                    'label' => 'ID',
                    'type' => 'core.uuid',
                    'required' => true,
                    'nullable' => false,
                    'unique' => true,
                    'indexed' => true,
                    'immutable_after_create' => true,
                    'server_only' => true,
                    'read_only' => true,
                ],
                [
                    'handle' => 'name',
                    'label' => 'Name',
                    'type' => 'core.text',
                    'required' => true,
                    'nullable' => false,
                    'length' => 120,
                ],
            ],
            'relationships' => [],
            'views' => [],
            'actions' => [[
                'handle' => 'approve',
                'label' => 'Approve',
                'capability' => 'business.record.action',
                'administrator' => true,
                'portal' => true,
                'public' => false,
                'high_impact' => true,
                'transition' => 'approve',
            ]],
            'workflow' => [
                'initial_state' => 'draft',
                'states' => ['draft', 'approved'],
                'transitions' => [[
                    'handle' => 'approve',
                    'from' => 'draft',
                    'to' => 'approved',
                    'capability' => 'business.record.action',
                ]],
            ],
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => true,
            'public_exposure' => false,
            'soft_delete_enabled' => true,
            'record_invariants' => [],
            'portal_operations' => ['approval', 'delete'],
        ];
    }

    /**
     * Resolve one built-in field type for metadata projection.
     *
     * @param   string  $identifier  Built-in type identifier.
     *
     * @return  FieldTypeDefinition  Matching immutable type.
     *
     * @since   2.0.0
     */
    private function fieldType(string $identifier): FieldTypeDefinition
    {
        foreach (BuiltInFieldTypes::all() as $type) {
            if ($type->id === $identifier) {
                return $type;
            }
        }

        self::fail('The requested built-in field type is missing.');
    }
}
