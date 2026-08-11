<?php

declare(strict_types=1);

namespace Kumwe\CMS\InterfaceStandard;

/**
 * Highest configuration layer allowed to change one whitelisted presentation slot.
 *
 * @since  2.0.0
 */
enum CustomizationScope: string
{
    /**
     * Installation theme baseline controlled by the site operator.
     *
     * @since  2.0.0
     */
    case Site = 'site';

    /**
     * Administrator-owned default applied across eligible users.
     *
     * @since  2.0.0
     */
    case Administrator = 'administrator';

    /**
     * Authorized role or workspace default below the administrator layer.
     *
     * @since  2.0.0
     */
    case RoleWorkspace = 'role-workspace';

    /**
     * Personal preference for one authenticated actor.
     *
     * @since  2.0.0
     */
    case User = 'user';
}
