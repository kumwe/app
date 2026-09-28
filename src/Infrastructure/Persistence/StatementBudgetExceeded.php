<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence;

use RuntimeException;
use Throwable;

/**
 * A bounded read statement was cancelled by the engine's timeout or passed its byte budget.
 *
 * @since  2.0.0
 */
final class StatementBudgetExceeded extends RuntimeException
{
    /**
     * The engine cancelled the statement at its execution-time budget.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string TIME = 'time';

    /**
     * The materialized rows passed the statement's byte budget.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string BYTES = 'bytes';

    /**
     * Record which bound was exceeded and by how much.
     *
     * @param  self::TIME|self::BYTES  $bound     Bound that stopped the statement.
     * @param  int                     $limit     Budget in milliseconds or bytes.
     * @param  ?Throwable              $previous  Engine cancellation, when the bound is time.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly string $bound, public readonly int $limit, ?Throwable $previous = null)
    {
        parent::__construct(
            $bound === self::TIME
                ? sprintf('The statement was cancelled at its %d ms execution-time budget.', $limit)
                : sprintf('The statement result passed its %d byte budget.', $limit),
            0,
            $previous,
        );
    }
}
