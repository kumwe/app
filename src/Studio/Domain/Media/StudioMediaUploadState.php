<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Media;

/**
 * Canonical Studio media upload-session lifecycle states.
 *
 * @since  2.0.0
 */
enum StudioMediaUploadState: string
{
    /**
     * Request exists locally but no host plan has been attached.
     *
     * @since  2.0.0
     */
    case Requested = 'requested';

    /**
     * Host policy accepted the request and issued a bounded grant.
     *
     * @since  2.0.0
     */
    case Authorized = 'authorized';

    /**
     * Bytes are moving only to the host-controlled staging destination.
     *
     * @since  2.0.0
     */
    case Transferring = 'transferring';

    /**
     * Transfer closed and the host is verifying actual bytes.
     *
     * @since  2.0.0
     */
    case Verifying = 'verifying';

    /**
     * Durable accepted media identity has been minted.
     *
     * @since  2.0.0
     */
    case Complete = 'complete';

    /**
     * Policy, transfer or verification failed without an accepted asset.
     *
     * @since  2.0.0
     */
    case Failed = 'failed';

    /**
     * Caller cancelled an active session and no asset was accepted.
     *
     * @since  2.0.0
     */
    case Cancelled = 'cancelled';

    /**
     * Apply the canonical cancellation table without mutating a completed session.
     *
     * @return  self  `Complete` remains complete; every active state becomes cancelled.
     *
     * @since   2.0.0
     */
    public function cancelled(): self
    {
        return match ($this) {
            self::Requested, self::Authorized, self::Transferring, self::Verifying => self::Cancelled,
            default => $this,
        };
    }
}
