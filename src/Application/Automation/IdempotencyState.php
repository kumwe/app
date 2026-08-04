<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

enum IdempotencyState: string
{
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
