#!/usr/bin/env php
<?php

/**
 * Deterministically compile or verify the checked-in public REST machine contract.
 *
 * A successor generation must first name a new independent core input. Its compiled artifact,
 * problem registry, and compatibility fixture are then retained under generation-owned paths.
 * Retained paths are never aliases and are never republished for a later generation.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\OpenApi\Application\OpenApiContractCompiler;
use Kumwe\App\OpenApi\Application\ProblemDetailsRegistry;
use Kumwe\App\OpenApi\Infrastructure\CanonicalOpenApiJson;
use Kumwe\App\OpenApi\Infrastructure\RestMachineContractGenerationLedger;
use Kumwe\App\OpenApi\Infrastructure\RestMachineContractSnapshot;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer dependencies are required to compile OpenAPI.\n");
    exit(78);
}
require $autoload;

$arguments = array_slice($argv, 1);
$check = $arguments === ['--check'];
$acceptGeneration = $arguments === ['--accept-generation'];
if ($arguments !== [] && !$check && !$acceptGeneration) {
    fwrite(STDERR, "Usage: php tools/compile-openapi.php [--check|--accept-generation]\n");
    exit(64);
}

// Bump this only with a reviewed successor core input at the derived generation-owned path below.
$contractGeneration = '1.0.0';

/**
 * Resolve immutable retained paths for one REST contract generation.
 *
 * @param   string  $generation  Normalized REST contract generation.
 *
 * @return  array{core: string, artifact: string, problem_registry: string, compatibility_fixture: string}
 *
 * @since   2.0.0
 */
$generationPaths = static function (string $generation): array {
    if ($generation === '1.0.0') {
        return [
            'core' => 'api/openapi/core-v1.json',
            'artifact' => 'api/openapi/kumwe-v1.json',
            'problem_registry' => 'api/problem-details/kumwe-v1.json',
            'compatibility_fixture' => 'api/openapi/compatibility/v1.json',
        ];
    }
    $generationRoot = 'api/openapi/generations/' . $generation;

    return [
        'core' => $generationRoot . '/core.json',
        'artifact' => $generationRoot . '/openapi.json',
        'problem_registry' => 'api/problem-details/generations/' . $generation . '.json',
        'compatibility_fixture' => $generationRoot . '/compatibility.json',
    ];
};
$retainedPaths = $generationPaths($contractGeneration);
$coreRelativePath = $retainedPaths['core'];
$artifactRelativePath = $retainedPaths['artifact'];
$problemRelativePath = $retainedPaths['problem_registry'];
$fixtureRelativePath = $retainedPaths['compatibility_fixture'];
$corePath = $root . '/' . $coreRelativePath;
$artifactPath = $root . '/' . $artifactRelativePath;
$problemPath = $root . '/' . $problemRelativePath;
$fixturePath = $root . '/' . $fixtureRelativePath;
$generationsPath = $root . '/api/openapi/generations.json';
$routeExclusionsPath = $root . '/api/openapi/route-exclusions.json';

/**
 * Read and decode one canonical JSON object.
 *
 * @param   string  $path   File to read.
 * @param   string  $label  Stable error label.
 *
 * @return  array{array<string, mixed>, string}  Decoded object and exact bytes.
 *
 * @since   2.0.0
 */
$readObject = static function (string $path, string $label): array {
    $encoded = file_get_contents($path);
    if (!is_string($encoded)) {
        throw new RuntimeException(sprintf('The checked-in %s cannot be read.', $label));
    }
    $document = json_decode($encoded, true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($document) || array_is_list($document)) {
        throw new RuntimeException(sprintf('The checked-in %s is not a JSON object.', $label));
    }

    /** @var array<string, mixed> $document */
    return [$document, $encoded];
};

/**
 * Publish exact bytes atomically beside their destination.
 *
 * @param   string  $path   Destination path.
 * @param   string  $bytes  Complete canonical bytes.
 *
 * @return  void
 *
 * @since   2.0.0
 */
