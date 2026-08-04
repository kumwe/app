<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

interface Scheduler
{
    public function dispatchDue(int $limit = 100): int;
}
