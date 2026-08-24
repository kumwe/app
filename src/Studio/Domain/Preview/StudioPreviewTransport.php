<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Preview;

use InvalidArgumentException;

/**
 * Browser-channel evidence carried outside the canonical host-operation argument.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewTransport
{
    /**
     * Capture origin, channel/source identities and the monotonic delivery sequence.
     *
     * @param   string  $origin     Browser-supplied absolute origin.
     * @param   string  $channelId  Server-issued preview channel identity.
     * @param   string  $sourceId   Server-issued expected browser source identity.
     * @param   int     $sequence   Zero-based monotonically increasing sequence.
     *
     * @throws  InvalidArgumentException  When a field is lexically malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $origin,
        public string $channelId,
        public string $sourceId,
        public int $sequence,
    ) {
        if (filter_var($origin, FILTER_VALIDATE_URL) === false || parse_url($origin, PHP_URL_PATH) !== null) {
            throw new InvalidArgumentException('The Studio preview origin is invalid.');
        }
        foreach ([$channelId, $sourceId] as $identity) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,239}$/D', $identity) !== 1) {
                throw new InvalidArgumentException('The Studio preview transport identity is invalid.');
            }
        }
        if ($sequence < 0) {
            throw new InvalidArgumentException('The Studio preview sequence is invalid.');
        }
    }
}
