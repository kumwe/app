<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence;

use InvalidArgumentException;

/**
 * The capacity contract's 30% free operating-space guardrail, checked against the database volume.
 *
 * The database engine will not tell an application account how full its disk is, so the operator names
 * the data directory's mount (`KUMWE_DATABASE_DATA_PATH`) wherever the application can see it — the
 * same host, or a read-only mount of the volume. Without that setting the guardrail reports itself as
 * unconfigured rather than guessing. The report is a diagnostic the readiness probe consults and the
 * operator diagnostics surface can compose.
 *
 * @since  2.0.0
 */
final readonly class FilesystemStorageReserve
{
    /**
     * Bind the guardrail to the database volume path and the required reserve.
     *
     * @param   ?string  $path     Directory on the database data volume, or null when not configured.
     * @param   float    $reserve  Fraction of the volume that must stay free, from the capacity contract.
     *
     * @throws  InvalidArgumentException  When the reserve is not a fraction between zero and one.
     *
     * @since   2.0.0
     */
    public function __construct(private ?string $path, private float $reserve = 0.30)
    {
        if ($reserve <= 0.0 || $reserve >= 1.0) {
            throw new InvalidArgumentException('The storage reserve must be a fraction between zero and one.');
        }
    }

    /**
     * Measure the volume.
     *
     * @return  ?array{path: string, free_bytes: int, total_bytes: int, free_fraction: float, reserve: float,
     *          satisfied: bool}  The measurement, or null when no path is configured or it cannot be read.
     *
     * @since   2.0.0
     */
    public function report(): ?array
    {
        if ($this->path === null || !is_dir($this->path)) {
            return null;
        }
        $free = @disk_free_space($this->path);
        $total = @disk_total_space($this->path);
        if (!is_float($free) || !is_float($total) || $total <= 0.0) {
            return null;
        }
        $fraction = $free / $total;

        return [
            'path' => $this->path,
            'free_bytes' => (int) $free,
            'total_bytes' => (int) $total,
            'free_fraction' => round($fraction, 4),
            'reserve' => $this->reserve,
            'satisfied' => $fraction >= $this->reserve,
        ];
    }
}
