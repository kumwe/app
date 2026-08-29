<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\Producer\Render\BlockRenderer;
use Kumwe\Producer\Render\RenderState;
use stdClass;

/**
 * Re-establishes exact runtime-generation and package trust before extension preview code executes.
 *
 * @since  2.0.0
 */
final readonly class TrustEnforcingStudioPreviewBlockRenderer implements BlockRenderer
{
    /**
     * Bind an implementation to its exact compiled publication entry and live trust authorities.
     *
     * @param  BlockRenderer           $inner         Owner-local Producer implementation.
     * @param  TrustStore                  $trust         Live package trust boundary.
     * @param  ExtensionExecutionGate      $execution     Exact boot-generation fence.
     * @param  string                      $extension     Canonical `vendor/name` package owner.
     * @param  array<string, mixed>        $runtimeEntry  Exact signed compiled entry that loaded the code.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BlockRenderer $inner,
        private TrustStore $trust,
        private ExtensionExecutionGate $execution,
        private string $extension,
        private array $runtimeEntry,
    ) {
    }

    /**
     * Execute only while the same signed runtime entry is active and trusted.
     *
     * @param   stdClass    $node   Schema-admitted Blueprint node.
     * @param   string      $scope  Producer-owned CSS scope.
     * @param   RenderState $state  Per-render Producer services and host authority.
     *
     * @return  string  Escaped inner markup returned inside the lifecycle lock.
     *
     * @since   2.0.0
     */
    public function render(
        stdClass $node,
        string $scope,
        RenderState $state,
    ): string {
        $this->execution->assertCurrent();

        return $this->trust->synchronizedLifecycle(
            function () use ($node, $scope, $state): string {
                $this->execution->assertCurrent();
                $this->trust->enforceRuntimeTrust($this->extension, $this->runtimeEntry);

                return $this->inner->render($node, $scope, $state);
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
