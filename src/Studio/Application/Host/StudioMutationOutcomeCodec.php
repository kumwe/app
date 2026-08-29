<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\Producer\Error\HostError;
use Kumwe\Producer\Wire\HostResult;

/**
 * Protects exact logical Producer mutation outcomes for App-owned durable replay storage.
 *
 * @since  2.0.0
 */
interface StudioMutationOutcomeCodec
{
    /**
     * Seal one already-redacted logical outcome under its complete replay coordinates.
     *
     * @param   HostResult|HostError  $outcome       Canonical success or committed refusal safe for storage.
     * @param   string                $scopeDigest   App-namespaced lowercase SHA-256 replay scope.
     * @param   string                $intentDigest  Producer's canonical SRI SHA-256 intent digest.
     *
     * @return  string  Versioned authenticated envelope suitable for the existing text column.
     *
     * @since   2.0.0
     */
    public function protect(
        HostResult|HostError $outcome,
        string $scopeDigest,
        string $intentDigest,
    ): string;

    /**
     * Authenticate and reconstruct one exact logical outcome from durable replay storage.
     *
     * @param   string  $protectedOutcome  Versioned authenticated storage envelope.
     * @param   string  $scopeDigest       Expected App-namespaced lowercase SHA-256 replay scope.
     * @param   string  $intentDigest      Expected Producer SRI SHA-256 intent digest.
     *
     * @return  HostResult|HostError  Exact canonical success or committed refusal.
     *
     * @throws  StudioMutationOutcomeRejected  When the envelope, binding, or canonical outcome is invalid.
     *
     * @since   2.0.0
     */
    public function recover(
        string $protectedOutcome,
        string $scopeDigest,
        string $intentDigest,
    ): HostResult|HostError;
}
