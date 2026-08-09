<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\ValidationViolation;

/**
 * Raised when caller-supplied values do not satisfy the definition they are being written against.
 *
 * `RecordRuleValidator` collects every field problem in one pass instead of aborting at the first, and
 * `BusinessRecordService` adds the failures it finds outside field validation — a record scope the
 * definition will not accept, an entity reference naming no record in scope. The whole set travels on
 * this exception so a delivery adapter can report a form's worth of problems at once rather than making
 * the caller resubmit per mistake, which is the reason it carries a list and not a single message.
 *
 * The list is capped at 256 entries so a pathological payload cannot turn one rejected write into an
 * unbounded response body.
 *
 * @since  2.0.0
 */
final class BusinessRecordValidationFailed extends BusinessRecordException
{
    /**
     * Every rule breach found in the rejected payload, in the order the validator discovered them.
     *
     * @var    non-empty-list<ValidationViolation>
     * @since  2.0.0
     */
    public readonly array $violations;

    /**
     * Report a rejected payload under the `business_record.validation_failed` code.
     *
     * @param   non-empty-list<ValidationViolation>  $violations  Field breaches to carry to the caller;
     *          stored re-indexed, so the exposed list has no gaps whatever keys the thrower used.
     *
     * @throws  InvalidArgumentException  When the list is empty or holds more than 256 violations.
     *
     * @since   2.0.0
     */
    public function __construct(array $violations)
    {
        if ($violations === [] || count($violations) > 256) {
            throw new InvalidArgumentException('Validation failure requires a bounded non-empty violation list.');
        }
        $this->violations = array_values($violations);
        parent::__construct('business_record.validation_failed', 'The business record failed validation.');
    }
}
