<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Retention;

/**
 * The three answers a retention readiness assessment can give, ordered from best to worst.
 *
 * @since  2.0.0
 */
enum RetentionReadinessState: int
{
    /**
     * Every store is configured and no forecast predicts exhaustion inside the warning horizon.
     *
     * @since  2.0.0
     */
    case Ready = 0;

    /**
     * Something needs attention but traffic may continue: a setting missing under the baseline
     * profile, or a forecast inside the warning horizon but outside the failure horizon.
     *
     * @since  2.0.0
     */
    case Warning = 1;

    /**
     * Traffic should be drained: a required setting is missing under the enterprise profile, a
     * backlog has reached capacity, or a forecast predicts exhaustion inside the failure horizon.
     *
     * @since  2.0.0
     */
    case Failed = 2;
}
