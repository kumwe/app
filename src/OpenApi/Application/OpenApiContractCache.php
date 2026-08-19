<?php

declare(strict_types=1);

namespace Kumwe\App\OpenApi\Application;

/**
 * Disposable verified cache for deterministic OpenAPI contracts.
 *
 * @since  2.0.0
 */
interface OpenApiContractCache
{
    /**
     * Read a contract compiled for an exact generation.
     *
     * @param   string  $generation  Trusted generation digest.
     *
     * @return  CompiledOpenApiContract|null  Verified cache entry, or null on any absence or corruption.
     *
     * @since   2.0.0
     */
    public function get(string $generation): ?CompiledOpenApiContract;

    /**
     * Store a verified canonical contract under its generation.
     *
     * @param   CompiledOpenApiContract  $contract  Contract to cache.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function put(CompiledOpenApiContract $contract): void;
}
