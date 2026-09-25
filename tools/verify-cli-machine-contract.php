<?php

/**
 * Verify, establish or re-digest the CLI machine-contract generations and their documentation mirrors.
 *
 * Usage:
 *   php tools/verify-cli-machine-contract.php            verify both generations and their mirrors
 *   php tools/verify-cli-machine-contract.php --write    establish a missing mirror or confirm identical bytes
 *   php tools/verify-cli-machine-contract.php --rehash-successor
 *
 * Generation one is retained and immutable: its deployed artifact and mirror keep their exact bytes and all
 * 44 commands. Generation two is generation one plus later commands, and every generation-one command must
 * appear in it unchanged. While generation two is unreleased, `--rehash-successor` recomputes its surface
 * digest after a reviewed command was added to `src/Delivery/Console/Contract/cli-v2.json` and republishes
 * the mirror, so the successor stays regenerable by this tool rather than by hand.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Delivery\Console\Contract\CliMachineContract;
use Kumwe\App\Delivery\Console\Contract\CliV1MachineContract;
use Kumwe\App\Delivery\Console\Contract\CliV2MachineContract;
use Kumwe\App\Tools\RetainedMachineContractWriter;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/tools/RetainedMachineContractWriter.php';

$root = dirname(__DIR__);
$mirrors = [
    'v1' => $root . '/docs/machine-contract/cli-v1.json',
    'v2' => $root . '/docs/machine-contract/cli-v2.json',
];
$successorSource = $root . '/src/Delivery/Console/Contract/cli-v2.json';
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
$rehash = $arguments === ['--rehash-successor'];
if ($arguments !== [] && !$write && !$rehash) {
    fwrite(STDERR, "Usage: php tools/verify-cli-machine-contract.php [--write|--rehash-successor]\n");
    exit(64);
}

try {
    if ($rehash) {
        $json = file_get_contents($successorSource);
        if (!is_string($json)) {
            throw new RuntimeException('The successor CLI contract cannot be read.');
        }
        $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['commands'] ?? null)) {
            throw new RuntimeException('The successor CLI contract has no command list.');
        }
        $digest = CliMachineContract::surfaceDigest(array_values($decoded['commands']));
        $rehashed = preg_replace(
            '/"surface_digest": "sha256:[a-f0-9]{64}"/',
            '"surface_digest": "' . $digest . '"',
            $json,
            1,
        );
        if (!is_string($rehashed)) {
            throw new RuntimeException('The successor CLI contract digest could not be replaced.');
        }
        CliMachineContract::fromJson($rehashed);
        if (
            file_put_contents($successorSource, $rehashed) !== strlen($rehashed)
            || file_put_contents($mirrors['v2'], $rehashed) !== strlen($rehashed)
        ) {
            throw new RuntimeException('The successor CLI contract or its mirror could not be written.');
        }
        fwrite(STDOUT, sprintf("Re-digested CLI generation 2 as %s.\n", $digest));
        exit(0);
    }

    $retained = CliV1MachineContract::json();
    $contract = CliV1MachineContract::contract();
    if (!str_ends_with($retained, "\n") || str_ends_with($retained, "\n\n")) {
        throw new RuntimeException('The authoritative CLI contract must end in exactly one line feed.');
    }
    if ($contract->generation() !== 1) {
        throw new RuntimeException('The v1 CLI contract must retain compatibility generation 1.');
    }
    if (count($contract->commandNames()) !== 44) {
        throw new RuntimeException('The v1 CLI contract must declare all 44 live commands.');
    }
    $current = CliV2MachineContract::json();
    $successor = CliV2MachineContract::contract();
    if ($successor->generation() !== 2) {
        throw new RuntimeException('The successor CLI contract must be generation 2.');
    }
    $retainedDocument = json_decode($retained, true, 128, JSON_THROW_ON_ERROR);
    $currentDocument = json_decode($current, true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($retainedDocument) || !is_array($currentDocument)) {
        throw new RuntimeException('A CLI contract generation is not a JSON object.');
    }
    $currentByName = array_column($currentDocument['commands'], null, 'name');
    foreach ($retainedDocument['commands'] as $command) {
        if (($currentByName[$command['name']] ?? null) !== $command) {
            throw new RuntimeException(sprintf(
                'CLI generation 2 changed retained generation-one command "%s"; it may only add commands.',
                $command['name'],
            ));
        }
    }
    $bytes = ['v1' => $retained, 'v2' => $current];

    if ($write) {
        foreach ($mirrors as $label => $path) {
            try {
                $created = RetainedMachineContractWriter::establish($path, $bytes[$label]);
            } catch (LogicException) {
                throw new RuntimeException(sprintf(
                    'Refusing to overwrite retained CLI generation %s with different bytes. '
                    . 'Preserve it and publish the compatibility change under a successor generation.',
                    $label,
                ));
            }
            fwrite(STDOUT, $created
                ? sprintf("Established docs/machine-contract/cli-%s.json.\n", $label)
                : sprintf("docs/machine-contract/cli-%s.json already contains the retained bytes.\n", $label));
        }
    }

    foreach ($mirrors as $label => $path) {
        $documented = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($documented) || !hash_equals(hash('sha256', $bytes[$label]), hash('sha256', $documented))) {
            throw new RuntimeException(sprintf(
                'docs/machine-contract/cli-%s.json differs from its deployed generation. Restore its exact bytes; '
                . 'an intentional compatibility change requires a successor generation, not an overwrite.',
                $label,
            ));
        }
    }

    fwrite(STDOUT, sprintf(
        "CLI machine contract generation %d verified: %d commands, %s; generation %d: %d commands, %s.\n",
        $contract->generation(),
        count($contract->commandNames()),
        $contract->digest(),
        $successor->generation(),
        count($successor->commandNames()),
        $successor->digest(),
    ));
    exit(0);
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}
