<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use LogicException;
use Kumwe\App\Extension\Application\ExtensionRuntimeWithdrawal;

/**
 * Late-binds lifecycle withdrawal to the active graph built after trust composition.
 *
 * `TrustStore` must exist in order to verify and load `ActiveExtensionSet`, while trust withdrawal must
 * later clear that set. This small coordinator breaks that composition cycle without a service locator:
 * the container shares it first, binds the one completed set after loading, and application services call
 * only the narrow withdrawal port.
 *
 * @since  2.0.0
 */
final class DeferredExtensionRuntimeWithdrawal implements ExtensionRuntimeWithdrawal
{
    /**
     * The one active graph owned by this process, once runtime loading has completed.
     *
     * @var    ?ActiveExtensionSet
     * @since  2.0.0
     */
    private ?ActiveExtensionSet $active = null;

    /**
     * Bind the graph exactly once after its signed publication has loaded.
     *
     * @param   ActiveExtensionSet  $active  Complete resident extension graph.
     *
     * @return  void
     *
     * @throws  LogicException  When composition attempts to replace an already bound graph.
     *
     * @since   2.0.0
     */
    public function bind(ActiveExtensionSet $active): void
    {
        if ($this->active !== null) {
            throw new LogicException('The active extension graph is already bound for withdrawal.');
        }
        $this->active = $active;
    }

    /**
     * Withdraw every package object when a graph is bound; bootstrap-time calls are harmless.
     *
     * A trust failure can invalidate authority while the next graph is still being loaded. There is no
     * resident graph to clear in that phase, so an unbound coordinator intentionally does nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function withdrawAll(): void
    {
        $this->active?->withdrawAll();
    }
}
