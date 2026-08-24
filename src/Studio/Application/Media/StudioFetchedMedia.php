<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

use InvalidArgumentException;

/**
 * Verified bounded external-media payload ready for the existing App media module.
 *
 * @since  2.0.0
 */
final readonly class StudioFetchedMedia
{
    /**
     * Capture one private payload without retaining its source URL or address.
     *
     * @param  string  $path       Private body path.
     * @param  string  $filename   Safe display filename.
     * @param  string  $mediaType  Byte-verified media type.
     * @param  int     $byteSize   Exact bounded size.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $path,
        public string $filename,
        public string $mediaType,
        public int $byteSize,
    ) {
        if ($path === '' || $filename === '' || $mediaType === '' || $byteSize < 1) {
            throw new InvalidArgumentException('A fetched Studio media payload is invalid.');
        }
    }
}
