<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

/**
 * How much acknowledgement a caller must supply before a `ChangePlan` may be applied.
 *
 * The requirement is fixed when the plan is created and decides whether `ChangePlan` issues a
 * confirmation token at all. It exists so that a destructive command can be previewed and then
 * demand a deliberate second step, while a routine command applies on a matching digest alone.
 *
 * @since  2.0.0
 */
enum ConfirmationRequirement: string
{
    /**
     * A matching command digest is sufficient; no confirmation token is issued or expected.
     *
     * @since  2.0.0
     */
    case NONE = 'none';

    /**
     * The plan issues a confirmation token that the caller must echo back when applying it.
     *
     * @since  2.0.0
     */
    case EXPLICIT = 'explicit';
}
