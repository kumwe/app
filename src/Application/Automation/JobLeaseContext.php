<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use Closure;
use InvalidArgumentException;

final readonly class JobLeaseContext
{
    /** @param Closure(int): void $renewal */
    public function __construct(
        public string $jobId,
        private int $defaultLeaseSeconds,
        private Closure $renewal,
    ) {
        if ($defaultLeaseSeconds < 5 || $defaultLeaseSeconds > 3_600) {
            throw new InvalidArgumentException('A job lease must last between 5 and 3600 seconds.');
        }
    }

    public function renew(?int $leaseSeconds = null): void
    {
        ($this->renewal)($leaseSeconds ?? $this->defaultLeaseSeconds);
    }
}
