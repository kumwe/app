<?php

declare(strict_types=1);

use Kumwe\App\Tests\Support\BusinessRuntimeBackupAcceptance;

require __DIR__ . '/deployment-drill-autoload.php';

exit(BusinessRuntimeBackupAcceptance::main($argv));
