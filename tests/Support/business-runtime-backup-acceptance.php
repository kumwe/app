<?php

declare(strict_types=1);

use Kumwe\CMS\Tests\Support\BusinessRuntimeBackupAcceptance;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/TestKernelFactory.php';
require __DIR__ . '/NeutralBusinessFixture.php';
require __DIR__ . '/BusinessRuntimeBackupAcceptance.php';

exit(BusinessRuntimeBackupAcceptance::main($argv));
