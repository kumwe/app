<?php

declare(strict_types=1);

use Kumwe\CMS\Tests\Support\AssetInspectionDeploymentAcceptance;

require __DIR__ . '/deployment-drill-autoload.php';

exit(AssetInspectionDeploymentAcceptance::main($argv));
