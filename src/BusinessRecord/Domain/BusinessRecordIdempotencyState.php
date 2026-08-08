<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

enum BusinessRecordIdempotencyState: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
