<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use Kumwe\App\Tests\Support\ResolvedWording;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
/**
 * Guards the shared generated-business delivery boundary used by every adapter.
 *
 * @since  2.0.0
 */
final class GeneratedBusinessDeliveryParityTest extends TestCase
{
    /**
     * Every generated adapter must derive its visible surface from the same policy-aware catalog.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryAdapterUsesTheSharedPolicyAwareCatalog(): void
    {
        foreach (
            [
                'src/BusinessSurface/Delivery/Browser/GeneratedBusinessBrowserController.php'
                    => 'BusinessSurfaceService',
                'src/Delivery/Http/Api/Business/BusinessDefinitionDiscoveryApiHandler.php'
                    => 'BusinessSurfaceCatalog',
                'src/Delivery/Http/Api/Business/BusinessRecordApiHandler.php' => 'BusinessSurfaceCatalog',
                'src/Delivery/Console/Command/ManageBusinessRecordsCommand.php' => 'BusinessSurfaceCatalog',
                'src/Infrastructure/Mcp/BusinessMcpHandlers.php' => 'BusinessSurfaceCatalog',
                'src/OpenApi/Application/OpenApiContractService.php' => 'BusinessSurfaceCatalog',
            ] as $path => $dependency
        ) {
            self::assertStringContainsString(
                $dependency,
                $this->contents($path),
                sprintf('%s must derive metadata through the shared surface application boundary.', $path),
            );
        }
    }

    /**
     * Delivery code must remain a mapping layer over shared application services.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedAdaptersCannotReachPersistenceOrEvaluateDomainRules(): void
    {
        foreach (
            [
                'src/BusinessSurface/Delivery',
                'src/Delivery/Http/Api/Business',
                'src/Delivery/Console/Command/ManageBusinessRecordsCommand.php',
                'src/Infrastructure/Mcp/BusinessMcpHandlers.php',
            ] as $path
        ) {
            $source = $this->source($path);
            self::assertStringNotContainsString('Doctrine\\DBAL', $source, $path);
            self::assertStringNotContainsString('BusinessRecordReadRepository', $source, $path);
            self::assertStringNotContainsString('BusinessRecordWriteRepository', $source, $path);
            self::assertStringNotContainsString('RecordRuleValidator', $source, $path);
            self::assertStringNotContainsString('BusinessRecordAccessController', $source, $path);
        }
    }

    /**
     * Mutating adapters must delegate to the one transactional record service.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryMutatingAdapterUsesTheSharedRecordService(): void
    {
        foreach (
            [
                'src/BusinessSurface/Application/BusinessSurfaceService.php',
                'src/Delivery/Http/Api/Business/BusinessRecordApiHandler.php',
                'src/Delivery/Console/Command/ManageBusinessRecordsCommand.php',
                'src/Infrastructure/Mcp/BusinessMcpHandlers.php',
            ] as $path
        ) {
            self::assertStringContainsString(
                'BusinessRecordService',
                $this->contents($path),
                sprintf('%s must delegate record mutations to BusinessRecordService.', $path),
            );
        }
    }

    /**
     * Custom views, actions and approval requests must cross the one generated-business facade.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryAdapterUsesTheSharedFacadeForCustomContracts(): void
    {
        foreach (
            [
                'src/BusinessSurface/Delivery/Browser/GeneratedBusinessBrowserController.php' => 'business',
                'src/Delivery/Http/Api/Business/BusinessRecordApiHandler.php' => 'surfaces',
                'src/Delivery/Console/Command/ManageBusinessRecordsCommand.php' => 'surfaces',
                'src/Infrastructure/Mcp/BusinessMcpHandlers.php' => 'business',
            ] as $path => $property
        ) {
            $source = $this->contents($path);
            foreach (['customView', 'action', 'requestActionApproval'] as $method) {
                self::assertStringContainsString(
                    sprintf('$this->%s->%s(', $property, $method),
                    $source,
                    sprintf('%s must route %s through the shared facade.', $path, $method),
                );
            }
        }
        self::assertStringNotContainsString(
            '$this->records->requestActionApproval(',
            $this->contents('src/Delivery/Console/Command/ManageBusinessRecordsCommand.php'),
        );
    }

    /**
     * Approval adapters must share one exposure gate and the portal must render only its redacted projection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testApprovalAdaptersShareTheLiveSurfaceGateAndPortalProjection(): void
    {
        $service = $this->contents(
            'src/BusinessSurface/Application/BusinessApprovalSurfaceService.php',
        );
        self::assertStringContainsString('ApprovalQueryService', $service);
        self::assertStringContainsString('BusinessApprovalExposureCatalog', $service);
        self::assertStringContainsString("resourceType !== 'business_record'", $service);

        $api = $this->contents('src/Delivery/Http/Api/Business/BusinessApprovalApiHandler.php');
        $cli = $this->contents('src/Delivery/Console/Command/ManageBusinessRecordsCommand.php');
        $portal = $this->contents('src/Portal/Http/Handler/PortalApprovalHandler.php');
        foreach ([$api, $cli, $portal] as $adapter) {
            self::assertStringContainsString('BusinessApprovalSurfaceService', $adapter);
            self::assertStringNotContainsString('ApprovalQueryService', $adapter);
        }
        self::assertStringContainsString('businessInbox(', $api);
        self::assertStringContainsString('businessDetail(', $api);
        self::assertStringContainsString('businessInbox(', $cli);
        self::assertStringContainsString('businessDetail(', $cli);
        self::assertGreaterThanOrEqual(4, substr_count($portal, 'portalDetail('));
        $transaction = strpos($portal, '$this->transactions->transactional(');
        self::assertIsInt($transaction);
        $beforeStepUp = strpos($portal, '$this->queries->portalDetail(', $transaction);
        $stepUp = strpos($portal, '$this->verify(', $beforeStepUp === false ? 0 : $beforeStepUp);
        $beforeMutation = strpos($portal, '$this->queries->portalDetail(', $stepUp === false ? 0 : $stepUp);
        $mutation = strpos($portal, '$this->approvals->approve(', $beforeMutation === false ? 0 : $beforeMutation);
        self::assertIsInt($beforeStepUp);
        self::assertIsInt($stepUp);
        self::assertIsInt($beforeMutation);
        self::assertIsInt($mutation);
        self::assertLessThan($stepUp, $beforeStepUp);
        self::assertLessThan($beforeMutation, $stepUp);
        self::assertLessThan($mutation, $beforeMutation);

        $templates = $this->contents('templates/portal/approvals.twig')
            . $this->contents('templates/portal/approval-detail.twig');
        foreach (
            [
                'resourceId', 'requesterId', 'approverId', 'bindingDigest', 'payloadDigest',
                'ruleCode', 'ruleVersion', 'approverRoleId', 'distinctActors',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $templates, $forbidden);
        }

        $container = $this->contents('src/Kernel/ContainerFactory.php');
        self::assertStringContainsString('$container->share(BusinessApprovalSurfaceService::class', $container);
        self::assertSame(1, substr_count($container, 'new BusinessApprovalSurfaceService('));
    }

    /**
     * Direct action and approval entry points must reject actions omitted by surface policy metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testActionDispatchRequiresPolicyFilteredSurfaceMembership(): void
    {
        $source = $this->contents('src/BusinessSurface/Application/BusinessSurfaceService.php');
        $action = $this->methodSource($source, 'action', 'bulkConfirmation');
        $approval = $this->methodSource($source, 'requestActionApproval', 'relate');

        foreach ([$action, $approval] as $method) {
            $metadata = strpos($method, '$this->metadata(');
            $membership = strpos($method, '$this->metadataItem($metadata, \'actions\', $action);');

            self::assertIsInt($metadata, 'The action boundary must obtain policy-filtered metadata.');
            self::assertIsInt($membership, 'The action boundary must require visible action membership.');
            self::assertLessThan($membership, $metadata, 'Metadata must be resolved before membership is checked.');
        }
    }

    /**
     * Browser selectors must use bounded shared relation and media choice services.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedBrowserSelectorsUseTheSharedSurfaceService(): void
    {
        $source = $this->contents(
            'src/BusinessSurface/Delivery/Browser/GeneratedBusinessBrowserController.php',
        );

        self::assertStringContainsString('public function choices(', $source);
        self::assertStringContainsString('$this->business->relationChoices(', $source);
        self::assertStringContainsString('$this->business->mediaChoices(', $source);
    }

    /**
     * Ordinary generated pages must not issue one catalogue query per reference or relationship declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedBrowserPageQueryWorkIsIndependentOfDefinitionWidth(): void
    {
        $source = $this->contents(
            'src/BusinessSurface/Delivery/Browser/GeneratedBusinessBrowserController.php',
        );
        $formStart = strpos($source, 'private function formChoices(');
        $formEnd = strpos($source, 'private function structuredFields(');
        $relationStart = strpos($source, 'private function relationshipChoices(');
        $relationEnd = strpos($source, 'private function selectedChoice(');
        self::assertIsInt($formStart);
        self::assertIsInt($formEnd);
        self::assertIsInt($relationStart);
        self::assertIsInt($relationEnd);
        $form = substr($source, $formStart, $formEnd - $formStart);
        $relationships = substr($source, $relationStart, $relationEnd - $relationStart);

        self::assertStringNotContainsString('$this->business->relationChoices(', $form);
        self::assertStringNotContainsString('$this->business->mediaChoices(', $form);
        self::assertStringNotContainsString('$this->business->relationChoices(', $relationships);
        self::assertSame(1, substr_count($relationships, '$this->business->ownedLineForm('));
        self::assertStringContainsString('if ($focus !== $handle)', $relationships);

        $service = $this->contents('src/BusinessSurface/Application/BusinessSurfaceService.php');
        $read = $this->methodSource($service, 'read', 'relationship');
        self::assertStringContainsString('$includes = [];', $read);
        self::assertStringContainsString('$this->catalog->operations(', $service);
        self::assertStringNotContainsString('foreach (BusinessSurfaceOperation::cases()', $service);

        $catalog = $this->contents('src/BusinessSurface/Application/BusinessSurfaceCatalog.php');
        self::assertStringContainsString('public function operations(', $catalog);
        self::assertStringContainsString('catalogOperationPlans(', $catalog);
    }

    /**
     * Every relationship has a bounded fixed route before the generic record route on both browser surfaces.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedRelationshipSectionsUseFixedBoundedRoutes(): void
    {
        $container = $this->contents('src/Kernel/ContainerFactory.php');
        foreach (['/administrator/business', '/portal/business'] as $base) {
            $relationship = $base . '/{definition}/{record}/relationships/{business_relationship}';
            $record = $base . '/{definition}/{record}';
            $relationshipPosition = strpos($container, $relationship);
            self::assertIsInt($relationshipPosition);
            $recordPosition = strpos(
                $container,
                "['" . $record . "',",
                $relationshipPosition + strlen($relationship),
            );
            self::assertIsInt($recordPosition);
            self::assertLessThan($recordPosition, $relationshipPosition);
        }

        $service = $this->contents('src/BusinessSurface/Application/BusinessSurfaceService.php');
        self::assertStringContainsString('public function relationship(', $service);
        self::assertStringContainsString('includes: [$relationship]', $service);
    }

    /**
     * OpenAPI discovery requires API authentication but delegates operation visibility to its contract service.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedOpenApiRouteHasNoBlanketBusinessCapability(): void
    {
        $container = $this->contents('src/Kernel/ContainerFactory.php');
        $start = strpos($container, "'/api/v1/openapi.json'");
        self::assertIsInt($start);
        $end = strpos($container, 'self::apiRoute(', $start);
        self::assertIsInt($end);
        $route = substr($container, $start, $end - $start);

        self::assertStringContainsString('OpenApiHandler::class', $route);
        self::assertStringNotContainsString('business.record.', $route);
    }

    /**
     * Keep every generic business and contract endpoint represented by the authoritative OpenAPI document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGenericRestRoutesHaveOpenApiOperations(): void
    {
        /** @var array<string, mixed> $contract */
        $contract = json_decode(
            $this->contents('api/openapi/kumwe-v1.json'),
            true,
            128,
            JSON_THROW_ON_ERROR,
        );
        $routes = [
            ['get', '/api/v1/openapi.json'],
            ['get', '/api/v1/business/definitions'],
            ['get', '/api/v1/business/definitions/{definition}'],
            ['get', '/api/v1/business/operations/{operation}'],
            ['get', '/api/v1/business/approvals'],
            ['get', '/api/v1/business/approvals/{approval}'],
            ['get', '/api/v1/business/reports'],
            ['post', '/api/v1/business/reports/{report}'],
            ['post', '/api/v1/business/reports/{report}/exports'],
            ['get', '/api/v1/business/report-exports/{artifact}'],
            ['get', '/api/v1/business/report-exports/{artifact}/download'],
            ['get', '/api/v1/business/records/{definition}'],
            ['post', '/api/v1/business/records/{definition}/search'],
            ['post', '/api/v1/business/views/{definition}/{view}'],
            ['post', '/api/v1/business/records/{definition}'],
            ['get', '/api/v1/business/records/{definition}/{record}'],
            ['post', '/api/v1/business/views/{definition}/{record}/{view}'],
            ['patch', '/api/v1/business/records/{definition}/{record}'],
            ['delete', '/api/v1/business/records/{definition}/{record}'],
            ['post', '/api/v1/business/records/{definition}/{record}/archive'],
            ['post', '/api/v1/business/records/{definition}/{record}/restore'],
            ['get', '/api/v1/business/records/{definition}/{record}/history'],
            ['post', '/api/v1/business/records/{definition}/{record}/actions/{action}'],
            ['post', '/api/v1/business/records/{definition}/{record}/actions/{action}/approval'],
            ['get', '/api/v1/business/records/{definition}/{record}/relations/{relation}'],
            ['post', '/api/v1/business/records/{definition}/{record}/relations/{relation}'],
            ['delete', '/api/v1/business/records/{definition}/{record}/relations/{relation}/{target}'],
            ['put', '/api/v1/business/records/{definition}/{record}/relations/{relation}/order'],
        ];
        foreach ($routes as [$method, $path]) {
            self::assertIsArray(
                $contract['paths'][$path][$method] ?? null,
                sprintf('%s %s is missing from OpenAPI.', strtoupper($method), $path),
            );
        }
        self::assertSame(
            [['bearerAuth' => [], 'siteContext' => []]],
            $contract['paths']['/api/v1/openapi.json']['get']['security'],
        );
        self::assertArrayHasKey('503', $contract['paths']['/api/v1/openapi.json']['get']['responses']);
    }

    /**
     * Both browser surfaces render and retain the shared validated input for bulk custom actions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBulkCustomActionInputUsesTheSharedGraphicalSchemaEditor(): void
    {
        foreach (
            [
                'templates/administrator/business-bulk-confirm.twig',
                'templates/portal/business-bulk-confirm.twig',
            ] as $path
        ) {
            $template = $this->contents($path);
            self::assertStringContainsString('bulk_action_fields', $template, $path);
            self::assertStringContainsString('schema_fields.fields(bulk_action_fields)', $template, $path);
            self::assertStringContainsString('name="prepare_bulk_input"', $template, $path);
        }

        $service = $this->contents('src/BusinessSurface/Application/BusinessSurfaceService.php');
        self::assertStringNotContainsString('actionRequiresInput', $service);
    }

    /**
     * Ordered relationships use labelled native controls instead of an identity-list authoring field.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOrderedRelationshipsUseGraphicalMemberControls(): void
    {
        foreach (
            [
                'templates/administrator/business-detail.twig',
                'templates/portal/business-detail.twig',
            ] as $path
        ) {
            $template = $this->contents($path);
            self::assertStringContainsString('name="ordered_record_ids[]"', $template, $path);
            self::assertStringContainsString("related_record.label|default('Unnamed record')", $template, $path);
            self::assertStringNotContainsString('textarea', $template, $path);
            self::assertStringNotContainsString('Ordered record IDs', $template, $path);
        }
    }

    /**
     * Successful generated surface reads do not carry validation errors.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedSurfaceErrorSummariesAreOptional(): void
    {
        foreach (
            [
                'templates/administrator/business-detail.twig',
                'templates/administrator/business-confirm.twig',
                'templates/administrator/business-bulk-confirm.twig',
                'templates/administrator/business-custom-view.twig',
                'templates/portal/business-detail.twig',
                'templates/portal/business-confirm.twig',
                'templates/portal/business-bulk-confirm.twig',
                'templates/portal/business-custom-view.twig',
            ] as $path
        ) {
            $template = $this->contents($path);
            self::assertStringContainsString(
                '{% if error_summary is defined and error_summary %}',
                $template,
                $path,
            );
        }
        foreach (
            [
                'templates/administrator/business-confirm.twig',
                'templates/portal/business-confirm.twig',
            ] as $path
        ) {
            self::assertStringContainsString(
                '{% if approval_requested|default(false) and approval_request_id|default(null) %}',
                $this->contents($path),
                $path,
            );
        }
    }

    /**
     * Portal navigation must appear only after request-scoped shared catalog discovery succeeds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalNavigationUsesPolicyFilteredRequestDiscovery(): void
    {
        $core = $this->contents('src/Extension/Contribution/CoreExtensionContributions.php');
        $visibility = $this->contents(
            'src/BusinessSurface/Delivery/Portal/GeneratedBusinessPortalNavigationVisibility.php',
        );
        $renderer = $this->contents('src/Portal/Presentation/PortalRenderer.php');
        $container = $this->contents('src/Kernel/ContainerFactory.php');

        self::assertStringContainsString("'core.portal-business-records'", $core);
        self::assertStringContainsString("'/portal/business'", $core);
        self::assertStringContainsString("'core.portal-business-records'", $visibility);
        self::assertStringContainsString('$this->catalog->definitions(', $visibility);
        self::assertStringContainsString('BusinessSurface::Portal', $visibility);
        self::assertStringContainsString('BusinessSurfaceOperation::Discover', $visibility);
        self::assertStringContainsString(') !== [];', $visibility);
        self::assertStringContainsString('$this->visibility->visible($session, $item)', $renderer);
        self::assertStringContainsString('new GeneratedBusinessPortalNavigationVisibility(', $container);
    }

    /**
     * High-impact browser execution must issue a fresh exact-purpose proof on both session surfaces.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedBrowserActionExecutionUsesSessionBoundStepUpOnBothSurfaces(): void
    {
        $controller = $this->contents(
            'src/BusinessSurface/Delivery/Browser/GeneratedBusinessBrowserController.php',
        );
        self::assertStringContainsString('public function actionStepUpPurpose(', $controller);
        self::assertStringContainsString('$this->business->actionStepUpPurpose(', $controller);
        $coordinator = $this->contents(
            'src/BusinessSurface/Application/GeneratedBusinessActionStepUp.php',
        );
        self::assertStringContainsString('$this->transactions->transactional(', $coordinator);
        self::assertStringContainsString('$this->verify(', $coordinator);
        self::assertStringContainsString('$this->elevate(', $coordinator);

        foreach (
            [
                'src/BusinessSurface/Delivery/Administrator/AdministratorBusinessSurfaceHandler.php',
                'src/BusinessSurface/Delivery/Portal/PortalBusinessSurfaceHandler.php',
            ] as $path
        ) {
            $source = $this->contents($path);
            self::assertStringContainsString('GeneratedBusinessActionStepUp', $source, $path);
            self::assertStringContainsString('$this->actionStepUp->execute(', $source, $path);
            self::assertStringNotContainsString('TransactionManager', $source, $path);
            self::assertStringNotContainsString('Infrastructure\\Persistence', $source, $path);
            self::assertStringNotContainsString('$this->actionStepUp->verify(', $source, $path);
            self::assertStringNotContainsString('$this->actionStepUp->elevate(', $source, $path);
            self::assertStringContainsString('GeneratedBusinessConfirmationQuery::retain($body)', $source, $path);
            self::assertStringContainsString("'Set-Cookie'", $source, $path);
        }
        $retained = $this->contents(
            'src/BusinessSurface/Delivery/Browser/GeneratedBusinessConfirmationQuery.php',
        );
        self::assertStringContainsString("\$body['verification']", $retained);
        self::assertStringContainsString("\$body['verification_method']", $retained);

        foreach (
            [
                'templates/administrator/business-confirm.twig',
                'templates/portal/business-confirm.twig',
            ] as $path
        ) {
            $template = $this->contents($path);
            self::assertStringContainsString('name="verification_method"', $template, $path);
            self::assertStringContainsString('name="verification"', $template, $path);
            self::assertStringContainsString('Fresh requester verification', $template, $path);
        }
    }

    /**
     * Serialize the site component namespace before admission reads and before publication writes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDefinitionPublicationLocksBeforeContractAdmissionAndWrite(): void
    {
        $source = $this->contents('src/BusinessDefinition/Application/BusinessDefinitionService.php');
        $lock = strpos($source, '$this->repository->lockContractNamespace(');
        $admission = strpos($source, '$this->contractAdmission->admit(', $lock === false ? 0 : $lock);
        $publish = strpos($source, '$this->repository->publish(', $admission === false ? 0 : $admission);

        self::assertIsInt($lock);
        self::assertIsInt($admission);
        self::assertIsInt($publish);
        self::assertLessThan($admission, $lock);
        self::assertLessThan($publish, $admission);
    }

    /**
     * Custom business handlers may only receive typed application values and execution context.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCustomHandlerContractsContainNoDeliveryOrPersistenceTypes(): void
    {
        $source = $this->source('src/BusinessSurface/Application/Custom');
        if ($source === '') {
            self::fail('The typed custom business handler boundary is missing.');
        }

        foreach (
            [
                'Psr\\Http',
                'ServerRequestInterface',
                'Doctrine\\DBAL',
                'Connection',
                'ContainerInterface',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    /**
     * Read one file or all PHP files below one directory.
     *
     * @param   string  $path  Repository-relative path.
     *
     * @return  string  Concatenated source.
     *
     * @since   2.0.0
     */
    private function source(string $path): string
    {
        $absolute = dirname(__DIR__, 2) . '/' . $path;
        if (is_file($absolute)) {
            return $this->contents($path);
        }
        if (!is_dir($absolute)) {
            return '';
        }

        $source = '';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $contents = file_get_contents($file->getPathname());
                self::assertIsString($contents);
                $source .= $contents;
            }
        }

        return $source;
    }

    /**
     * Read one repository source file.
     *
     * @param   string  $path  Repository-relative file path.
     *
     * @return  string  File contents.
     *
     * @since   2.0.0
     */
    private function contents(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents, sprintf('Could not read %s.', $path));

        return str_ends_with($path, '.twig') ? ResolvedWording::withResolved($contents) : $contents;
    }

    /**
     * Extract one public method from a final service using its following method as the stable boundary.
     *
     * @param   string  $source           Complete source text.
     * @param   string  $method           Method to extract.
     * @param   string  $followingMethod  Following public method.
     *
     * @return  string  Extracted method source.
     *
     * @since   2.0.0
     */
    private function methodSource(string $source, string $method, string $followingMethod): string
    {
        $start = strpos($source, sprintf('public function %s(', $method));
        $end = strpos($source, sprintf('public function %s(', $followingMethod));

        self::assertIsInt($start, sprintf('Could not find BusinessSurfaceService::%s().', $method));
        self::assertIsInt($end, sprintf('Could not find BusinessSurfaceService::%s().', $followingMethod));
        self::assertLessThan($end, $start, sprintf('%s must precede %s.', $method, $followingMethod));

        return substr($source, $start, $end - $start);
    }
}
