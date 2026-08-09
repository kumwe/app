<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Security;

/**
 * Response header policy that every Kumwe response is hardened with.
 *
 * The policy sits in a plain builder rather than inside the middleware so the exact header set is
 * unit-testable and identical on every delivery path. `SecurityHeadersMiddleware` builds one per
 * response and copies the result onto it. HSTS is constructor-gated because pinning a browser to HTTPS
 * from a development host, or from a plain HTTP request, locks visitors out of a site that cannot
 * serve it; the caller decides, this class only spells the policy out.
 *
 * @since  2.0.0
 */
final readonly class SecurityHeaders
{
    /**
     * Fix the transport policy for the header sets this instance builds.
     *
     * @param  bool  $enableHsts  Whether to emit `Strict-Transport-Security`; enable only on production HTTPS.
     *
     * @since  2.0.0
     */
    public function __construct(private bool $enableHsts)
    {
    }

    /**
     * Build the header names and values to copy onto an outgoing response.
     *
     * Passing a nonce is what makes an inline script executable under this policy; without one
     * `script-src` admits same-origin script files only, which is the safe default for pages that
     * carry no inline script at all.
     *
     * @param   ?string  $scriptNonce  Nonce that admits matching inline scripts, or null to allow none.
     *
     * @return  array<string, string>  Header name to value; `Strict-Transport-Security` only when enabled.
     *
     * @since   2.0.0
     */
    public function values(?string $scriptNonce = null): array
    {
        $scriptSource = $scriptNonce === null ? "'self'" : sprintf("'self' 'nonce-%s'", $scriptNonce);
        $headers = [
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "base-uri 'self'",
                "connect-src 'self'",
                "font-src 'self' data:",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "img-src 'self' data: blob:",
                "object-src 'none'",
                sprintf('script-src %s', $scriptSource),
                "style-src 'self' 'unsafe-inline'",
                'upgrade-insecure-requests',
            ]),
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ];

        if ($this->enableHsts) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }
}
