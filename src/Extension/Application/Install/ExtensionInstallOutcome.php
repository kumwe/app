<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Install;

enum ExtensionInstallOutcome: string
{
    case Unknown = 'unknown';
    case RolledBack = 'rolled_back';
    case Committed = 'committed';
}
