<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use RuntimeException;

/**
 * Typed, delivery-safe refusal raised by a Studio host port implementation.
 *
 * @since  2.0.0
 */
final class StudioHostOperationRefused extends RuntimeException
{
    /**
     * Carry only canonical protocol fields; internal policy and persistence details never cross delivery.
     *
     * @param  string       $category                Canonical host-error category.
     * @param  string       $diagnosticCode          Stable Studio diagnostic code.
     * @param  string|null  $revision                Safe current revision on an optimistic conflict.
     * @param  bool         $retryable               Whether the exact request may be retried.
     * @param  int|null     $retryAfterMilliseconds  Deterministic retry delay for rate limiting.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $category,
        public readonly string $diagnosticCode,
        public readonly ?string $revision = null,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterMilliseconds = null,
    ) {
        parent::__construct($diagnosticCode);
    }
}
