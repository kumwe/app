<?php

/**
 * Console container factory used by the `bin/kumwe` entry point.
 *
 * Recovery commands must run when the ordinary container cannot be built — a broken extension
 * registry, an unmigrated database, a lost lock. This script inspects the requested command and
 * returns the reduced recovery container for that fixed list, and the full container otherwise, so a
 * site can always be diagnosed and repaired from the command line.
 *
 * @return \Kumwe\App\Kernel\Container The container appropriate to the requested command.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;

$command = $_SERVER['argv'][1] ?? '';
$recoveryCommands = [
    'app:health',
    'theme:administrator:recover',
    'database:recover-lock',
    'database:status',
    'extension:runtime:materialize',
    'extension:runtime:watch',
    'extension:trust',
    'database:migrate',
    'user:create-admin',
    'user:recover-credentials',
];
$factory = new ContainerFactory();

return in_array($command, $recoveryCommands, true)
    ? $factory->createRecovery(Environment::fromGlobals())
    : $factory->create(Environment::fromGlobals());
