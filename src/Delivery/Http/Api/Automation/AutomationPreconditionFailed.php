<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Automation;

use DomainException;

/**
 * Signals that an `If-Match` precondition names a schedule revision other than the stored one.
 *
 * `AutomationApiHandler` raises it before a schedule update or delete touches the store, so a client
 * that read revision 4 and sends its edit after someone else saved revision 5 is refused instead of
 * overwriting work it never saw. The handler renders it as a 412 problem document; the distinction
 * from a missing or malformed header matters, because those are `RequireIfMatchMiddleware`'s 428 and
 * 400 and mean the client can retry unchanged, whereas this one means it must re-read first.
 *
 * @since  2.0.0
 */
final class AutomationPreconditionFailed extends DomainException
{
    /**
     * Build the failure with the fixed, operator-facing message the 412 problem document carries.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('The supplied schedule ETag does not match the current version.');
    }
}
