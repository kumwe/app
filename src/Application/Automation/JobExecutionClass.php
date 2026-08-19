<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

/**
 * Blast radius of a job type: whether its effect belongs to one site or to the whole installation.
 *
 * `JobExecutionScope` derives the class from the job type, and the queue and scheduler persist it on
 * every schedule and job row. It then decides three things on the way back out: which principal the
 * worker builds the job's execution context from, whether the claim query insists on a live enabled
 * owner site, and which resource an `automation.manage` decision is made against.
 *
 * @since  2.0.0
 */
enum JobExecutionClass: string
{
    /**
     * Work belonging to the site that created it, and executed on that site's behalf.
     *
     * @since  2.0.0
     */
    case Site = 'site';

    /**
     * Work whose effect spans the installation, so it outlives any one site and runs as an internal identity.
     *
     * @since  2.0.0
     */
    case Installation = 'installation';
}
