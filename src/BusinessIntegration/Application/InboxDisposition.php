<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

/**
 * Explicit outcome of offering an event to a durable consumer inbox.
 *
 * @since  2.0.0
 */
enum InboxDisposition: string
{
    /** The caller owns a fresh fenced delivery lease. @since 2.0.0 */
    case CLAIMED = 'claimed';

    /** The consumer already completed this event or advanced beyond its aggregate version. @since 2.0.0 */
    case DUPLICATE = 'duplicate';

    /** An earlier aggregate version must complete before this event may run. @since 2.0.0 */
    case REORDERED = 'reordered';

    /** Another worker still owns the unexpired delivery lease. @since 2.0.0 */
    case BUSY = 'busy';

    /** The delivery exhausted retries or failed permanently. @since 2.0.0 */
    case POISON = 'poison';

    /** The active consumer generation does not support this event contract. @since 2.0.0 */
    case UNAVAILABLE = 'unavailable';
}
