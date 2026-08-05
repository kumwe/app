<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use Kumwe\CMS\Application\Authorization\ExecutionContext;

interface Scheduler
{
    public function dispatchDue(ExecutionContext $context, int $limit = 100): int;
}
