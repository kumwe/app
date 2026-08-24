<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use stdClass;

/**
 * Closed canonical-schema decoder for Studio HTTP host envelopes.
 *
 * @since  2.0.0
 */
final readonly class StudioHostRequestDecoder
{
    /**
     * Bind decoding to the exact vendored Studio contract registry.
     *
     * @param  StudioContractSchemas  $schemas  Pinned offline schema interpreter.
     *
     * @since  2.0.0
     */
    public function __construct(private StudioContractSchemas $schemas)
    {
    }

    /**
     * Validate before reading any envelope member, including client attempts to assert identity or grants.
     *
     * @param   mixed  $document  Decoded JSON candidate from the trusted HTTP adapter.
     *
     * @return  StudioHostRequest  Typed envelope containing no authenticated identity.
     *
     * @throws  StudioHostRequestRejected  When the request is outside the pinned closed schema.
     *
     * @since   2.0.0
     */
    public function decode(mixed $document): StudioHostRequest
    {
        if (!$document instanceof stdClass || !$this->schemas->validator('host-request')->validate($document)) {
            throw new StudioHostRequestRejected('The Studio host envelope is invalid.');
        }
        $context = $document->context;
        if (!$context instanceof stdClass) {
            throw new StudioHostRequestRejected('The Studio host context is invalid.');
        }
        assert(is_string($context->operationId));
        assert(is_string($context->protocolVersion));
        assert(is_string($context->requestId));
        assert(is_string($context->resourceContextKey));
        assert(is_string($context->sessionGeneration));
        $expectedRevision = property_exists($context, 'expectedRevision') ? $context->expectedRevision : null;
        $idempotencyKey = property_exists($context, 'idempotencyKey') ? $context->idempotencyKey : null;
        $locale = property_exists($context, 'locale') ? $context->locale : null;
        $traceContext = property_exists($context, 'traceContext') ? $context->traceContext : null;
        assert($expectedRevision === null || is_string($expectedRevision));
        assert($idempotencyKey === null || is_string($idempotencyKey));
        assert($locale === null || is_string($locale));
        assert($traceContext === null || $traceContext instanceof stdClass);

        return new StudioHostRequest(
            $context->operationId,
            $context->protocolVersion,
            $context->requestId,
            $context->resourceContextKey,
            $context->sessionGeneration,
            property_exists($document, 'arguments') ? $document->arguments : null,
            $expectedRevision,
            $idempotencyKey,
            $locale,
            $traceContext,
        );
    }
}
