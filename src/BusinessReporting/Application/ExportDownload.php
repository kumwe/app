<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Application;

use InvalidArgumentException;

/**
 * Authorized verified stream and safe response metadata for one completed export.
 *
 * @since  2.0.0
 */
final readonly class ExportDownload
{
    /**
     * Capture a verified read-only export stream.
     *
     * @param   resource  $stream    Stream positioned at byte zero.
     * @param   string    $filename  Safe attachment filename.
     * @param   int       $size      Exact content length.
     * @param   string    $checksum  SHA-256 ETag material.
     *
     * @throws  InvalidArgumentException  When the stream or evidence is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public mixed $stream,
        public string $filename,
        public int $size,
        public string $checksum,
    ) {
        if (!is_resource($stream) || $size < 1 || preg_match('/^[0-9a-f]{64}$/D', $checksum) !== 1) {
            throw new InvalidArgumentException('An export download stream or evidence is invalid.');
        }
    }
}
