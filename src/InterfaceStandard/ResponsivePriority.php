<?php

declare(strict_types=1);

namespace Kumwe\CMS\InterfaceStandard;

/**
 * Importance of one semantic element when usable container width becomes constrained.
 *
 * @since  2.0.0
 */
enum ResponsivePriority: string
{
    /**
     * Identity, state, or primary action that must remain directly available.
     *
     * @since  2.0.0
     */
    case Essential = 'essential';

    /**
     * Information required for the ordinary decision but allowed to reflow.
     *
     * @since  2.0.0
     */
    case Primary = 'primary';

    /**
     * Supporting information allowed to collapse into labelled secondary detail.
     *
     * @since  2.0.0
     */
    case Secondary = 'secondary';

    /**
     * Infrequent information that may defer to progressive disclosure first.
     *
     * @since  2.0.0
     */
    case Optional = 'optional';
}
