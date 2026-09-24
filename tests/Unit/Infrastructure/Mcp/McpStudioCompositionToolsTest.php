<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Mcp;

use DomainException;
use InvalidArgumentException;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\BuildsStudioCompositionService;
use Kumwe\App\Tests\Support\McpHandlersFixture;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use Kumwe\Content\Application\ContentModelNotFound;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Pins `kumwe_studio_composition_get` and `_provision` to the composition screen's read and its refusals.
 *
 * The real `StudioContentCompositionService` answers the read, so the document is the one REST and the console
 * print; the handler's job is both screen capabilities, `resource.not_found` for a version never provisioned or
 * a model the caller cannot read, `request.invalid` for a stale theme lock, and refusing before the mutation
 * guard when provisioning is not allowed.
 *
 * @since  2.0.0
 */
#[CoversClass(KumweMcpHandlers::class)]
final class McpStudioCompositionToolsTest extends TestCase
{
    use BuildsStudioCompositionService;

    /**
     * The read answers the REST document of a provisioned composition, and not-found before and for other models.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheReadAnswersTheProvisionedComposition(): void
    {
        $service = $this->compositionService(new RecordingAuditRecorder());
        $handlers = $this->handlers(['content.read', 'studio.mode.blueprint'], $service);
        $this->assertRefusal(
            ContentModelNotFound::class,
            static fn () => $handlers->getStudioComposition(self::$compositionTypeId, 4),
        );
        $provisioned = $service->provision(
            AuthorizationContext::human(['content.read', 'studio.mode.blueprint']),
            self::$compositionTypeId,
            4,
            StudioContentCompositionService::RENDERERS,
        );

        $read = $handlers->getStudioComposition(self::$compositionTypeId, 4);

        self::assertSame(
            json_encode($provisioned->toArray(), JSON_THROW_ON_ERROR),
            json_encode($read, JSON_THROW_ON_ERROR),
        );
        $this->assertRefusal(
            ContentModelNotFound::class,
            static fn () => $handlers->getStudioComposition('018f22e2-7c8b-7ab0-8f3a-88e8026be999', 4),
        );
        $this->retheme();
        $this->assertRefusal(
            DomainException::class,
            static fn () => $handlers->getStudioComposition(self::$compositionTypeId, 4),
        );
    }

    /**
     * Missing screen capabilities and an absent composition service refuse before any read or mutation guard.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsPrecedeTheMutationGuard(): void
    {
        $service = $this->compositionService(new RecordingAuditRecorder());
        $cases = [
            [
                InsufficientCapability::class,
                fn () => $this->handlers(['content.read'], $service)->getStudioComposition(self::$compositionTypeId, 4),
            ],
            [
                InsufficientCapability::class,
                fn () => $this->handlers(['studio.mode.blueprint'], $service)
                    ->getStudioComposition(self::$compositionTypeId, 4),
            ],
            [
                InsufficientCapability::class,
                fn () => $this->handlers(['content.read'], $service)->provisionStudioComposition(
                    'composition-provision-001',
                    self::$compositionTypeId,
                    4,
                ),
            ],
            [
                InvalidArgumentException::class,
                fn () => $this->handlers(['content.read', 'studio.mode.blueprint'], null)
                    ->getStudioComposition(self::$compositionTypeId, 4),
            ],
        ];
        foreach ($cases as [$expected, $call]) {
            $this->assertRefusal($expected, $call);
        }
    }

    /**
     * Assert one call is refused with the expected exception class.
     *
     * @param   class-string<Throwable>  $expected  Expected refusal.
     * @param   callable                 $call      Tool call.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRefusal(string $expected, callable $call): void
    {
        try {
            $call();
            self::fail($expected . ' was not raised.');
        } catch (Throwable $refusal) {
            self::assertInstanceOf($expected, $refusal);
        }
    }

    /**
     * Build handlers bound to a human context holding the given capabilities.
     *
     * @param   list<string>                      $capabilities  Capabilities of the caller.
     * @param   ?StudioContentCompositionService  $service       Composition service, or none.
     *
     * @return  KumweMcpHandlers  Context-bound handlers.
     *
     * @since   2.0.0
     */
    private function handlers(array $capabilities, ?StudioContentCompositionService $service): KumweMcpHandlers
    {
        return McpHandlersFixture::create(new McpCapabilityCatalog(), compositions: $service)
            ->forContext(AuthorizationContext::human($capabilities));
    }
}
