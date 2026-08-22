<?php

declare(strict_types=1);

namespace Kumwe\App\OpenApi\Infrastructure;

use InvalidArgumentException;

/**
 * Appends REST contract generations while making every retained row immutable.
 *
 * @since  2.0.0
 */
final class RestMachineContractGenerationLedger
{
    /**
     * Exact keys carried by every retained generation row.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const ROW_KEYS = [
        'artifact',
        'artifact_sha256',
        'compatibility_fixture',
        'compatibility_fixture_sha256',
        'compiler_generation_sha256',
        'core',
        'core_sha256',
        'generation',
        'problem_registry',
        'problem_registry_sha256',
        'route_exclusions',
        'route_exclusions_sha256',
    ];

    /**
     * Retained files that must never be shared by two generations.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const GENERATION_PATH_KEYS = [
        'artifact',
        'compatibility_fixture',
        'core',
        'problem_registry',
    ];

    /**
     * SHA-256 fields required on every generation row.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const DIGEST_KEYS = [
        'artifact_sha256',
        'compatibility_fixture_sha256',
        'compiler_generation_sha256',
        'core_sha256',
        'problem_registry_sha256',
        'route_exclusions_sha256',
    ];

    /**
     * Retain an identical generation or append a successor without rewriting history.
     *
     * @param   array<string, mixed>  $ledger     Existing retained generation ledger.
     * @param   array<string, mixed>  $candidate  Fully digested candidate generation row.
     *
     * @return  array<string, mixed>  Existing ledger or one carrying the appended successor.
     *
     * @throws  InvalidArgumentException  When the ledger is malformed or a retained name changes bytes.
     *
     * @since   2.0.0
     */
    public static function retain(array $ledger, array $candidate): array
    {
        $rows = self::validateLedger($ledger);
        self::validateRow($candidate);
        $generation = $candidate['generation'];
        if (!is_string($generation)) {
            throw new InvalidArgumentException('The candidate REST generation name is invalid.');
        }
        foreach ($rows as $row) {
            if ($row['generation'] !== $generation) {
                continue;
            }
            if (self::normalize($row) !== self::normalize($candidate)) {
                throw new InvalidArgumentException(sprintf(
                    'REST generation %s is immutable; publish changed bytes under a successor generation.',
                    $generation,
                ));
            }

            return $ledger;
        }

        $current = $ledger['current'];
        if (!is_string($current) || version_compare($generation, $current, '<=')) {
            throw new InvalidArgumentException(sprintf(
                'REST generation %s is not a successor to retained generation %s.',
                $generation,
                is_string($current) ? $current : '(invalid)',
            ));
        }

        $rows[] = $candidate;
        $ledger['current'] = $generation;
        $ledger['generations'] = $rows;
        self::validateLedger($ledger);

        return $ledger;
    }

