<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

use Kumwe\App\BusinessRecord\Application\DocumentCommitObserver;

/**
 * Publishes committed documents as the capacity contract's document operation classes.
 *
 * A document of at most one hundred lines is a `document_100_line_commit` and anything larger a
 * `document_1000_line_commit`, matching the latency objectives; lines are counted separately from the
 * logical transaction, as the contract's counting rule requires.
 *
 * @since  2.0.0
 */
final readonly class MetricDocumentCommitObserver implements DocumentCommitObserver
{
    /**
     * Bind the observer to the recorder.
     *
     * @param  MetricRecorder  $metrics  Recorder the histogram and counter are written to.
     *
     * @since  2.0.0
     */
    public function __construct(private MetricRecorder $metrics)
    {
    }

    /**
     * Record one committed document.
     *
     * @param   int    $lines         Owned lines the command carried.
     * @param   float  $milliseconds  Wall time of the whole command.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function committed(int $lines, float $milliseconds): void
    {
        $this->metrics->observe(
            MetricCatalog::OPERATION_DURATION,
            ['operation_class' => $lines <= 100 ? 'document_100_line_commit' : 'document_1000_line_commit'],
            $milliseconds / 1_000,
        );
        if ($lines > 0) {
            $this->metrics->increment(MetricCatalog::DOCUMENT_LINES, [], (float) $lines);
        }
    }
}
