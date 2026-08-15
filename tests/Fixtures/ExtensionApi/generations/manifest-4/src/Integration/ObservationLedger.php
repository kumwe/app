<?php

declare(strict_types=1);

namespace KumweContract\ManifestFour\Integration;

/**
 * Process-local, non-authoritative record of what the manifest-4 handlers were asked to do.
 *
 * Nothing outside this package reads it. It exists so each SPI-2 handler has a real body instead of an
 * empty one, which is what makes the lifecycle fixture prove the handlers are wired rather than merely
 * declared.
 *
 * @since  2.0.0
 */
final class ObservationLedger
{
    /**
     * Labels recorded in call order, one per handler invocation.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $entries = [];

    /**
     * Record one handler invocation.
     *
     * @param   string  $label  Stable label naming which handler ran.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(string $label): void
    {
        $this->entries[] = $label;
    }

    /**
     * Report every recorded invocation in call order.
     *
     * @return  list<string>  Labels in the order they were recorded; empty when nothing has run.
     *
     * @since   2.0.0
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
