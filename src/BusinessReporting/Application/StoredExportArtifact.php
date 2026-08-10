<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Application;

use InvalidArgumentException;

/**
 * Immutable byte count and checksum returned after a private atomic store.
 *
 * @since  2.0.0
 */
final readonly class StoredExportArtifact
{
    /**
     * Capture private storage evidence.
     *
     * @param   string  $key       Opaque storage key.
     * @param   int     $size      Positive byte count.
     * @param   string  $checksum  Lowercase SHA-256 of stored bytes.
     *
     * @throws  InvalidArgumentException  When evidence is malformed.
     *
     * @since   2.0.0
     */
    public function __construct(public string $key, public int $size, public string $checksum)
    {
        if (preg_match('/^[0-9a-f-]{36}\.csv$/D', $key) !== 1 || $size < 1
            || preg_match('/^[0-9a-f]{64}$/D', $checksum) !== 1
        ) {
            throw new InvalidArgumentException('Stored export evidence is invalid.');
        }
    }
}
