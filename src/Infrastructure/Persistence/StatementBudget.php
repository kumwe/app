<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence;

use InvalidArgumentException;

/**
 * The server execution time and materialized bytes one bounded read statement may use.
 *
 * The time is enforced by the database engine, which cancels the statement and releases its snapshot;
 * the bytes are counted as rows are materialized, so a page whose column payload passes the ceiling is
 * refused before it is decoded, authorized field by field or serialized for a surface.
 *
 * @since  2.0.0
 */
final readonly class StatementBudget
{
    /**
     * Longest server execution time any bounded statement may be granted, in milliseconds.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_TIMEOUT_MILLISECONDS = 30_000;

    /**
     * Largest materialized payload any bounded statement may be granted, in bytes (64 MiB).
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_BYTES = 67_108_864;

    /**
     * Declare the bounds, refusing values outside the supported range.
     *
     * @param   int  $timeoutMilliseconds  Server execution time, 1 to 30,000 milliseconds.
     * @param   int  $maximumBytes         Materialized column bytes, 1 byte to 64 MiB.
     *
     * @throws  InvalidArgumentException  When either bound is outside its range.
     *
     * @since   2.0.0
     */
    public function __construct(public int $timeoutMilliseconds = 5_000, public int $maximumBytes = 8_388_608)
    {
        if ($timeoutMilliseconds < 1 || $timeoutMilliseconds > self::MAXIMUM_TIMEOUT_MILLISECONDS) {
            throw new InvalidArgumentException('A statement timeout must be between 1 and 30000 milliseconds.');
        }
        if ($maximumBytes < 1 || $maximumBytes > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('A statement byte budget must be between 1 byte and 64 MiB.');
        }
    }
}
