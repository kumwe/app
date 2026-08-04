<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

enum JobStatus: string
{
    case PENDING = 'pending';
    case RESERVED = 'reserved';
    case COMPLETED = 'completed';
    case DEAD = 'dead';
}
