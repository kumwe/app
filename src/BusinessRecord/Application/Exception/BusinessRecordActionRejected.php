<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

/**
 * Raised when a declared business action cannot be executed against the record it was aimed at.
 *
 * `BusinessRecordService::action()` throws this at every point where the action itself is the problem
 * rather than the record's identity or version: the pinned definition declares no action under that
 * handle, the command carries action input where no typed input is accepted, the action's condition
 * expression evaluates against the record to anything but true, the action names no workflow
 * transition, or the transition it names does not leave the state the record is currently in.
 *
 * Two of those verdicts — the condition, and whether the transition starts from the state the record
 * is in — turn on the record rather than the definition, so the same action can be accepted for one
 * record and refused for another, and retrying without a state change fails identically. Refusals
 * that come from the authorization gateway, on the action's capability or the transition's, are
 * raised by the gateway under its own type and are not folded into this one.
 *
 * @since  2.0.0
 */
final class BusinessRecordActionRejected extends BusinessRecordException
{
    /**
     * Report a rejected action under the `business_record.action_rejected` code.
     *
     * @param  string  $reason  Operator-facing sentence naming which check refused the action; every
     *         throw site in the service supplies its own, so the default is a fallback.
     *
     * @since  2.0.0
     */
    public function __construct(string $reason = 'The requested business action is not valid for this record.')
    {
        parent::__construct('business_record.action_rejected', $reason);
    }
}
