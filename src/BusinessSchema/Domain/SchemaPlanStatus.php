<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

enum SchemaPlanStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Executing = 'executing';
    case Completed = 'completed';
    case Failed = 'failed';
    case RecoveryRequired = 'recovery_required';
    case Compensated = 'compensated';
    case Cancelled = 'cancelled';

    public function terminal(): bool
    {
        return in_array($this, [self::Completed, self::Compensated, self::Cancelled], true);
    }
}
