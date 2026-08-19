<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Exception;

/**
 * Signals that no business record answers the identity a command or query addressed.
 *
 * The record layer collapses three distinct situations into this single answer: no such record, a
 * record the caller's site and organization scope does not reach, and an identifier that is not a
 * usable identity for the pinned definition. A caller therefore cannot tell a record it may not see
 * from one that does not exist. Read repositories return null and their callers convert that here;
 * the write repository raises it directly when a row it was about to update turns out to have gone.
 * Callers that treat an unresolvable reference as a validation problem rather than a failed
 * operation catch it instead of letting it propagate.
 *
 * @since  2.0.0
 */
final class BusinessRecordNotFound extends BusinessRecordException
{
    /**
     * Fix the stable code and operator message every instance carries.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('business_record.not_found', 'The business record was not found.');
    }
}
