<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

/**
 * Reconciles the current trusted schedule declarations before scheduler access.
 *
 * @since  2.0.0
 */
interface ScheduleRuntimeSynchronizer
{
    /**
     * Reconcile active signed schedules with durable scheduler rows.
     *
     * @return  bool  True when the migrated persistence schema was synchronized.
     *
     * @since   2.0.0
     */
    public function synchronize(): bool;
}
