<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Application;

use Kumwe\CMS\BusinessReporting\Domain\ProjectionDefinition;

/**
 * Replaceable derived-store writer used inside one projection rebuild transaction.
 *
 * @since  2.0.0
 */
interface ProjectionWriter
{
    /**
     * Start a replacement generation and clear no authoritative source data.
     *
     * @param   ProjectionDefinition  $definition  Exact projection contract being rebuilt.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function begin(ProjectionDefinition $definition): void;

    /**
     * Replace one derived row by its declared composite key.
     *
     * @param   array<string, bool|int|string>       $key     Values for every declared key field.
     * @param   array<string, bool|int|string|null>  $values  Complete typed derived row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function put(array $key, array $values): void;

    /**
     * Delete one derived row by its declared composite key.
     *
     * @param   array<string, bool|int|string>  $key  Values for every declared key field.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(array $key): void;

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
     * Atomically publish the replacement generation.
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
