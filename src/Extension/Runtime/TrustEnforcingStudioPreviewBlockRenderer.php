<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use InvalidArgumentException;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\RuntimePublicationMismatch;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Application\Trust\UntrustedPackage;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;
use RuntimeException;

/**
 * Re-establishes exact runtime-generation and package trust before extension preview code executes.
 *
 * Both answers are read without the installation-wide lifecycle lock. That lock serializes lifecycle
 * mutators and is taken without waiting, so a renderer that held it refused every concurrent Studio
 * render, extension request and revocation, and an availability check refused by it dropped a trusted
 * renderer from the registry. Trust is instead read from committed authority and fenced by the runtime
 * generation this process loaded, before and after the read, and a rendered fragment is released only
 * when that generation is still current once the implementation has returned.
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
     * The generation is checked before the trust read and again after the implementation returns, so a
     * fragment rendered across a lifecycle change that withdrew this entry is discarded rather than
     * returned.
     *
     * @param   StudioPreviewBlock          $block     Immutable copied contributed block input.
     * @param   StudioPreviewBindingResult  $binding   Authorized binding projection.
     * @param   string                      $viewport  Active semantic viewport.
     *
     * @return  StudioPreviewBlockFragment  Safe fragment rendered wholly inside one trusted generation.
     *
     * @throws  RuntimeException  When the loaded generation is stale or changed during the render, or the
     *          trust authority cannot be read.
     * @throws  UntrustedPackage  When the package is no longer trusted; it is quarantined first.
     * @throws  RuntimePublicationMismatch  When the compiled entry no longer describes the authoritative
     *          release.
     *
     * @since   2.0.0
     */
    public function render(
        StudioPreviewBlock $block,
        StudioPreviewBindingResult $binding,
        string $viewport,
    ): StudioPreviewBlockFragment {
        $this->execution->assertCurrent();
        $this->trust->enforceRuntimeTrust($this->extension, $this->runtimeEntry);
        $fragment = $this->inner->render($block, $binding, $viewport);
        $this->execution->assertCurrent();

        return $fragment;
    }

    /**
     * Report whether the exact boot publication and package trust still authorize this implementation.
     *
     * A stale generation and a distrust verdict both answer false. A trust authority that cannot be read
     * is not a verdict: it propagates, so the registry decision that asked is refused explicitly instead
     * of silently omitting a renderer that may well be trusted.
     *
     * @return  bool  True only while the exact compiled owner/version entry remains current and trusted.
     *
     * @throws  RuntimeException  When the trust authority cannot be read; `TrustStore` has logged it.
     *
     * @since   2.0.0
     */
    public function isAvailable(): bool
    {
        try {
            return $this->trust->residentRuntimeTrusted($this->execution, $this->extension, $this->runtimeEntry);
        } catch (UntrustedPackage | RuntimePublicationMismatch | InvalidArgumentException) {
            return false;
        }
    }
}
