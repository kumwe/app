<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Host;

use InvalidArgumentException;

/**
 * Durable claim and optional protected outcome for one App-namespaced Producer mutation scope.
 *
 * @since  2.0.0
 */
final readonly class StudioMutationReplayRecord
{
    /**
     * Retain the proved coordinates and optional protected completed outcome.
     *
     * @param   string       $scopeDigest       App-namespaced lowercase SHA-256 scope digest.
     * @param   string       $intentDigest      Producer's canonical SRI SHA-256 intent digest.
     * @param   string|null  $protectedOutcome  Authenticated completed outcome, null while claimed.
     *
     * @throws  InvalidArgumentException  When either coordinate or a present envelope is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $scopeDigest,
        public string $intentDigest,
        public ?string $protectedOutcome,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $scopeDigest) !== 1) {
            throw new InvalidArgumentException('A Studio mutation replay scope digest is invalid.');
        }
        if (preg_match('/^sha256-[A-Za-z0-9+\/]{42}[AEIMQUYcgkosw048]=$/D', $intentDigest) !== 1) {
            throw new InvalidArgumentException('A Studio mutation replay intent digest is invalid.');
        }
        if ($protectedOutcome !== null && !str_starts_with($protectedOutcome, 'v1.')) {
            throw new InvalidArgumentException('A Studio mutation replay outcome is not a supported envelope.');
        }
    }
}
