<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Mcp;

use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringRefused;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionGateway;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\BuildsStudioCompositionService;
use Kumwe\App\Tests\Support\McpHandlersFixture;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

/**
 * Pins the `kumwe_studio_blueprint_*` tools' refusals before any Studio session or host is reached.
 *
 * The real gateway runs over the real composition service and in-memory stores: the credential floor, an unknown
 * mode, an unprovisioned composition, a malformed argument document, a missing gateway and each operation's
 * replay-key and revision rules refuse exactly as the REST and console bindings do.
 *
 * @since  2.0.0
 */
#[CoversClass(KumweMcpHandlers::class)]
final class McpStudioBlueprintToolsTest extends TestCase
{
    use BuildsStudioCompositionService;

    /**
     * Every refusal names the same Studio diagnostic the other surfaces answer with.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsPrecedeTheStudioHost(): void
    {
        $full = ['content.read', 'studio.mode.blueprint'];
        $type = self::$compositionTypeId;
        $cases = [
            [InsufficientCapability::class, fn () => $this->handlers(['studio.mode.blueprint'])
                ->openStudioBlueprintSession($type, 4)],
            ['studio.machine/target-invalid', fn () => $this->handlers($full)
                ->openStudioBlueprintSession($type, 4, 'content')],
            ['studio.composition/not-found', fn () => $this->handlers($full)
                ->openStudioBlueprintSession($type, 4, 'read-only')],
            ['studio.machine/request-invalid', fn () => $this->handlers($full)
                ->studioBlueprintLoad('contexts/key', 'session-one', '[]')],
            ['studio.machine/session-invalid', fn () => $this->handlers($full)
                ->studioBlueprintDependencies('not a session', 'session-one', '{}')],
            ['studio.machine/idempotency-key-invalid', fn () => $this->handlers($full)
                ->studioBlueprintSave('not a key', 'contexts/key', 'session-one', '{}', 'initial-a')],
            ['studio.machine/revision-invalid', fn () => $this->handlers($full)
                ->studioBlueprintPublish('replay-key-0001', 'contexts/key', 'session-one', '{}', '')],
            ['studio.machine/generation-invalid', fn () => $this->handlers($full)
                ->studioBlueprintUnpublish('replay-key-0002', 'contexts/key', '', '{}', 'initial-a')],
            [LogicException::class, fn () => $this->handlers($full, false)
                ->studioBlueprintLoad('contexts/key', 'session-one', '{}')],
        ];
        foreach ($cases as $index => [$expected, $call]) {
            try {
                $call();
                self::fail(sprintf('Refusal %d was not raised.', $index));
            } catch (Throwable $refusal) {
                if (str_contains($expected, '/')) {
                    self::assertInstanceOf(StudioMachineAuthoringRefused::class, $refusal, (string) $index);
                    self::assertSame([$expected], $refusal->diagnosticCodes(), (string) $index);
                    continue;
                }
                self::assertInstanceOf($expected, $refusal, (string) $index);
            }
        }
    }

    /**
     * Build handlers bound to an MCP context holding the given capabilities.
     *
     * @param   list<string>  $capabilities  Capabilities of the caller.
     * @param   bool          $gateway       Whether the Blueprint gateway is composed.
     *
     * @return  KumweMcpHandlers  Context-bound handlers.
     *
     * @since   2.0.0
     */
    private function handlers(array $capabilities, bool $gateway = true): KumweMcpHandlers
    {
        $handlers = McpHandlersFixture::create(
            new McpCapabilityCatalog(),
            blueprints: $gateway
                ? new StudioMachineCompositionGateway(
                    $this->compositionService(new RecordingAuditRecorder()),
                    (new ReflectionClass(StudioHostSessionAuthority::class))->newInstanceWithoutConstructor(),
                    (new ReflectionClass(StudioProducerHostFactory::class))->newInstanceWithoutConstructor(),
                )
                : null,
        );

        return $handlers->forContext(AuthenticatedPrincipal::issueFromStrings(
            AuthorizationContext::provenance(),
            AuthorizationContext::SUBJECT,
            $capabilities,
            'api-token:mcp-blueprint',
        )->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'mcp-blueprint-request',
            surface: AuthenticatedSurface::Mcp,
        ));
    }
}
