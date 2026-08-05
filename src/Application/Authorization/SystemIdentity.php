<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

enum SystemIdentity: string
{
    case Bootstrap = 'system:bootstrap';
    case CommandLine = 'system:cli';
    case ExtensionMaterializer = 'system:extension-materializer';
    case InstallationMaintenance = 'system:installation-maintenance';
    case Migration = 'system:migration';
    case Scheduler = 'system:scheduler';
    case Worker = 'system:worker';
}
