<?php

declare(strict_types=1);

namespace Kumwe\CMS\OpenApi\Application;

/**
 * Shared memory and publication bounds for generated OpenAPI contracts.
 *
 * The contract byte limit is deliberately below half the private cache envelope limit because JSON
 * envelope encoding may escape every contract byte. The compiler and verified-value object enforce the
 * same limit, so neither an HTTP response nor a cache writer can receive an oversized contract.
 *
 * @since  2.0.0
 */
final class OpenApiContractLimits
{
    /**
     * Maximum definitions in one caller-specific generated contract.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAX_DEFINITIONS = 256;

    /**
     * Maximum encoded metadata bytes accepted before compilation expands shared schemas.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAX_DEFINITION_INPUT_BYTES = 4_000_000;

    /**
     * Maximum canonical contract bytes that may be constructed, cached, or served.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAX_CONTRACT_BYTES = 8_000_000;

    /**
     * Static constants only.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
