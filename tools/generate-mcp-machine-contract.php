<?php

/**
 * Generate or verify the retained MCP v1 machine-contract fixture.
 *
 * Usage:
 *   php tools/generate-mcp-machine-contract.php --check
 *   php tools/generate-mcp-machine-contract.php --write
 *
 * `--check` is the CI mode: it builds the contract from the same catalogue the live server registers and
 * compares exact bytes. `--write` can establish a missing generation or confirm identical bytes, but it never
 * replaces a retained artifact. A compatibility change requires a successor generation identifier and path.
 * Neither mode needs a database or application kernel.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Infrastructure\Mcp\McpMachineContract;
use Kumwe\App\Tools\RetainedMachineContractWriter;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Install Composer dependencies before generating the MCP machine contract.\n");
    exit(2);
}
require $autoload;
require $root . '/tools/RetainedMachineContractWriter.php';

$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments)) {
    $arguments = [];
}
$mode = $arguments[1] ?? null;
if (!in_array($mode, ['--check', '--write'], true) || count($arguments) !== 2) {
    fwrite(STDERR, "Usage: php tools/generate-mcp-machine-contract.php --check|--write\n");
    exit(64);
}

$artifact = 'docs/machine-contract/' . McpMachineContract::GENERATION . '.json';
$path = $root . '/' . $artifact;
$contract = new McpMachineContract(new McpCapabilityCatalog());
$expected = McpMachineContract::prettyJson($contract->document());

if ($mode === '--write') {
    try {
        $created = RetainedMachineContractWriter::establish($path, $expected);
    } catch (\LogicException) {
        fwrite(
            STDERR,
            sprintf(
                "Refusing to overwrite retained MCP generation %s with different bytes. "
                . "Create a successor generation identifier and artifact while preserving %s.\n",
                McpMachineContract::GENERATION,
                McpMachineContract::GENERATION,
            ),
        );
        exit(1);
    } catch (\RuntimeException $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(1);
    }

    fwrite(
        STDOUT,
        $created
            ? sprintf("Established %s.\n", $artifact)
            : sprintf("%s already contains the retained bytes.\n", $artifact),
    );
    exit(0);
}

$actual = is_file($path) ? file_get_contents($path) : false;
if (!is_string($actual) || !hash_equals($expected, $actual)) {
    fwrite(
        STDERR,
        sprintf("%s differs from the live retained MCP generation. ", $artifact)
        . "Review the compatibility change, then run this tool with --write.\n",
    );
    exit(1);
}

fwrite(STDOUT, "MCP machine contract matches the live retained generation.\n");
