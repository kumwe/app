<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Install;

enum InstallState: string
{
    case Planned = 'planned';
    case Executing = 'executing';
    case Failed = 'failed';
    case RollingBack = 'rolling_back';
    case RolledBack = 'rolled_back';
    case Committed = 'committed';
}
