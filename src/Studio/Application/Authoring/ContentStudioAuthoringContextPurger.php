<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

/**
 * Reclaims contextual Content authoring bindings after their hard authority window closes.
 *
 * A binding is unusable at its expiry even before physical deletion. Implementations remove only
 * expired rows in bounded batches so the opaque-context table cannot grow without limit once hosted
 * contextual authoring begins issuing keys.
 *
 * @since  2.0.0
 */
interface ContentStudioAuthoringContextPurger
{
    /**
     * Delete one bounded batch of bindings whose exclusive expiry has been reached.
     *
     * @param   int  $batchSize  Maximum rows to remove in this pass, between 1 and 10000.
     *
     * @return  int  Rows actually deleted; fewer than the limit means the observed backlog is drained.
     *
     * @since   2.0.0
     */
    public function purgeExpired(int $batchSize = 1_000): int;
}
