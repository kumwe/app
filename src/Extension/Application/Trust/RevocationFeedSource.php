<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

use RuntimeException;

/**
 * Port that fetches the raw bytes of an upstream revocation list.
 *
 * The transport is deliberately behind a port and deliberately dumb. It has no say in whether the bytes
 * mean anything: the envelope is Ed25519-signed against a key pinned in configuration and carries its
 * own monotonic sequence, so an implementation could read from a mirror, a mounted file or a plain HTTP
 * response without changing what the installation is willing to believe. That is also why an
 * installation survives an unreachable feed — transport integrity is not load-bearing here.
 *
 * @since  2.0.0
 */
interface RevocationFeedSource
{
    /**
     * Read the configured origin and return the envelope bytes exactly as served.
     *
     * @param   string  $origin  Absolute `https://` URL or absolute local path from configuration.
     *
     * @return  string  Raw envelope bytes; never modified, decoded or re-serialized in transit.
     *
     * @throws  RuntimeException  When the origin cannot be read, answers an error, or serves more than
     *          the accepted maximum; every transport condition arrives as this one exception so the
     *          caller can treat unreachable uniformly.
     *
     * @since   2.0.0
     */
    public function fetch(string $origin): string;
}
