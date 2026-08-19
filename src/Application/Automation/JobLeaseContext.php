<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use Closure;
use InvalidArgumentException;

/**
 * Handle a long-running handler uses to keep its own job lease alive while it works.
 *
 * The worker builds one of these per job and passes it to a `LeaseAwareJobHandler`. Renewing at a safe
 * checkpoint pushes the lease expiry out and refreshes the worker heartbeat, so work that legitimately
 * outruns the initial lease is not reaped and re-claimed by a sibling worker. The renewal closure comes
 * from the worker, which keeps handlers away from the queue itself.
 *
 * @since  2.0.0
 */
final readonly class JobLeaseContext
{
    /**
     * Bind a renewal callback to the job whose lease it extends.
     *
     * @param   string              $jobId                Identifier of the job being executed.
     * @param   int                 $defaultLeaseSeconds  Window used when renewal is requested without a length.
     * @param   Closure(int): void  $renewal              Worker callback extending the lease by the given seconds.
     *
     * @throws  InvalidArgumentException  When the default window falls outside 5 to 3600 seconds.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $jobId,
        private int $defaultLeaseSeconds,
        private Closure $renewal,
    ) {
        if ($defaultLeaseSeconds < 5 || $defaultLeaseSeconds > 3_600) {
            throw new InvalidArgumentException('A job lease must last between 5 and 3600 seconds.');
        }
    }

    /**
     * Push the job's lease expiry out from now, so a slow handler is not reaped mid-flight.
     *
     * @param   ?int  $leaseSeconds  Window to request in seconds, or null to reuse the default window.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function renew(?int $leaseSeconds = null): void
    {
        ($this->renewal)($leaseSeconds ?? $this->defaultLeaseSeconds);
    }
}
