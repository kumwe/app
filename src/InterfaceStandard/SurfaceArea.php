<?php

declare(strict_types=1);

namespace Kumwe\App\InterfaceStandard;

/**
 * Delivery area whose shell or template hosts a semantic surface.
 *
 * Template is explicit because an installable theme may declare conformance independently of the
 * administrator, portal, or public route whose semantic slots it implements.
 *
 * @since  2.0.0
 */
enum SurfaceArea: string
{
    /**
     * Authenticated administrator shell.
     *
     * @since  2.0.0
     */
    case Administrator = 'administrator';

    /**
     * Ordinary-user portal shell, including its public authentication entry points.
     *
     * @since  2.0.0
     */
    case Portal = 'portal';

    /**
     * Public site surface outside the administrator and portal shells.
     *
     * @since  2.0.0
     */
    case Public = 'public';

    /**
     * Installable template implementation of one or more actor-facing surfaces.
     *
     * @since  2.0.0
     */
    case Template = 'template';
}
