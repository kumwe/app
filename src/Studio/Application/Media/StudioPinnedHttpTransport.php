<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

/**
 * One-hop HTTP transport that connects to a pre-resolved address while verifying TLS for the URL host.
 *
 * @since  2.0.0
 */
interface StudioPinnedHttpTransport
{
    /**
     * Fetch exactly one URL without automatically resolving or following redirects.
     *
     * @param   string  $url             Lexically accepted normalized HTTPS URL.
     * @param   string  $pinnedAddress   Already classified public DNS answer.
     * @param   int     $maximumBytes    Inclusive decoded response-body quota.
     * @param   int     $timeoutSeconds  Connect/read deadline for this hop.
     *
     * @return  StudioPinnedHttpResponse  Bounded response whose body is private host custody.
     *
     * @since   2.0.0
     */
    public function get(
        string $url,
        string $pinnedAddress,
        int $maximumBytes,
        int $timeoutSeconds,
    ): StudioPinnedHttpResponse;
}