$publish = static function (string $path, string $bytes): void {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The REST contract directory cannot be created.');
    }
    $temporary = tempnam($directory, '.kumwe-rest-contract-');
    if (!is_string($temporary)) {
        throw new RuntimeException('A REST contract temporary file cannot be created.');
    }
    try {
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $path)) {
            throw new RuntimeException('A REST contract artifact cannot be published atomically.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
};

try {
    if (!is_file($corePath)) {
        throw new RuntimeException(sprintf(
            'Create the independent successor core input at %s before compiling generation %s.',
            $coreRelativePath,
            $contractGeneration,
        ));
    }
    [$core, $coreBytes] = $readObject($corePath, 'independent core OpenAPI input');
    [$routeExclusions, $routeExclusionsBytes] = $readObject(
        $routeExclusionsPath,
        'OpenAPI live-route exclusion allowlist',
    );
    [$retainedGenerations] = $readObject($generationsPath, 'REST generation ledger');
    if (
        isset($core['x-kumwe-business-generation'])
        || isset($core['x-kumwe-generated-components'])
        || isset($core['x-kumwe-generated-paths'])
    ) {
        throw new RuntimeException('The independent core OpenAPI input contains generated output markers.');
    }
    if (!hash_equals(CanonicalOpenApiJson::encode($core), $coreBytes)) {
        throw new RuntimeException('The independent core OpenAPI input is not canonical.');
    }
    if (
        ($routeExclusions['format'] ?? null) !== 'kumwe-openapi-route-exclusions-v1'
        || !hash_equals(CanonicalOpenApiJson::encode($routeExclusions), $routeExclusionsBytes)
    ) {
        throw new RuntimeException('The OpenAPI live-route exclusion allowlist is invalid or not canonical.');
    }
    $problemRegistry = new ProblemDetailsRegistry();
    $problemDocument = $problemRegistry->document();
    $problemBytes = CanonicalOpenApiJson::encode($problemDocument);
    $components = $core['components'] ?? null;
    $schemas = is_array($components) && !array_is_list($components)
        ? ($components['schemas'] ?? null)
        : null;
    if (!is_array($schemas) || array_is_list($schemas)) {
        throw new RuntimeException('The independent core OpenAPI schema registry is invalid.');
    }
    $schemas['ProblemDetails'] = $problemRegistry->openApiSchema();
    $components['schemas'] = $schemas;
    $core['components'] = $components;
    $generation = hash('sha256', $coreBytes . $problemBytes);
    $compiled = (new OpenApiContractCompiler())->compile($core, [], $generation);
    $artifactBytes = $compiled->json;
    /** @var array<string, mixed> $artifactDocument */
    $artifactDocument = json_decode($artifactBytes, true, 128, JSON_THROW_ON_ERROR);
    $fixture = RestMachineContractSnapshot::create(
        $artifactDocument,
        $problemDocument,
        $contractGeneration,
    );
    $fixtureBytes = CanonicalOpenApiJson::encode($fixture);
    $candidateGeneration = [
        'generation' => $contractGeneration,
        'core' => $coreRelativePath,
        'artifact' => $artifactRelativePath,
        'problem_registry' => $problemRelativePath,
        'route_exclusions' => 'api/openapi/route-exclusions.json',
        'compatibility_fixture' => $fixtureRelativePath,
        'compiler_generation_sha256' => $generation,
        'core_sha256' => hash('sha256', $coreBytes),
        'artifact_sha256' => hash('sha256', $artifactBytes),
        'problem_registry_sha256' => hash('sha256', $problemBytes),
        'route_exclusions_sha256' => hash('sha256', $routeExclusionsBytes),
        'compatibility_fixture_sha256' => hash('sha256', $fixtureBytes),
    ];
    $alreadyRetained = false;
    $retainedRows = $retainedGenerations['generations'] ?? [];
    if (is_array($retainedRows)) {
        foreach ($retainedRows as $retainedRow) {
            if (is_array($retainedRow) && ($retainedRow['generation'] ?? null) === $contractGeneration) {
                $alreadyRetained = true;
                break;
            }
        }
    }
    $generations = RestMachineContractGenerationLedger::retain(
        $retainedGenerations,
        $candidateGeneration,
    );
    $generationsBytes = CanonicalOpenApiJson::encode($generations);
} catch (Throwable $exception) {
    fwrite(STDERR, "REST machine-contract compilation failed: " . $exception->getMessage() . "\n");
    exit(65);
}

if ($check) {
    try {
        [, $currentArtifact] = $readObject($artifactPath, 'compiled OpenAPI artifact');
        [, $currentProblems] = $readObject($problemPath, 'problem-details registry');
        [, $currentFixture] = $readObject($fixturePath, 'REST compatibility fixture');
        [, $currentGenerations] = $readObject($generationsPath, 'REST generation ledger');
    } catch (Throwable $exception) {
        fwrite(STDERR, "REST machine-contract verification failed: " . $exception->getMessage() . "\n");
        exit(66);
    }
    $stale = [];
    foreach (
        [
            'compiled OpenAPI artifact' => [$artifactBytes, $currentArtifact],
            'problem-details registry' => [$problemBytes, $currentProblems],
            'REST compatibility fixture' => [$fixtureBytes, $currentFixture],
            'REST generation ledger' => [$generationsBytes, $currentGenerations],
        ] as $label => [$expected, $current]
    ) {
        if (!hash_equals($expected, $current)) {
            $stale[] = $label;
        }
    }
    if ($stale !== []) {
        fwrite(STDERR, sprintf(
            "The %s %s stale; review compatibility and run composer openapi:accept-generation.\n",
            implode(', ', $stale),
            count($stale) === 1 ? 'is' : 'are',
        ));
        exit(1);
    }
    fwrite(STDOUT, "The REST machine-contract generation is current and compatibility-pinned.\n");
    exit(0);
}

if (!$alreadyRetained && !$acceptGeneration) {
    fwrite(
        STDERR,
        "A successor REST generation requires unique retained paths and --accept-generation.\n",
    );
    exit(65);
}

try {
    $publish($artifactPath, $artifactBytes);
    if ($acceptGeneration) {
        $publish($problemPath, $problemBytes);
        $publish($fixturePath, $fixtureBytes);
        $publish($generationsPath, $generationsBytes);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "REST machine-contract publication failed: " . $exception->getMessage() . "\n");
    exit(73);
}

fwrite(STDOUT, sprintf(
    "Generated %s (%s)%s.\n",
    $artifactPath,
    $compiled->checksum,
    $acceptGeneration ? ' and accepted REST generation ' . $contractGeneration : '',
));
