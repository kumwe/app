<?php

declare(strict_types=1);

use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;

$command = $_SERVER['argv'][1] ?? '';
$recoveryCommands = [
    'app:health',
    'administrator:theme:recover',
    'database:recover-lock',
    'database:status',
    'extension:runtime:materialize',
    'extension:runtime:watch',
    'extension:trust',
    'database:migrate',
];
$factory = new ContainerFactory();

return in_array($command, $recoveryCommands, true)
    ? $factory->createRecovery(Environment::fromGlobals())
    : $factory->create(Environment::fromGlobals(), true, true);
