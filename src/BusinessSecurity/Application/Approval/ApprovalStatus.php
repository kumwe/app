<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSecurity\Application\Approval;

/**
 * Lifecycle of a single-use approval request.
 *
 * @since  2.0.0
 */
enum ApprovalStatus: string
{
    /** Awaiting quorum. @since 2.0.0 */
    case Pending = 'pending';

    /** Quorum reached and ready for the requester to consume. @since 2.0.0 */
    case Approved = 'approved';

    /** An eligible approver rejected the request. @since 2.0.0 */
    case Rejected = 'rejected';

    /** The requester cancelled before consumption. @since 2.0.0 */
    case Cancelled = 'cancelled';

    /** An administrator revoked a previously approved request. @since 2.0.0 */
    case Revoked = 'revoked';

    /** The bound high-impact action consumed the request. @since 2.0.0 */
    case Consumed = 'consumed';
}
