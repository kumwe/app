<?php

declare(strict_types=1);

use Kumwe\CMS\Tests\Support\AssetInspectionDeploymentAcceptance;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/AssetInspectionDeploymentAcceptance.php';

exit(AssetInspectionDeploymentAcceptance::main($argv));
