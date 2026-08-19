<?php

declare(strict_types=1);

namespace Kumwe\App\OpenApi\Application;

use InvalidArgumentException;
use JsonException;

/**
 * Immutable verified OpenAPI bytes and the generation evidence they were compiled from.
 *
 * @since  2.0.0
 */
final readonly class CompiledOpenApiContract
{
    /**
     * Capture one canonical OpenAPI contract.
     *
     * @param   string  $generation  SHA-256 binding trusted runtime, definitions and filtered metadata.
     * @param   string  $checksum    SHA-256 over the exact JSON bytes.
     * @param   string  $json        Canonical OpenAPI 3.1 JSON.
     *
     * @throws  InvalidArgumentException  When digests or JSON bytes are invalid.
     *
     * @since   2.0.0
     */
    public function __construct(public string $generation, public string $checksum, public string $json)
    {
        $valid = preg_match('/^[a-f0-9]{64}$/D', $generation) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $checksum) === 1
            && $json !== ''
            && strlen($json) <= OpenApiContractLimits::MAX_CONTRACT_BYTES
            && hash_equals($checksum, hash('sha256', $json));
        try {
            $document = $valid ? json_decode($json, true, 128, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $document = null;
        }
        if (
            !is_array($document)
            || array_is_list($document)
            || ($document['openapi'] ?? null) !== '3.1.0'
            || ($document['x-kumwe-business-generation'] ?? null) !== $generation
        ) {
            throw new InvalidArgumentException('A compiled OpenAPI contract is invalid.');
        }
    }
}
