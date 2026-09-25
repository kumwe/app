<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use Kumwe\Automation\StoredJob;

/**
 * Answers which end-to-end operation a claimed job belongs to, so its execution joins that operation.
 *
 * The worker builds a fresh execution context for every job it runs. Without this port that context
 * carried the worker process's own correlation identifier, so everything a job did — its audit rows, the
 * integration events it published, the jobs it queued in turn and its log lines — was cut off from the
 * HTTP request or scheduler pass that queued it. The store that persisted the job records the producing
 * context's correlation identifier beside the row; this port reads it back for the worker without the
 * application layer depending on how, or where, the row is stored.
 *
 * @since  2.0.0
 */
interface JobOriginLookup
{
    /**
     * Read the correlation identifier recorded when the job was queued.
     *
     * @param   StoredJob  $job  Job the worker has just claimed.
     *
     * @return  ?string  The producing operation's correlation identifier, or null for a row written
     *          before origins were recorded, in which case the worker keeps its own.
     *
     * @since   2.0.0
     */
    public function correlationOf(StoredJob $job): ?string;
}
