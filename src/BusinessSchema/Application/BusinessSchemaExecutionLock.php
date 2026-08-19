<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

/**
 * Serialises business-schema execution and stamps each run with a fence the journal can be trusted by.
 *
 * Applying a plan means a long sequence of DDL and journal writes that no second executor may interleave
 * with, and a run that lost the lock — a dropped connection, a container killed mid-migration — must not
 * be able to keep writing as though it still owned the schema. This port answers both needs at once.
 * `BusinessSchemaExecutor` wraps its whole run in `synchronized()` and stamps the fence it is handed onto
 * every plan and step row it writes, so the plan repository can reject a write carrying a fence that has
 * since been superseded. The fence is what makes that check sound, which is why it must outlive a crash
 * rather than live in process memory.
 *
 * @since  2.0.0
 */
interface BusinessSchemaExecutionLock
{
    /**
     * Run an operation while this caller alone may apply business-schema changes.
     *
     * The lock is not a queue: an implementation refuses a caller that cannot take it immediately rather
     * than parking it behind a migration that may run for hours, and releases it however the operation
     * ends. Exclusion covers at least the named definition, and an implementation may serialise more
     * widely than that. The fence is allocated durably before the operation starts and is strictly
     * greater than every fence issued before it, so writes from a superseded run are recognisable on
     * sight. Whatever the operation throws propagates to the caller once the lock has been given up.
     *
     * @template T
     *
     * @param   string            $definitionId  Definition whose schema this run is about to change, as a UUID.
     * @param   callable(int): T  $operation     Receives a monotonic durable fence.
     *
     * @return  T  Whatever the operation returned, passed back untouched.
     *
     * @since   2.0.0
     */
    public function synchronized(string $definitionId, callable $operation): mixed;
}
