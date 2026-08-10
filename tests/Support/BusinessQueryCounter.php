<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Counts only SQL executions emitted by Doctrine's driver logging middleware.
 *
 * @since  2.0.0
 */
final class BusinessQueryCounter extends AbstractLogger
{
    /**
     * Number of executed SQL statements since the last reset.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $queries = 0;

    /**
     * Count a DBAL query or prepared statement while ignoring connection and transaction messages.
     *
     * @param   mixed                 $level    PSR log level.
     * @param   Stringable|string     $message  Doctrine log template.
     * @param   array<string, mixed>  $context  Bounded statement context.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        if ((string) $level === LogLevel::DEBUG && str_starts_with((string) $message, 'Executing ')) {
            ++$this->queries;
        }
    }

    /**
     * Start a fresh query measurement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function reset(): void
    {
        $this->queries = 0;
    }

    /**
     * Return the number of executed SQL statements in the current measurement.
     *
     * @return  int  Executed statement count.
     *
     * @since   2.0.0
     */
    public function queries(): int
    {
        return $this->queries;
    }
}
