<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

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
     * The SQL texts executed since the last reset with their bound parameters, in execution order,
     * bounded so a runaway measurement cannot hold a whole soak run in memory.
     *
     * @var    list<array{sql: string, params: array<int|string, mixed>}>
     * @since  2.0.0
     */
    private array $statements = [];

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
            $sql = $context['sql'] ?? null;
            $params = $context['params'] ?? [];
            if (is_string($sql) && count($this->statements) < 10000) {
                $this->statements[] = ['sql' => $sql, 'params' => is_array($params) ? $params : []];
            }
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
        $this->statements = [];
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

    /**
     * The SQL texts executed in the current measurement, in execution order.
     *
     * A plan-capture test runs a real operation and asks this for the statement the runtime actually
     * compiled, so what is EXPLAINed is the shipped query rather than a hand-written imitation of it.
     * The bound parameters ride along so a capture can rebuild an executable statement.
     *
     * @return  list<array{sql: string, params: array<int|string, mixed>}>  Executed statements since the
     *          last reset.
     *
     * @since   2.0.0
     */
    public function statements(): array
    {
        return $this->statements;
    }
}
