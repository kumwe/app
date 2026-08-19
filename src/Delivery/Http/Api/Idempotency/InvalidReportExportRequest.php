<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Idempotency;

use InvalidArgumentException;

/**
 * Signals that a report-export route identifier is malformed before the idempotency ledger is touched.
 *
 * @since  2.0.0
 */
final class InvalidReportExportRequest extends InvalidArgumentException
{
}