    /**
     * Validate the ledger envelope, generation ordering, paths, and shared allowlists.
     *
     * @param   array<string, mixed>  $ledger  Retained generation ledger.
     *
     * @return  list<array<string, mixed>>  Validated generation rows.
     *
     * @throws  InvalidArgumentException  When any retained ledger invariant is broken.
     *
     * @since   2.0.0
     */
    private static function validateLedger(array $ledger): array
    {
        if (self::sortedKeys($ledger) !== ['current', 'format', 'generations']) {
            throw new InvalidArgumentException('The retained REST generation ledger has an invalid shape.');
        }
        $current = $ledger['current'];
        $rows = $ledger['generations'];
        if (
            $ledger['format'] !== 'kumwe-rest-machine-contract-generations-v1'
            || !is_string($current)
            || !self::isGeneration($current)
            || !is_array($rows)
            || !array_is_list($rows)
            || $rows === []
        ) {
            throw new InvalidArgumentException('The retained REST generation ledger is invalid.');
        }

        /** @var list<array<string, mixed>> $validatedRows */
        $validatedRows = [];
        /** @var array<string, true> $generationPaths */
        $generationPaths = [];
        /** @var array<string, string> $routeExclusionDigests */
        $routeExclusionDigests = [];
        $priorGeneration = null;
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('A retained REST generation row is invalid.');
            }
            /** @var array<string, mixed> $row */
            self::validateRow($row);
            $generation = $row['generation'];
            if (
                !is_string($generation)
                || ($priorGeneration !== null && version_compare($generation, $priorGeneration, '<='))
            ) {
                throw new InvalidArgumentException('Retained REST generations are not strictly ordered.');
            }
            foreach (self::GENERATION_PATH_KEYS as $pathKey) {
                $path = $row[$pathKey];
                if (!is_string($path) || isset($generationPaths[$path])) {
                    throw new InvalidArgumentException(sprintf(
                        'Retained REST generation path %s must be unique across all generations.',
                        is_string($path) ? $path : '(invalid)',
                    ));
                }
                if (isset($routeExclusionDigests[$path])) {
                    throw new InvalidArgumentException(
                        'A retained REST generation file collides with a route allowlist.',
                    );
                }
                $generationPaths[$path] = true;
            }
            $routePath = $row['route_exclusions'];
            $routeDigest = $row['route_exclusions_sha256'];
            if (!is_string($routePath) || !is_string($routeDigest) || isset($generationPaths[$routePath])) {
                throw new InvalidArgumentException('A retained REST route-exclusion path is invalid.');
            }
            $priorRouteDigest = $routeExclusionDigests[$routePath] ?? null;
            if ($priorRouteDigest !== null && !hash_equals($priorRouteDigest, $routeDigest)) {
                throw new InvalidArgumentException(sprintf(
                    'Shared REST route-exclusion file %s changed bytes across generations.',
                    $routePath,
                ));
            }
            $routeExclusionDigests[$routePath] = $routeDigest;
            $validatedRows[] = $row;
            $priorGeneration = $generation;
        }
        if ($priorGeneration !== $current) {
            throw new InvalidArgumentException('The current REST generation is not the final retained generation.');
        }

        return $validatedRows;
    }

    /**
     * Validate the exact fields, paths, and digests of one retained row.
     *
     * @param   array<string, mixed>  $row  Candidate or retained generation row.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a required machine-contract field is invalid.
     *
     * @since   2.0.0
     */
    private static function validateRow(array $row): void
    {
        if (self::sortedKeys($row) !== self::ROW_KEYS || !self::isGeneration($row['generation'])) {
            throw new InvalidArgumentException('A retained REST generation row has an invalid shape.');
        }
        foreach (self::GENERATION_PATH_KEYS as $pathKey) {
            self::validatePath($pathKey, $row[$pathKey]);
        }
        self::validatePath('route_exclusions', $row['route_exclusions']);
        foreach (self::DIGEST_KEYS as $digestKey) {
            $digest = $row[$digestKey];
            if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'REST generation digest %s must be a lowercase SHA-256 value.',
                    $digestKey,
                ));
            }
        }
    }

    /**
     * Validate one safe repository-relative retained JSON path.
     *
     * @param   string  $field  Retained row path field.
     * @param   mixed   $path   Candidate path value.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the path could escape or alias its retained location.
     *
     * @since   2.0.0
     */
    private static function validatePath(string $field, mixed $path): void
    {
        $prefix = $field === 'problem_registry' ? 'api/problem-details/' : 'api/openapi/';
        if (
            !is_string($path)
            || strlen($path) > 255
            || !str_starts_with($path, $prefix)
            || !str_ends_with($path, '.json')
            || preg_match('/^[A-Za-z0-9._\/-]+$/D', $path) !== 1
        ) {
            throw new InvalidArgumentException(sprintf('REST generation path %s is invalid.', $field));
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException(sprintf('REST generation path %s is unsafe.', $field));
            }
        }
    }

    /**
     * Determine whether a value is a normalized three-part generation name.
     *
     * @param   mixed  $generation  Candidate generation value.
     *
     * @return  bool  True for a normalized non-zero-major semantic version.
     *
     * @since   2.0.0
     */
    private static function isGeneration(mixed $generation): bool
    {
        return is_string($generation)
            && preg_match('/^[1-9][0-9]*\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $generation) === 1;
    }

    /**
     * Return object keys in canonical comparison order.
     *
     * @param   array<string, mixed>  $value  JSON object.
     *
     * @return  list<string>  Sorted string keys.
     *
     * @since   2.0.0
     */
    private static function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * Recursively sort object keys before comparing retained row semantics.
     *
     * @param   mixed  $value  JSON-compatible ledger value.
     *
     * @return  mixed  Value with object keys in canonical order.
     *
     * @since   2.0.0
     */
    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $member) {
            $value[$key] = self::normalize($member);
        }

        return $value;
    }

    /**
     * Prevent construction; generation retention is stateless.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
