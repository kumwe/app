<?php

declare(strict_types=1);

use Kumwe\CMS\Tests\Support\BusinessRuntimeBackupAcceptance;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

exit(BusinessRuntimeBackupAcceptance::main($argv));
