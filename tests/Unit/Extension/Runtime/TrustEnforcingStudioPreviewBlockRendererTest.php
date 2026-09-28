<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\App\Extension\Application\Trust\UntrustedPackage;
use Kumwe\App\Extension\Runtime\TrustEnforcingStudioPreviewBlockRenderer;
use Kumwe\App\Tests\Support\ResidentTrustFixtures;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Proves contributed Studio preview code renders and reports availability only behind the live trust fence.
 *
 * The fence reads committed trust authority and the boot generation, never the lifecycle lock: a trusted
 * entry must render while any other process holds that lock, a fragment produced across a generation
 * change must be discarded, and a trust authority that cannot be read must surface as a refusal rather
 * than as a false availability answer that would silently narrow a Studio registry.
 *
 * @since  2.0.0
 */
#[CoversClass(TrustEnforcingStudioPreviewBlockRenderer::class)]
#[UsesClass(TrustStore::class)]
final class TrustEnforcingStudioPreviewBlockRendererTest extends TestCase
{
    use ResidentTrustFixtures;

    /**
     * Prove a current, trusted entry reaches the implementation and returns its exact fragment, lock-free.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATrustedCurrentEntryRendersWithoutTheLifecycleLock(): void
    {
        $fragment = new StudioPreviewBlockFragment('div', 'acme-probe', '');
        $inner = $this->createMock(StudioPreviewBlockRenderer::class);
        $inner->expects(self::once())->method('render')->willReturn($fragment);
        $repository = self::probeRepository(
            $this->createMock(TrustStoreRepository::class),
            [self::probeExtension()],
            self::probeRelease(),
        );
        $repository->expects(self::never())->method('synchronizedLifecycle');

        $rendered = $this->renderer($inner, $repository, self::scriptedGate(true))->render(
            $this->createStub(StudioPreviewBlock::class),
            StudioPreviewBindingResult::unavailable(),
            'expanded',
        );

        self::assertSame($fragment, $rendered);
    }

    /**
     * Prove a fragment rendered while the generation moved is discarded instead of returned.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFragmentRenderedAcrossAGenerationChangeIsDiscarded(): void
    {
        $inner = $this->createMock(StudioPreviewBlockRenderer::class);
        $inner->expects(self::once())->method('render')
            ->willReturn(new StudioPreviewBlockFragment('div', 'acme-probe', ''));
        $repository = self::probeRepository(
            $this->createStub(TrustStoreRepository::class),
            [self::probeExtension()],
            self::probeRelease(),
        );
        $renderer = $this->renderer($inner, $repository, self::scriptedGate(true, false));

        try {
            $renderer->render(
                $this->createStub(StudioPreviewBlock::class),
                StudioPreviewBindingResult::unavailable(),
                'expanded',
            );
            self::fail('A fragment rendered across a generation change must not be returned.');
        } catch (RuntimeException $refused) {
            self::assertSame(
                'This process cannot execute a stale or untrusted extension generation.',
                $refused->getMessage(),
            );
        }
    }

    /**
     * Prove a stale generation is refused before trust is read and before the implementation runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStaleGenerationIsRefusedBeforeTrustIsReadOrCodeRuns(): void
    {
        $inner = $this->createMock(StudioPreviewBlockRenderer::class);
        $inner->expects(self::never())->method('render');
        $repository = self::probeRepository(
            $this->createMock(TrustStoreRepository::class),
            [self::probeExtension()],
            self::probeRelease(),
        );
        $repository->expects(self::never())->method('lockGeneration');
        $renderer = $this->renderer($inner, $repository, self::scriptedGate(false));

        $this->expectException(RuntimeException::class);

        $renderer->render(
            $this->createStub(StudioPreviewBlock::class),
            StudioPreviewBindingResult::unavailable(),
            'expanded',
        );
    }

    /**
     * Prove a revoked release is quarantined and never reaches the implementation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARevokedReleaseIsQuarantinedAndNeverRenders(): void
    {
        $inner = $this->createMock(StudioPreviewBlockRenderer::class);
        $inner->expects(self::never())->method('render');
        $repository = self::probeRepository(
            $this->createMock(TrustStoreRepository::class),
            [self::probeExtension()],
            self::probeRelease('revoked'),
        );
        $repository->expects(self::once())->method('quarantineExtension')
            ->with(self::probeExtension())
            ->willReturn(true);
        $renderer = $this->renderer($inner, $repository, self::scriptedGate(true));

        $this->expectException(UntrustedPackage::class);

        $renderer->render(
            $this->createStub(StudioPreviewBlock::class),
            StudioPreviewBindingResult::unavailable(),
            'expanded',
        );
    }

    /**
     * Prove availability is true only for a current trusted entry, and false for staleness or distrust.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAvailabilityIsAVerdictOnlyForCurrentTrustedStaleOrDistrustedEntries(): void
    {
        $inner = $this->createMock(StudioPreviewBlockRenderer::class);
        $inner->expects(self::never())->method('render');
        $trusted = self::probeRepository(
            $this->createMock(TrustStoreRepository::class),
            [self::probeExtension()],
            self::probeRelease(),
        );
        $trusted->expects(self::never())->method('synchronizedLifecycle');
        self::assertTrue($this->renderer($inner, $trusted, self::scriptedGate(true))->isAvailable());

        self::assertFalse($this->renderer($inner, $trusted, self::scriptedGate(false))->isAvailable());

        $revoked = self::probeRepository(
            $this->createStub(TrustStoreRepository::class),
            [self::probeExtension()],
            self::probeRelease('revoked'),
        );
        $revoked->method('quarantineExtension')->willReturn(true);
        self::assertFalse($this->renderer($inner, $revoked, self::scriptedGate(true))->isAvailable());

        $inactive = self::probeRepository(
            $this->createStub(TrustStoreRepository::class),
            [],
            self::probeRelease(),
        );
        self::assertFalse($this->renderer($inner, $inactive, self::scriptedGate(true))->isAvailable());
    }

    /**
     * Prove an unreadable trust authority propagates from availability, logged, with nothing quarantined.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnreadableTrustAuthorityIsRefusedRatherThanAnsweredUnavailable(): void
    {
        $inner = $this->createMock(StudioPreviewBlockRenderer::class);
        $inner->expects(self::never())->method('render');
        $repository = $this->createMock(TrustStoreRepository::class);
        $repository->method('lockGeneration')->willThrowException(new RuntimeException('lock wait timeout'));
        $repository->expects(self::never())->method('quarantineExtension');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Extension trust could not be determined; the extension is refused.',
            self::callback(static fn (array $context): bool => ($context['event'] ?? null)
                === 'extension.trust.indeterminate'
                && ($context['extension'] ?? null) === 'acme/probe'
                && ($context['message'] ?? null) === 'lock wait timeout'),
        );
        $renderer = new TrustEnforcingStudioPreviewBlockRenderer(
            $inner,
            self::probeTrustStore($repository, $logger),
            self::scriptedGate(true),
            self::probeExtension(),
            self::probeRuntimeEntry(),
        );

        try {
            $renderer->isAvailable();
            self::fail('An unreadable trust authority must not be answered as unavailable.');
        } catch (RuntimeException $refused) {
            self::assertSame(
                'The extension trust authority could not be read; the extension is refused until it can be.',
                $refused->getMessage(),
            );
        }
    }

    /**
     * Wrap one implementation in the fence under a real trust boundary backed by the given repository.
     *
     * @param   StudioPreviewBlockRenderer  $inner       Implementation the fence guards.
     * @param   TrustStoreRepository        $repository  Repository double the trust boundary consults.
     * @param   ExtensionExecutionGate      $execution   Boot-generation gate double.
     *
     * @return  TrustEnforcingStudioPreviewBlockRenderer  Fenced renderer bound to the probe entry.
     *
     * @since   2.0.0
     */
    private function renderer(
        StudioPreviewBlockRenderer $inner,
        TrustStoreRepository $repository,
        ExtensionExecutionGate $execution,
    ): TrustEnforcingStudioPreviewBlockRenderer {
        return new TrustEnforcingStudioPreviewBlockRenderer(
            $inner,
            self::probeTrustStore($repository),
            $execution,
            self::probeExtension(),
            self::probeRuntimeEntry(),
        );
    }
}
