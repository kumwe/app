<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

/**
 * Resolves a normalized external-media host to the addresses a pinned connection may use.
 *
 * @since  2.0.0
 */
interface StudioExternalAddressResolver
{
    /**
     * Resolve every A and AAAA answer without following application-level redirects.
     *
     * @param   string  $host  Normalized ASCII hostname without IPv6 brackets.
     *
     * @return  list<string>  Unique textual addresses in deterministic order.
     *
     * @since   2.0.0
     */
    public function resolve(string $host): array;
}
