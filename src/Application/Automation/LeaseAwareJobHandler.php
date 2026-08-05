<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

/**
 * Opt-in contract for work that may outlive its initial lease.
 *
 * Implementations should renew at safe checkpoints. Existing JobHandler
 * implementations remain source-compatible and continue to use handle().
 */
interface LeaseAwareJobHandler extends JobHandler
{
    /** @param array<string, mixed> $payload */
    public function handleWithLease(array $payload, JobLeaseContext $lease): void;
}
