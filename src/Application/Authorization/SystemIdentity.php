<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

/**
 * The closed set of unattended actors Kumwe will issue an execution context to.
 *
 * Work that runs with no operator present still has to name who is acting. A `SystemPrincipal` binds
 * one of these cases for the life of the process, `ExecutionContext::issueSystem()` stamps it on the
 * context, and owner-bound resource-policy definitions explicitly name the cases permitted to use each
 * action/resource binding — unattended authority is therefore registered as typed core data, never
 * inferred from a stored grant. Keeping the set small and each binding narrow is what stops one
 * compromised background task from acting with the authority of another; the backing value is also the
 * actor identifier written into audit records, which is why it is a stable `system:` token.
 *
 * @since  2.0.0
 */
enum SystemIdentity: string
{
    /**
     * Creates the first administrator account on an installation that has no operator yet.
     *
     * Held only by the console command that bootstraps an account, and granted nothing beyond
     * `administrator.bootstrap`, so it cannot be reused to change identity records afterwards.
     *
     * @since  2.0.0
     */
    case Bootstrap = 'system:bootstrap';

    /**
     * Console execution that carries no authority of its own.
     *
     * No resource policy names it, so a context issued under this identity is refused every action;
     * console work that must change something is given a purpose-built identity instead.
     *
     * @since  2.0.0
     */
    case CommandLine = 'system:cli';

    /**
     * Recompiles the extension runtime map, installation-wide rather than for one site.
     *
     * Granted `extensions.manage` and allowed to act on installation-global resources, which is what
     * lets the queued runtime rebuild touch state no single site owns.
     *
     * @since  2.0.0
     */
    case ExtensionMaterializer = 'system:extension-materializer';

    /**
     * Runs the housekeeping jobs that keep installation-wide tables from growing without bound.
     *
     * Granted `automation.manage` with installation-global reach, and used for the idempotency purges
     * that would otherwise have no site to run under.
     *
     * @since  2.0.0
     */
    case InstallationMaintenance = 'system:installation-maintenance';

    /**
     * Applies schema migrations and recovers an abandoned migration lock.
     *
     * Its single capability is `system.migrate`, so a migration process cannot reach content, identity
     * or automation operations even though it runs with the database open in front of it.
     *
     * @since  2.0.0
     */
    case Migration = 'system:migration';

    /**
     * Turns due schedules into queued jobs.
     *
     * Granted `system.scheduler.dispatch` alone: it decides that an occurrence is due and enqueues it,
     * while the work itself is later performed under `Worker`.
     *
     * @since  2.0.0
     */
    case Scheduler = 'system:scheduler';

    /**
     * Executes queued jobs on behalf of whoever enqueued them.
     *
     * Holds the widest system capability list, because a job handler may move content through its
     * lifecycle. Its reach is still site-local: an installation-global job is re-issued under the
     * dedicated identity `JobExecutionScope` declares for that job type before the handler runs.
     *
     * @since  2.0.0
     */
    case Worker = 'system:worker';
}
