<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;

/**
 * Re-establishes exact runtime-generation and package trust before extension preview code executes.
 *
 * @since  2.0.0
 */
final readonly class TrustEnforcingStudioPreviewBlockRenderer implements StudioPreviewBlockRenderer
{
    /**
     * Bind an implementation to its exact compiled publication entry and live trust authorities.
     *
     * @param  StudioPreviewBlockRenderer  $inner         Owner-local SDK implementation.
     * @param  TrustStore                  $trust         Live package trust boundary.
     * @param  ExtensionExecutionGate      $execution     Exact boot-generation fence.
     * @param  string                      $extension     Canonical `vendor/name` package owner.
     * @param  array<string, mixed>        $runtimeEntry  Exact signed compiled entry that loaded the code.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioPreviewBlockRenderer $inner,
        private TrustStore $trust,
        private ExtensionExecutionGate $execution,
        private string $extension,
        private array $runtimeEntry,
    ) {
    }

    /**
     * Execute only while the same signed runtime entry is active and trusted.
     *
     * @param   StudioPreviewBlock          $block     Immutable copied contributed block input.
     * @param   StudioPreviewBindingResult  $binding   Authorized binding projection.
     * @param   string                      $viewport  Active semantic viewport.
     *
     * @return  StudioPreviewBlockFragment  Safe fragment returned inside the lifecycle lock.
     *
     * @since   2.0.0
     */
    public function render(
        StudioPreviewBlock $block,
        StudioPreviewBindingResult $binding,
        string $viewport,
    ): StudioPreviewBlockFragment {
        $this->execution->assertCurrent();

        return $this->trust->synchronizedLifecycle(
            function () use ($block, $binding, $viewport): StudioPreviewBlockFragment {
                $this->execution->assertCurrent();
                $this->trust->enforceRuntimeTrust($this->extension, $this->runtimeEntry);

                return $this->inner->render($block, $binding, $viewport);
            },
        );
    }

    /**
     * Report whether the exact boot publication and package trust still authorize this implementation.
     *
     * @return  bool  True only while the exact compiled owner/version entry remains current and trusted.
     *
     * @since   2.0.0
     */
    public function isAvailable(): bool
    {
        if (!$this->execution->isCurrent()) {
            return false;
        }
        try {
            return $this->trust->synchronizedLifecycle(function (): bool {
                if (!$this->execution->isCurrent()) {
                    return false;
                }
                $this->trust->enforceRuntimeTrust($this->extension, $this->runtimeEntry);

                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }
}
