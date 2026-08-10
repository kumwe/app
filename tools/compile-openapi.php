#!/usr/bin/env php
<?php

/**
 * Deterministically compile or verify the checked-in OpenAPI contract.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\CMS\OpenApi\Application\OpenApiContractCompiler;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer dependencies are required to compile OpenAPI.\n");
    exit(78);
}
require $autoload;

$arguments = array_slice($argv, 1);
$check = $arguments === ['--check'];
if ($arguments !== [] && !$check) {
    fwrite(STDERR, "Usage: php tools/compile-openapi.php [--check]\n");
    exit(64);
}

$path = $root . '/api/openapi/kumwe-v1.json';
$encoded = file_get_contents($path);
if (!is_string($encoded)) {
    fwrite(STDERR, "The checked-in OpenAPI contract cannot be read.\n");
    exit(66);
}

try {
    $core = json_decode($encoded, true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($core) || array_is_list($core)) {
        throw new RuntimeException('The checked-in OpenAPI contract is not an object.');
    }
    $generation = hash('sha256', 'kumwe-openapi-golden-v1');
    $compiled = (new OpenApiContractCompiler())->compile($core, [], $generation);
    $expected = $compiled->json;
} catch (Throwable $exception) {
    fwrite(STDERR, "OpenAPI compilation failed: " . $exception->getMessage() . "\n");
    exit(65);
}

if ($check) {
    if (!hash_equals($expected, $encoded)) {
        fwrite(STDERR, "The generated OpenAPI artifact is stale; run composer openapi:compile.\n");
        exit(1);
    }
    fwrite(STDOUT, "The generated OpenAPI artifact is current.\n");
    exit(0);
}

$temporary = tempnam(dirname($path), '.kumwe-openapi-');
if (!is_string($temporary)) {
    fwrite(STDERR, "The OpenAPI temporary file cannot be created.\n");
    exit(73);
}
try {
    if (file_put_contents($temporary, $expected, LOCK_EX) !== strlen($expected) || !rename($temporary, $path)) {
        fwrite(STDERR, "The generated OpenAPI artifact cannot be published atomically.\n");
        exit(73);
    }
} finally {
    if (is_file($temporary)) {
        unlink($temporary);
    }
}

fwrite(STDOUT, sprintf("Generated %s (%s).\n", $path, $compiled->checksum));
