<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Media;

/**
 * Stable rejection vocabulary exported by Studio's canonical external-URL policy.
 *
 * @since  2.0.0
 */
enum StudioExternalUrlRejection: string
{
    /**
     * The candidate carries user information and could hide its actual authority.
     *
     * @since  2.0.0
     */
    case CredentialsInUrl = 'credentials-in-url';

    /**
     * The parsed authority is empty, private, loopback, link-local or special-use.
     *
     * @since  2.0.0
     */
    case HostNotAllowed = 'host-not-allowed';

    /**
     * The candidate cannot be interpreted as one absolute URL.
     *
     * @since  2.0.0
     */
    case Malformed = 'malformed';

    /**
     * The parsed scheme is outside the closed policy allowlist.
     *
     * @since  2.0.0
     */
    case SchemeNotAllowed = 'scheme-not-allowed';

    /**
     * The raw candidate exceeds the policy's pre-parse code-unit bound.
     *
     * @since  2.0.0
     */
    case UrlTooLong = 'url-too-long';
}
