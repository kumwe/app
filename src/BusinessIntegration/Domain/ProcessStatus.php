<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Domain;

/**
 * Durable lifecycle of a generic process-manager instance.
 *
 * @since  2.0.0
 */
enum ProcessStatus: string
{
    /** The process accepts further events and may have pending work. @since 2.0.0 */
    case RUNNING = 'running';

    /** The process reached its successful terminal state. @since 2.0.0 */
    case COMPLETED = 'completed';

    /** An operator or policy cancelled the process. @since 2.0.0 */
    case CANCELLED = 'cancelled';

    /** The process reached an unsuccessful terminal state. @since 2.0.0 */
    case FAILED = 'failed';
}
