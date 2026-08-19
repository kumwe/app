<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * Base class for the failures the business-record application layer reports to its callers.
 *
 * Every subclass pairs a machine-readable code with an operator-readable sentence, so a delivery
 * adapter or a test can identify a failure by its code and never by matching message text — the
 * messages are free to be reworded, the codes are not. All codes live under the `business_record.`
 * prefix, which is what makes them recognisable as coming from this module. Every concrete failure
 * below it is final and hard-codes its own code in its constructor, so the code and the class stay in
 * step; this base is the one class left open, and catching it is how a caller handles the whole family
 * at once.
 *
 * It extends `RuntimeException` rather than a logic-error type because these report a state the caller
 * could not have checked in advance — a record that has gone, a version that moved under it, a
 * definition withdrawn mid-flight. A malformed argument is a different thing, and the commands in this
 * module reject that as `InvalidArgumentException` before any of this is reached.
 *
 * @since  2.0.0
 */
class BusinessRecordException extends RuntimeException
{
    /**
     * Bind a stable failure code to the sentence an operator reads.
     *
     * @param  string      $stableCode  Machine-readable code for this failure, prefixed
     *         `business_record.`; part of the released API.
     * @param  string      $message     Complete sentence addressed to an operator, carrying no
     *         secrets, credentials or raw request data.
     * @param  ?Throwable  $previous    Driver or domain failure being translated, or null when this
     *         exception is the origin.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly string $stableCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Return the code a caller branches on to tell this failure apart from its siblings.
     *
     * @return  string  Stable identifier such as `business_record.not_found`, safe to log, compare
     *          and map onto a transport response.
     *
     * @since   2.0.0
     */
    public function stableCode(): string
    {
        return $this->stableCode;
    }
}
