<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Security;

/**
 * Client-facing view of a request as reported by a chain of trusted proxies.
 *
 * `ForwardedHeaderParser` builds one of these only after walking the forwarding chain back through hops
 * the operator trusts, and `TrustedProxyMiddleware` is the only consumer: it rewrites the request URI
 * and `Host` header from these fields and publishes the client address as a request attribute. Every
 * field is already validated and normalised, so a consumer never re-parses proxy metadata itself.
 *
 * @since  2.0.0
 */
final readonly class ForwardedRequest
{
    /**
     * Capture what the trusted chain reported about the original client request.
     *
     * @param  string   $clientAddress      Bare IP of the outermost untrusted hop, without any port.
     * @param  ?string  $scheme             Lowercase `http` or `https`, or null when none was reported.
     * @param  ?string  $host               Lowercase forwarded host name, or null when none was reported.
     * @param  ?int     $port               Forwarded port, or null when the authority carried none.
     * @param  bool     $authoritySupplied  Whether an authority was reported; a null port then means default.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $clientAddress,
        public ?string $scheme,
        public ?string $host,
        public ?int $port,
        public bool $authoritySupplied,
    ) {
    }
}
