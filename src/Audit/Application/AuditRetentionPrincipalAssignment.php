<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Application;

/**
 * Port the migration command uses to bring the database's audit retention principal in line with configuration.
 *
 * @since  2.0.0
 */
interface AuditRetentionPrincipalAssignment
{
    /**
     * Assign the configured retention login when the database names a different one.
     *
     * @return  bool  True when the assignment changed; false when nothing is configured or it already holds.
     *
     * @throws  \RuntimeException  When the retention authority guards are not installed.
     * @throws  \InvalidArgumentException  When the configured login is not a plain database login name.
     *
     * @since   2.0.0
     */
    public function synchronize(): bool;
}
