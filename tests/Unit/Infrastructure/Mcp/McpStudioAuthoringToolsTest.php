<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Mcp;

use Kumwe\App\Application\Automation\AutomationManagementService;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Infrastructure\RedisLockedExtensionManager;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\App\Infrastructure\Mcp\BusinessMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Infrastructure\Mcp\McpCatalogValidator;
use Kumwe\App\Infrastructure\Mcp\McpMutationGuard;
use Kumwe\App\Infrastructure\Mcp\McpMutationGuardMode;
use Kumwe\App\Infrastructure\Mcp\McpToolErrorVocabulary;
use Kumwe\App\Infrastructure\Mcp\McpToolExecutionEvidence;
use Kumwe\App\Infrastructure\Mcp\ReportMcpHandlers;
use Kumwe\App\Infrastructure\Time\SystemClock;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\Site\Infrastructure\Persistence\DoctrineSiteSettings;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextAuthority;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetResolver;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringGateway;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringRefused;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the MCP Studio authoring tools: their catalogue policy, the Studio replay route and closed error codes.
 *
 * The eight tools pass the catalogue validator against the real handler source; a mutating Studio tool that
 * does not hand its `operationId` to the Studio host is rejected; every Studio refusal category but an
 * internal one maps to a retained `studio_authoring.*` envelope; and the handlers refuse a malformed
 * document or session before any store is reached.
 *
 * @since  2.0.0
 */
