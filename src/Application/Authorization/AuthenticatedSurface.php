<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

/**
 * Delivery boundary through which an execution context authenticated.
 *
 * @since  2.0.0
 */
enum AuthenticatedSurface: string
{
    /** Administrator browser, never accepted by portal-only routes. @since 2.0.0 */
    case Administrator = 'administrator';

    /** Ordinary-user portal browser, never accepted by administrator or recovery routes. @since 2.0.0 */
    case Portal = 'portal';

    /** Public REST application programming interface. @since 2.0.0 */
    case Api = 'api';

    /** Model Context Protocol tool surface. @since 2.0.0 */
    case Mcp = 'mcp';

    /** Command-line management surface. @since 2.0.0 */
    case Cli = 'cli';

    /** Constrained unattended worker or schedule. @since 2.0.0 */
    case Background = 'background';

    /** Installation recovery surface, isolated from normal authentication. @since 2.0.0 */
    case Recovery = 'recovery';
}
