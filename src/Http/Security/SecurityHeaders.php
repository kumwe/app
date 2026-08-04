<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Security;

final readonly class SecurityHeaders
{
    public function __construct(private bool $enableHsts)
    {
    }

    /**
     * @return array<string, string>
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
