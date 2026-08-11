<?php

declare(strict_types=1);

namespace Kumwe\CMS\InterfaceStandard;

/**
 * Intended human actor for one interface surface, independent of its current permissions.
 *
 * @since  2.0.0
 */
enum SurfaceActor: string
{
    /**
     * Authenticated operator working in the administrator application.
     *
     * @since  2.0.0
     */
    case Administrator = 'administrator';

    /**
     * Authenticated ordinary user working in the portal application.
     *
     * @since  2.0.0
     */
    case Portal = 'portal';

    /**
     * Visitor using a surface that does not assume an authenticated shell.
     *
     * @since  2.0.0
     */
    case Public = 'public';
}
