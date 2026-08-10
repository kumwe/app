<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Business;

use RuntimeException;

/**
 * Signals that a business-record mutation carried no `If-Match` for the current visible version.
 *
 * The supplied version is deliberately absent from the exception. The request grammar accepts exactly one
 * parsed canonical `vN` tag so the integer can travel into the application command without a pre-read that
 * would prevent an idempotent retry from reaching its stored outcome. Quoting the rejected tag adds no
 * recovery value. `BusinessRecordApiResponder` maps this and an in-transaction version race onto the same
 * 412 problem.
 *
 * @since  2.0.0
 */
final class BusinessRecordPreconditionFailed extends RuntimeException
{
    /**
     * Construct the fixed, request-data-free precondition failure.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('The supplied If-Match value does not identify the current business-record version.');
    }
}
