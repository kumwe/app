<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Media;

use Kumwe\App\Studio\Application\Media\StudioExternalAddressResolver;

/**
 * Native A/AAAA resolver used only after Studio's lexical URL policy has accepted a hostname.
 *
 * @since  2.0.0
 */
final readonly class NativeStudioExternalAddressResolver implements StudioExternalAddressResolver
{
    /**
     * Resolve literal addresses directly or collect all native A and AAAA answers deterministically.
     *
     * @param   string  $host  Normalized ASCII hostname or textual IP literal.
     *
     * @return  list<string>  Unique answers ordered textually.
     *
     * @since   2.0.0
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }
        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = strtolower($address);
            }
        }
        $addresses = array_values(array_unique($addresses));
        sort($addresses, SORT_STRING);

        return $addresses;
    }
}
