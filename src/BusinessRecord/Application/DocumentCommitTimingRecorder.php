<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

/**
 * Collects one document command's phase durations while the command runs, and keeps the last commit's.
 *
 * The recorder is armed by the document command and silent otherwise: a phase reported while no frame is
 * open is dropped, which is what lets shared collaborators — the fence path, the publication — report
 * their spans unconditionally without the ordinary record commands paying for a measurement nobody
 * reads. One instance is shared through the container, so the perf harness and the integration suite
 * read the same timings the command recorded, rather than re-deriving them from the outside.
 *
 * A command that retries accumulates across its attempts, because the cost of the commit is what every
 * attempt cost together. The frame survives until the command commits or abandons it, and `latest()`
 * answers the last committed frame only — an abandoned command leaves the previous answer standing.
 *
 * @since  2.0.0
 */
final class DocumentCommitTimingRecorder
{
    /**
     * Whether a document command currently has a frame open.
     *
     * @var    bool
     * @since  2.0.0
     */
    private bool $open = false;

    /**
     * Milliseconds accumulated per phase inside the open frame.
     *
     * @var    array<string, float>
     * @since  2.0.0
     */
    private array $phases = [];

    /**
     * The last committed frame, or null before the first document command completes.
     *
     * @var    DocumentCommitTimings|null
     * @since  2.0.0
     */
    private ?DocumentCommitTimings $latest = null;

    /**
     * Open a frame for one document command, discarding anything a broken command left behind.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function begin(): void
    {
        $this->open = true;
        $this->phases = [];
    }

    /**
     * Accumulate wall time into one phase of the open frame; silently dropped when no frame is open.
     *
     * @param   string  $phase         Phase name, from the commit-timing vocabulary.
     * @param   float   $milliseconds  Wall time to add.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function add(string $phase, float $milliseconds): void
    {
        if (!$this->open) {
            return;
        }
        $this->phases[$phase] = ($this->phases[$phase] ?? 0.0) + $milliseconds;
    }

    /**
     * Milliseconds accumulated so far in one phase of the open frame.
     *
     * An outer span uses this to subtract the lock waits reported while it ran, so the phases stay
     * disjoint instead of counting the same wall time twice.
     *
     * @param   string  $phase  Phase name to read.
     *
     * @return  float  Accumulated milliseconds; zero while no frame is open.
     *
     * @since   2.0.0
     */
    public function accumulated(string $phase): float
    {
        return $this->phases[$phase] ?? 0.0;
    }

    /**
     * Close the open frame as the last committed measurement.
     *
     * @param   float  $totalMs  Wall time of the whole command.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function commit(float $totalMs): void
    {
        if (!$this->open) {
            return;
        }
        $this->latest = new DocumentCommitTimings(
            $this->phases['validation'] ?? 0.0,
            $this->phases['lock_wait'] ?? 0.0,
            $this->phases['write'] ?? 0.0,
            $this->phases['revision'] ?? 0.0,
            $this->phases['audit'] ?? 0.0,
            $this->phases['event'] ?? 0.0,
            $totalMs,
        );
        $this->open = false;
        $this->phases = [];
    }

    /**
     * Drop the open frame without recording it, after a command failed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function abandon(): void
    {
        $this->open = false;
        $this->phases = [];
    }

    /**
     * The last committed command's timings, or null before any document command completed.
     *
     * @return  DocumentCommitTimings|null  The last committed frame.
     *
     * @since   2.0.0
     */
    public function latest(): ?DocumentCommitTimings
    {
        return $this->latest;
    }
}
