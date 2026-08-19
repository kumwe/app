<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\OpenApi\Infrastructure;

use Kumwe\App\OpenApi\Application\CompiledOpenApiContract;
use Kumwe\App\OpenApi\Application\OpenApiContractLimits;
use Kumwe\App\OpenApi\Infrastructure\FilesystemOpenApiContractCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilesystemOpenApiContractCache::class)]
/**
 * Proves filesystem OpenAPI caching verifies identities and checksums before reuse.
 *
 * @since  2.0.0
 */
final class FilesystemOpenApiContractCacheTest extends TestCase
{
    /**
     * Isolated cache directory created for the current test.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $directory;

    /**
     * Allocate an unpredictable cache directory outside the repository.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kumwe-openapi-' . bin2hex(random_bytes(8));
    }

    /**
     * Remove every cache artifact created by the current test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /**
     * Proves only a checksum-verified contract is returned for its exact generation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRoundTripsOnlyChecksumVerifiedGeneration(): void
    {
        $cache = new FilesystemOpenApiContractCache($this->directory);
        $generation = str_repeat('a', 64);
        $json = sprintf(
            '{"openapi":"3.1.0","x-kumwe-business-generation":"%s"}',
            $generation,
        );
        $contract = new CompiledOpenApiContract($generation, hash('sha256', $json), $json);

        self::assertNull($cache->get($contract->generation));
        $cache->put($contract);

        self::assertEquals($contract, $cache->get($contract->generation));
        self::assertSame([], glob($this->directory . '/.openapi-*') ?: []);
        self::assertNull($cache->get('../outside'));
    }

    /**
     * Proves a malformed persisted envelope is treated as a cache miss.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCorruptEnvelopeFailsClosed(): void
    {
        $cache = new FilesystemOpenApiContractCache($this->directory);
        $generation = str_repeat('b', 64);
        file_put_contents($this->directory . '/' . $generation . '.json', '{"generation":"wrong"}');

        self::assertNull($cache->get($generation));
    }

    /**
     * Never substitute an older verified generation when the requested generation is corrupt.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCorruptCurrentGenerationNeverFallsBackToOlderEntry(): void
    {
        $cache = new FilesystemOpenApiContractCache($this->directory);
        $olderGeneration = str_repeat('c', 64);
        $json = sprintf(
            '{"openapi":"3.1.0","x-kumwe-business-generation":"%s"}',
            $olderGeneration,
        );
        $older = new CompiledOpenApiContract($olderGeneration, hash('sha256', $json), $json);
        $cache->put($older);
        $currentGeneration = str_repeat('d', 64);
        file_put_contents(
            $this->directory . '/' . $currentGeneration . '.json',
            json_encode([
                'generation' => $currentGeneration,
                'checksum' => hash('sha256', $json),
                'contract' => $json,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertNull($cache->get($currentGeneration));
        self::assertEquals($older, $cache->get($older->generation));
    }

    /**
     * Prevent an oversized verified value from reaching cache publication.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOversizedContractCannotReachCachePublication(): void
    {
        $cache = new FilesystemOpenApiContractCache($this->directory);
        $json = str_repeat('x', OpenApiContractLimits::MAX_CONTRACT_BYTES + 1);

        try {
            $cache->put(new CompiledOpenApiContract(str_repeat('e', 64), hash('sha256', $json), $json));
            self::fail('An oversized contract reached cache publication.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('A compiled OpenAPI contract is invalid.', $exception->getMessage());
        }

        self::assertSame([], glob($this->directory . '/*') ?: []);
    }
}
