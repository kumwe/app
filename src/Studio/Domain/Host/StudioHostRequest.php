<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Host;

use stdClass;

/**
 * Schema-validated canonical Studio host request consumed by the dispatcher.
 *
 * @since  2.0.0
 */
final readonly class StudioHostRequest
{
    /**
     * Retain the already validated envelope without adding identity or authorization evidence.
     *
     * @param  string         $operationId         Canonical operation capability.
     * @param  string         $protocolVersion     Negotiated wire protocol.
     * @param  string         $requestId           Client correlation identifier.
     * @param  string         $resourceContextKey  Opaque server-issued resource binding.
     * @param  string         $sessionGeneration   Client's open-time authority generation.
     * @param  mixed          $arguments           Canonical JSON operation argument, when present.
     * @param  string|null    $expectedRevision    Optimistic revision, when required.
     * @param  string|null    $idempotencyKey      Retry identity, when supplied.
     * @param  string|null    $locale              Requested diagnostic locale.
     * @param  stdClass|null  $traceContext        Bounded non-authoritative tracing context.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $operationId,
        public string $protocolVersion,
        public string $requestId,
        public string $resourceContextKey,
        public string $sessionGeneration,
        public mixed $arguments,
        public ?string $expectedRevision,
        public ?string $idempotencyKey,
        public ?string $locale,
        public ?stdClass $traceContext,
    ) {
    }
}
