<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

use InvalidArgumentException;

/**
 * Bounded response returned by a direct, TLS-verified connection to one pinned address.
 *
 * @since  2.0.0
 */
final readonly class StudioPinnedHttpResponse
{
    /**
     * Capture normalized lowercase response headers and a private body path.
     *
     * @param  int                    $status   HTTP status.
     * @param  array<string, string>  $headers  Lowercase single-value headers.
     * @param  string                 $path     Private downloaded body path.
     * @param  int                    $bytes    Exact decoded body size.
     *
     * @since  2.0.0
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $path,
        public int $bytes,
    ) {
        if ($status < 100 || $status > 599 || $bytes < 0 || $path === '') {
            throw new InvalidArgumentException('A pinned Studio media response is invalid.');
        }
    }
}
