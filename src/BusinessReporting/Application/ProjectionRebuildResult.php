<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use InvalidArgumentException;

/**
 * Reproducibility evidence returned by a completed projection rebuild.
 *
 * @since  2.0.0
 */
final readonly class ProjectionRebuildResult
{
    /**
     * Capture terminal sequence and checksums.
     *
     * @param   int     $lastSequence        Last applied sequence, zero for an empty source.
     * @param   int     $eventCount          Number of applied events.
     * @param   string  $sourceChecksum      Rolling checksum of the ordered event stream.
     * @param   string  $projectionChecksum  Canonical checksum returned by the derived store.
     *
     * @throws  InvalidArgumentException  When counters or checksums are malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public int $lastSequence,
        public int $eventCount,
        public string $sourceChecksum,
        public string $projectionChecksum,
    ) {
        if (
            $lastSequence < 0 || $eventCount < 0
            || preg_match('/^[0-9a-f]{64}$/D', $sourceChecksum) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $projectionChecksum) !== 1
        ) {
            throw new InvalidArgumentException('Projection rebuild evidence is invalid.');
        }
    }
}
