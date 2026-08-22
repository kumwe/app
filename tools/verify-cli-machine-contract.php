<?php

declare(strict_types=1);

use Kumwe\App\Delivery\Console\Contract\CliV1MachineContract;
use Kumwe\App\Tools\RetainedMachineContractWriter;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tools/RetainedMachineContractWriter.php';

$root = dirname(__DIR__);
$documentation = $root . '/docs/machine-contract/cli-v1.json';
$processArguments = $_SERVER['argv'] ?? [];
if (!is_array($processArguments)) {
    $processArguments = [];
}
$arguments = [];
foreach (array_slice($processArguments, 1) as $argument) {
    if (!is_string($argument)) {
        fwrite(STDERR, "CLI verifier arguments must be strings.\n");
        exit(64);
    }
    $arguments[] = $argument;
}
$write = $arguments === ['--write'];
if ($arguments !== [] && !$write) {
    fwrite(STDERR, "Usage: php tools/verify-cli-machine-contract.php [--write]\n");
    exit(64);
}

try {
    $authoritative = CliV1MachineContract::json();
    $contract = CliV1MachineContract::contract();
    if (!str_ends_with($authoritative, "\n") || str_ends_with($authoritative, "\n\n")) {
        throw new RuntimeException('The authoritative CLI contract must end in exactly one line feed.');
    }
    if ($contract->generation() !== 1) {
        throw new RuntimeException('The v1 CLI contract must retain compatibility generation 1.');
    }
    if (count($contract->commandNames()) !== 44) {
        throw new RuntimeException('The v1 CLI contract must declare all 44 live commands.');
    }

    if ($write) {
        try {
            $created = RetainedMachineContractWriter::establish($documentation, $authoritative);
        } catch (LogicException) {
            throw new RuntimeException(
                'Refusing to overwrite retained CLI generation v1 with different bytes. '
                . 'Preserve v1 and publish the compatibility change under a successor generation.',
            );
        }
        fwrite(
            STDOUT,
            $created
                ? "Established docs/machine-contract/cli-v1.json.\n"
                : "docs/machine-contract/cli-v1.json already contains the retained bytes.\n",
        );
    }

    $documented = file_get_contents($documentation);
    if (!is_string($documented) || !hash_equals(hash('sha256', $authoritative), hash('sha256', $documented))) {
        throw new RuntimeException(
            'docs/machine-contract/cli-v1.json differs from retained generation v1. Restore its exact bytes; '
            . 'an intentional compatibility change requires a successor generation, not an overwrite.',
        );
    }

    fwrite(STDOUT, sprintf(
        "CLI machine contract generation %d verified: %d commands, %s.\n",
        $contract->generation(),
        count($contract->commandNames()),
        $contract->digest(),
    ));
    exit(0);
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}
