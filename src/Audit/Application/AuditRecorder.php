<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Application;

use Kumwe\App\Audit\Domain\AuditEvent;

/**
 * Sink that application use cases hand a finished audit record to.
 *
 * Every state mutation in Kumwe is audited, and use cases record from inside the transaction that
 * carries the change itself, so an implementation must fail loudly rather than swallow an event it
 * cannot store: the surrounding rollback is what stops the trail from disagreeing with the data.
 * The port is deliberately write-only — audit history is read back through administration queries,
 * never through this interface — which lets application code depend on the obligation to record
 * without knowing whether the trail lands in the database, a file, or a test double.
 *
 * @since  2.0.0
 */
interface AuditRecorder
{
    /**
     * Store one audit record durably.
     *
     * @param   AuditEvent  $event  Validated record of who did what to which subject, and how it ended.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(AuditEvent $event): void;
}
