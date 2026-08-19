<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Presentation;

use Kumwe\App\Portal\Application\PortalSession;

/**
 * Applies request-session conditions that cannot be expressed by static capability-owned navigation.
 *
 * @since  2.0.0
 */
interface PortalNavigationVisibility
{
    /**
     * Decide whether one already capability- and trust-filtered shell item remains visible.
     *
     * @param   PortalSession              $session  Live portal session and membership snapshot.
     * @param   array<string, int|string>  $item     Safe navigation row produced by the registry.
     *
     * @return  bool  True when the item remains visible for this request session.
     *
     * @since   2.0.0
     */
    public function visible(PortalSession $session, array $item): bool;
}
