<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Domain;

/**
 * Explicit durable effect requested by a process transition.
 *
 * @since  2.0.0
 */
enum ProcessWorkKind: string
{
    /** A due-time signal routed back to process logic. @since 2.0.0 */
    case TIMER = 'timer';

    /** An idempotent command handed to an application or job handler. @since 2.0.0 */
    case COMMAND = 'command';

    /** A best-effort compensating request, never represented as rollback. @since 2.0.0 */
    case COMPENSATION = 'compensation';
}
