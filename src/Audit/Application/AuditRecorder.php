<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Application;

use Kumwe\CMS\Audit\Domain\AuditEvent;

interface AuditRecorder
{
    public function record(AuditEvent $event): void;
}