#[CoversClass(KumweMcpHandlers::class)]
#[CoversClass(McpCapabilityCatalog::class)]
#[CoversClass(McpToolExecutionEvidence::class)]
#[CoversClass(McpToolErrorVocabulary::class)]
#[CoversClass(McpMutationGuardMode::class)]
final class McpStudioAuthoringToolsTest extends TestCase
{
    /**
     * The Studio tools are valid against the live handler call graph.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStudioToolsPassTheCatalogueValidator(): void
    {
        $handlers = self::handlers();
        $validator = new McpCatalogValidator();
        $studio = array_values(array_filter(
            (new McpCapabilityCatalog())->tools(),
            static fn (array $tool): bool => str_starts_with($tool['name'], 'kumwe_studio_authoring_'),
        ));

        self::assertCount(8, $studio);
        foreach ($studio as $tool) {
            self::assertSame([], $validator->toolViolations($tool, $handlers), $tool['name']);
            self::assertSame('content.read', $tool['capability']);
        }
    }

    /**
     * A mutating Studio tool bound to a handler that never reaches the Studio host fails evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStudioMutationThatBypassesTheHostBoundaryIsRejected(): void
    {
        $tools = [];
        foreach ((new McpCapabilityCatalog())->tools() as $tool) {
            $tools[$tool['name']] = $tool;
        }
        $bypass = $tools['kumwe_studio_authoring_save_item'];
        $bypass['handler'] = 'studioAuthoringPlanSave';

        self::assertContains(
            'Mutating tool "kumwe_studio_authoring_save_item" does not hand its operationId to the Studio host '
                . 'replay boundary.',
            (new McpToolExecutionEvidence())->violations($bypass, self::handlers()),
        );
    }

    /**
     * Studio refusal categories map to retained codes; an internal failure stays a generic defect.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStudioRefusalsMapToRetainedCodes(): void
    {
        foreach (
            [
                ['forbidden', 'studio.host/session-refused', 'studio_authoring.forbidden', false],
                ['conflict', 'studio.authoring/expected-mismatch', 'studio_authoring.conflict', false],
                ['validation-failed', 'studio.authoring/unknown-target', 'studio_authoring.validation_failed', false],
                ['not-found', 'studio.authoring/type-not-found', 'studio_authoring.not_found', false],
                ['invalid-request', 'studio.machine/request-invalid', 'studio_authoring.invalid_request', false],
                [
                    'invalid-request',
                    'studio.host/idempotency-intent-changed',
                    'studio_authoring.idempotency_key_reused',
                    false,
                ],
                ['unavailable', 'studio.host/idempotency-in-progress', 'studio_authoring.idempotency_in_progress', true],
            ] as [$category, $diagnostic, $code, $retryable]
        ) {
            $envelope = McpToolErrorVocabulary::envelope(StudioMachineAuthoringRefused::of($category, $diagnostic));
            self::assertNotNull($envelope, $code);
            self::assertSame($code, $envelope->code);
            self::assertSame($retryable, $envelope->retryable);
            self::assertSame('The Studio authoring request was refused.', $envelope->message);
        }
        self::assertNull(McpToolErrorVocabulary::envelope(
            StudioMachineAuthoringRefused::of('internal', 'studio.authoring/document-invalid'),
        ));
    }

    /**
     * Handlers refuse a malformed document or session, an unbound caller, and a missing gateway.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHandlersRefuseMalformedInputBeforeAnyStore(): void
    {
        $bound = self::handlers()->forContext(AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            ['content.read'],
            'api-token:mcp',
        )->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'mcp-request',
            surface: AuthenticatedSurface::Mcp,
        ));

        foreach (['[]', '{', '"text"'] as $document) {
            try {
                $bound->studioAuthoringPlanSave('contexts/key', 'session-one', $document);
                self::fail($document . ' must be refused.');
            } catch (StudioMachineAuthoringRefused $refused) {
                self::assertSame(['studio.machine/request-invalid'], $refused->diagnosticCodes());
            }
        }
        try {
            $bound->studioAuthoringSaveItem('replay-key-000001', 'not a session', 'session-one', '{}');
            self::fail('A malformed session must be refused.');
        } catch (StudioMachineAuthoringRefused $refused) {
            self::assertSame(['studio.machine/session-invalid'], $refused->diagnosticCodes());
        }
        try {
            $bound->openStudioAuthoringSession('publish');
            self::fail('An unknown intent must be refused.');
        } catch (StudioMachineAuthoringRefused $refused) {
            self::assertSame(['studio.machine/target-invalid'], $refused->diagnosticCodes());
        }
        foreach (
            [
                'list' => static fn () => $bound->studioAuthoringListTypes('bad key', 'g', '{}'),
                'resolve' => static fn () => $bound->studioAuthoringResolveTarget('bad key', 'g', '{}'),
                'start' => static fn () => $bound->studioAuthoringStart('replay-key-000001', 'bad key', 'g', '{}'),
                'type' => static fn () => $bound->studioAuthoringSaveAsNewType('replay-key-000001', 'bad key', 'g', '{}'),
                'version' => static fn () => $bound->studioAuthoringSaveNewTypeVersion(
                    'replay-key-000001',
                    'bad key',
                    'g',
                    '{}',
                ),
            ] as $label => $call
        ) {
            try {
                $call();
                self::fail($label . ' must be refused.');
            } catch (StudioMachineAuthoringRefused $refused) {
                self::assertSame(['studio.machine/session-invalid'], $refused->diagnosticCodes(), $label);
            }
        }

        $this->expectException(InsufficientCapability::class);
        self::handlers()->studioAuthoringPlanSave('contexts/key', 'session-one', '{}');
    }

    /**
     * A handler composed without Studio authoring refuses loudly rather than silently.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHandlersWithoutAGatewayRefuse(): void
    {
        $this->expectException(LogicException::class);
        self::handlers(false)->forContext(AuthorizationContext::human(['content.read']))
            ->studioAuthoringPlanSave('contexts/key', 'session-one', '{}');
    }

    /**
     * Handlers over uninitialized services and an optional unreachable Studio gateway.
     *
     * @param   bool  $studio  Whether to compose the Studio gateway.
     *
     * @return  KumweMcpHandlers  Unbound handlers.
     *
     * @since   2.0.0
     */
    private static function handlers(bool $studio = true): KumweMcpHandlers
    {
        $bare = static fn (string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $catalog = new McpCapabilityCatalog();

        return new KumweMcpHandlers(
            $catalog,
            $bare(ContentService::class),
            $bare(NavigationService::class),
            $bare(AccessControlService::class),
            $bare(DoctrineSiteSettings::class),
            $bare(RedisLockedExtensionManager::class),
            $bare(TrustStore::class),
            $bare(AutomationManagementService::class),
            $bare(BusinessDefinitionService::class),
            $bare(BusinessSchemaService::class),
            $bare(BusinessMcpHandlers::class),
            $bare(ReportMcpHandlers::class),
            $bare(McpMutationGuard::class),
            new SystemClock(),
            AuthorizationContext::gateway(),
            studioAuthoring: $studio ? new StudioMachineAuthoringGateway(
                $bare(ContentStudioAuthoringContextAuthority::class),
                $bare(StudioHostSessionAuthority::class),
                $bare(ContentStudioAuthoringTargetResolver::class),
                $bare(ContentService::class),
                $bare(ContentModelService::class),
                $bare(StudioProducerHostFactory::class),
            ) : null,
        );
    }
}
