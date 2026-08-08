<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

enum SchemaStepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Compensated = 'compensated';
    case Skipped = 'skipped';

    public function terminal(): bool
    {
        return in_array($this, [self::Completed, self::Compensated, self::Skipped], true);
    }
}
