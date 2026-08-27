<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Authoring;

use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringLaunch;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringLaunchResolver;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTarget;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringAvailability;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringConfiguration;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringConfigurationProvider;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringFallbackReason;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringReadiness;
use Kumwe\App\Studio\Application\Authoring\UnavailableStudioContextualAuthoringConfigurationProvider;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Tests\Support\AuthorizationContext;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves a Content launch is available only when runtime and configuration arrive atomically.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentStudioAuthoringLaunchResolver::class)]
#[CoversClass(ContentStudioAuthoringLaunch::class)]
#[CoversClass(UnavailableStudioContextualAuthoringConfigurationProvider::class)]
final class ContentStudioAuthoringLaunchResolverTest extends TestCase
{
    /**
     * A release/runtime refusal remains authoritative and avoids configuration work entirely.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRuntimeRefusalPreservesItsReasonAndSkipsConfiguration(): void
    {
        $runtime = $this->createStub(StudioContextualAuthoringAvailability::class);
        $runtime->method('current')->willReturn(StudioContextualAuthoringReadiness::fallback(
            StudioContextualAuthoringFallbackReason::BrowserRuntimeUnavailable,
        ));
        $configurations = $this->createMock(StudioContextualAuthoringConfigurationProvider::class);
        $configurations->expects(self::never())->method('forMount');
        $target = $this->target();

        $launch = (new ContentStudioAuthoringLaunchResolver($runtime, $configurations))->resolve(
            AuthorizationContext::human([]),
            $target,
            'csrf-runtime-refusal',
        );

        self::assertFalse($launch->readiness->available);
        self::assertSame(
            StudioContextualAuthoringFallbackReason::BrowserRuntimeUnavailable,
            $launch->readiness->reason,
        );
        self::assertSame($target, $launch->target);
        self::assertNull($launch->configuration);
    }

    /**
     * Qualified release/runtime evidence cannot launch without canonical per-mount configuration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRuntimeReadyWithoutConfigurationFallsBackWithStableReason(): void
    {
        $runtime = $this->createStub(StudioContextualAuthoringAvailability::class);
        $runtime->method('current')->willReturn(StudioContextualAuthoringReadiness::available());
        $context = AuthorizationContext::human([]);
        $target = $this->target();
        $configurations = new UnavailableStudioContextualAuthoringConfigurationProvider();

        $launch = (new ContentStudioAuthoringLaunchResolver($runtime, $configurations))->resolve(
            $context,
            $target,
            'csrf-configuration-missing',
        );

        self::assertSame([
            'available' => false,
            'fallback' => 'structured-form',
            'reason' => 'configuration-unavailable',
            'target' => $target->toArray(),
            'configuration' => null,
        ], $launch->toArray());
        self::assertSame(
            StudioContextualAuthoringFallbackReason::ConfigurationUnavailable,
            $launch->readiness->reason,
        );
    }

    /**
     * One canonical marker joins qualified runtime evidence as the same atomic launch value.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRuntimeReadyWithConfigurationRetainsTheExactConfiguration(): void
    {
        $runtime = $this->createStub(StudioContextualAuthoringAvailability::class);
        $runtime->method('current')->willReturn(StudioContextualAuthoringReadiness::available());
        $context = AuthorizationContext::human([]);
        $target = $this->target();
        $configuration = $this->createStub(StudioContextualAuthoringConfiguration::class);
        $configurations = $this->createMock(StudioContextualAuthoringConfigurationProvider::class);
        $configurations->expects(self::once())
            ->method('forMount')
            ->with($context, $target, 'csrf-configured-launch')
            ->willReturn($configuration);

        $launch = (new ContentStudioAuthoringLaunchResolver($runtime, $configurations))->resolve(
            $context,
            $target,
            'csrf-configured-launch',
        );
        $view = $launch->toArray();

        self::assertTrue($launch->readiness->available);
        self::assertNull($launch->readiness->reason);
        self::assertSame($configuration, $launch->configuration);
        self::assertSame($configuration, $view['configuration']);
    }

    /**
     * Direct construction cannot separate an available decision from its required configuration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInconsistentLaunchConstructionIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Studio contextual authoring launch is not atomic.');

        new ContentStudioAuthoringLaunch(
            $this->target(),
            StudioContextualAuthoringReadiness::available(),
            null,
        );
    }

    /**
     * The closed readiness value refuses a reason beside an available decision.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInconsistentReadinessConstructionIsRefused(): void
    {
        $reflection = new ReflectionClass(StudioContextualAuthoringReadiness::class);
        $readiness = $reflection->newInstanceWithoutConstructor();
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Studio contextual authoring readiness is inconsistent.');

        $constructor->invoke(
            $readiness,
            true,
            StudioContextualAuthoringFallbackReason::ProtocolUnavailable,
        );
    }

    /**
     * Build one PHP-authoritative blank-create target for launch-boundary assertions.
     *
     * @return  ContentStudioAuthoringTarget  Trusted App target carrying no Studio configuration.
     *
     * @since   2.0.0
     */
    private function target(): ContentStudioAuthoringTarget
    {
        return new ContentStudioAuthoringTarget(
            StudioAuthoringIntent::Create,
            null,
            null,
            null,
            null,
            null,
            '/administrator/content/new',
        );
    }
}
