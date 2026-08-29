<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionWriter;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ProjectionDefinition;

/**
 * Host-owned atomic generation lifecycle around the SDK row-writer surface.
 *
 * Extension builders receive only `ProjectionWriter`; the application retains generation creation,
 * checkpointing, activation and rollback authority through this persistence port.
 *
 * @since  2.0.0
 */
interface ProjectionGenerationWriter extends ProjectionWriter
{
    /**
     * Start a replacement generation without clearing authoritative source data.
     *
     * @param   ProjectionDefinition  $definition  Exact canonical projection contract being rebuilt.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function begin(ProjectionDefinition $definition): void;

    /**
     * Persist an applied source checkpoint in the replacement generation.
     *
     * @param   int     $sequence       Last applied source sequence.
     * @param   string  $eventChecksum  Running source checksum at that point.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function checkpoint(int $sequence, string $eventChecksum): void;

    /**
     * Atomically publish the completed replacement generation.
     *
     * @return  string  Lowercase SHA-256 over canonical sorted rows in the published generation.
     *
     * @since   2.0.0
     */
    public function commit(): string;

    /**
     * Discard the incomplete replacement generation after a failed rebuild.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function rollback(): void;
}
