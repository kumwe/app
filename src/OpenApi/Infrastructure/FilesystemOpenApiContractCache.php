<?php

declare(strict_types=1);

namespace Kumwe\App\OpenApi\Infrastructure;

use JsonException;
use Kumwe\App\OpenApi\Application\CompiledOpenApiContract;
use Kumwe\App\OpenApi\Application\OpenApiContractCache;
use Kumwe\App\OpenApi\Application\OpenApiContractLimits;
use RuntimeException;

/**
 * Atomic checksum-verifying filesystem cache for generated OpenAPI contracts.
 *
 * Each generation is one non-public JSON envelope, so a crash cannot publish a contract without its
 * checksum or vice versa. Reads treat every absence, malformed envelope, digest mismatch and oversized
 * file as a cache miss. Writes use a same-directory exclusive temporary file followed by atomic rename.
 *
 * @since  2.0.0
 */
final readonly class FilesystemOpenApiContractCache implements OpenApiContractCache
{
    /**
     * Maximum cache envelope size admitted from disk.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAX_BYTES = 16_777_216;

    /**
     * Configure the private cache directory.
     *
     * @param   string  $directory  Absolute non-public directory dedicated to OpenAPI cache entries.
     *
     * @throws  RuntimeException  When the directory cannot be created or is not writable.
     *
     * @since   2.0.0
     */
    public function __construct(private string $directory)
    {
        if (!str_starts_with($directory, '/') || str_contains($directory, "\0")) {
            throw new RuntimeException('The OpenAPI cache directory must be an absolute path.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The OpenAPI cache directory cannot be created.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('The OpenAPI cache directory is not writable.');
        }
    }

    /**
     * Read and prove one exact generation envelope.
     *
     * @param   string  $generation  Trusted generation digest.
     *
     * @return  CompiledOpenApiContract|null  Verified entry, or null for absence or corruption.
     *
     * @since   2.0.0
     */
    public function get(string $generation): ?CompiledOpenApiContract
    {
        if (!$this->digest($generation)) {
            return null;
        }
        $path = $this->path($generation);
        $size = is_file($path) ? filesize($path) : false;
        if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES || is_link($path)) {
            return null;
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            return null;
        }
        try {
            $envelope = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($envelope) || array_is_list($envelope)
                || array_keys($envelope) !== ['generation', 'checksum', 'contract']
                || $envelope['generation'] !== $generation
                || !is_string($envelope['checksum'])
                || !is_string($envelope['contract'])
                || strlen($envelope['contract']) > OpenApiContractLimits::MAX_CONTRACT_BYTES
            ) {
                return null;
            }

            return new CompiledOpenApiContract(
                $generation,
                $envelope['checksum'],
                $envelope['contract'],
            );
        } catch (JsonException | \InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Atomically publish one already verified contract envelope.
     *
     * @param   CompiledOpenApiContract  $contract  Verified canonical contract.
     *
     * @return  void
     *
     * @throws  RuntimeException  When encoding or atomic publication fails.
     *
     * @since   2.0.0
     */
    public function put(CompiledOpenApiContract $contract): void
    {
        try {
            $bytes = json_encode([
                'generation' => $contract->generation,
                'checksum' => $contract->checksum,
                'contract' => $contract->json,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('The OpenAPI cache envelope cannot be encoded.', 0, $exception);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('The OpenAPI cache envelope exceeds its safe bound.');
        }
        $temporary = tempnam($this->directory, '.openapi-');
        if (!is_string($temporary)) {
            throw new RuntimeException('The OpenAPI cache temporary file cannot be created.');
        }
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('The OpenAPI cache entry cannot be written completely.');
            }
            if (!chmod($temporary, 0600) || !rename($temporary, $this->path($contract->generation))) {
                throw new RuntimeException('The OpenAPI cache entry cannot be published atomically.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Resolve a digest-only cache path.
     *
     * @param   string  $generation  Valid generation digest.
     *
     * @return  string  Absolute envelope path.
     *
     * @since   2.0.0
     */
    private function path(string $generation): string
    {
        return $this->directory . '/' . $generation . '.json';
    }

    /**
     * Check the closed cache key grammar.
     *
     * @param   string  $value  Candidate digest.
     *
     * @return  bool  True only for a lowercase SHA-256 spelling.
     *
     * @since   2.0.0
     */
    private function digest(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
