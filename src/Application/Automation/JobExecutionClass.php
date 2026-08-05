<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

enum JobExecutionClass: string
{
    case Site = 'site';
    case Installation = 'installation';
}
