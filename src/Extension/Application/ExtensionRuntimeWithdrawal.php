<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application;

/**
 * Withdraws executable extension objects after their published generation is superseded.
 *
 * Trust and lifecycle application services call this port only after their durable authority change
 * commits. Runtime composition supplies a late-bound implementation because the active extension set
 * itself cannot exist until the trust boundary has verified and loaded the signed publication.
 *
 * @since  2.0.0
 */
interface ExtensionRuntimeWithdrawal
{
    /**
     * Remove every resident object owned by the superseded extension generation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function withdrawAll(): void;
}
