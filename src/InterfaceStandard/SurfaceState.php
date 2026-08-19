<?php

declare(strict_types=1);

namespace Kumwe\App\InterfaceStandard;

/**
 * Data or authorization state a surface explicitly promises to render coherently.
 *
 * @since  2.0.0
 */
enum SurfaceState: string
{
    /**
     * Ordinary representative state for the primary task.
     *
     * @since  2.0.0
     */
    case Default = 'default';

    /**
     * No resource is available for the current query or scope.
     *
     * @since  2.0.0
     */
    case Empty = 'empty';

    /**
     * Small result set that must not leave the workspace visually ambiguous.
     *
     * @since  2.0.0
     */
    case Sparse = 'sparse';

    /**
     * Large or wide representative data at the supported operational limit.
     *
     * @since  2.0.0
     */
    case Dense = 'dense';

    /**
     * Recoverable validation, loading, or operation failure.
     *
     * @since  2.0.0
     */
    case Error = 'error';

    /**
     * Coherent surface after policy removes fields, actions, or destinations.
     *
     * @since  2.0.0
     */
    case PermissionReduced = 'permission-reduced';

    /**
     * Immutable or package-owned resource displayed without misleading edit affordances.
     *
     * @since  2.0.0
     */
    case ReadOnly = 'read-only';
}
