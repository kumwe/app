<?php

declare(strict_types=1);

namespace Kumwe\App\OpenApi\Infrastructure;

use Kumwe\App\OpenApi\Application\CompiledOpenApiContract;
use Kumwe\App\OpenApi\Application\OpenApiContractCache;

/**
 * Process-local disposable OpenAPI cache used by web workers and unit tests.
 *
 * @since  2.0.0
 */
final class InMemoryOpenApiContractCache implements OpenApiContractCache
{
    /**
     * Cached contracts keyed by generation.
     *
     * @var    array<string, CompiledOpenApiContract>
     * @since  2.0.0
     */
    private array $contracts = [];

    /**
     * Return a checksum-verified contract for one generation.
     *
     * @param   string  $generation  Deterministic contract generation identity.
     *
     * @return  CompiledOpenApiContract|null  Cached contract, or null when absent or corrupt.
     *
     * @since   2.0.0
     */
    public function get(string $generation): ?CompiledOpenApiContract
    {
        $contract = $this->contracts[$generation] ?? null;
        if ($contract === null || !hash_equals($contract->checksum, hash('sha256', $contract->json))) {
            unset($this->contracts[$generation]);

            return null;
        }

        return $contract;
    }

    /**
     * Cache one compiled contract by its generation identity.
     *
     * @param   CompiledOpenApiContract  $contract  Compiled contract to cache.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function put(CompiledOpenApiContract $contract): void
    {
        $this->contracts[$contract->generation] = $contract;
        if (count($this->contracts) > 16) {
            array_shift($this->contracts);
        }
    }
}
