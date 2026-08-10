<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use InvalidArgumentException;

/**
 * Inbox disposition paired with a lease exactly when delivery was claimed.
 *
 * @since  2.0.0
 */
final readonly class InboxClaimResult
{
    /**
     * Build a consistent claim result.
     *
     * @param   InboxDisposition  $disposition  Explicit delivery outcome.
     * @param   ?InboxLease       $lease        Fenced claim only for `CLAIMED`.
     *
     * @throws  InvalidArgumentException  When disposition and lease disagree.
     *
     * @since   2.0.0
     */
    public function __construct(public InboxDisposition $disposition, public ?InboxLease $lease = null)
    {
        if (($disposition === InboxDisposition::CLAIMED) !== ($lease !== null)) {
            throw new InvalidArgumentException('Only a claimed inbox result may carry a lease.');
        }
    }
}
