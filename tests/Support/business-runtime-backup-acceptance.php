<?php

declare(strict_types=1);

use Kumwe\CMS\Tests\Support\BusinessRuntimeBackupAcceptance;

require __DIR__ . '/deployment-drill-autoload.php';

exit(BusinessRuntimeBackupAcceptance::main($argv));
