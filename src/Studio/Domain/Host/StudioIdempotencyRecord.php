<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Host;

use RuntimeException;

/**
 * Durable claim and completed result for one Studio host mutation scope.
 *
 * @since  2.0.0
 */
final readonly class StudioIdempotencyRecord
{
    /**
     * Retain the proved intent and optional canonical completed host result.
     *
     * @param  string       $scopeDigest   Digest of actor/session/resource/operation/key.
     * @param  string       $intentDigest  Digest of semantic argument and intent-bearing context.
     * @param  string|null  $resultBytes   Completed canonical host-result bytes, null while claimed.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $scopeDigest,
        public string $intentDigest,
        public ?string $resultBytes,
    ) {
        if (
            preg_match('/^[a-f0-9]{64}$/D', $scopeDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $intentDigest) !== 1
        ) {
            throw new RuntimeException('A Studio idempotency record is invalid.');
        }
    }
}
