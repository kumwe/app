<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Install;

/**
 * Whether an interrupted extension install left its database work applied, as recorded durably.
 *
 * An install spans a filesystem publication and a database transaction, so a crash or a dropped
 * connection can leave a genuine third answer beside "yes" and "no". This value is what the
 * `extension_install_operations` row stores for that question, and it is deliberately conservative:
 * an install is written as `Unknown` before anything is attempted and stays that way until either the
 * commit is observed or reconciliation at process startup decides, so no ambiguous attempt is ever
 * silently treated as finished.
 *
 * @since  2.0.0
 */
enum ExtensionInstallOutcome: string
{
    /**
     * Still in flight, or interrupted before anyone could see how it ended.
     *
     * Staged and published bytes are both retained under this value, because either one may still be
     * the correct final state; once the attempt has been interrupted, only reconciliation keyed by the
     * operation ID may finalize or retire them.
     *
     * @since  2.0.0
     */
    case Unknown = 'unknown';
    /**
     * The install did not take effect, and its incomplete artifacts have been retired.
     *
     * @since  2.0.0
     */
    case RolledBack = 'rolled_back';
    /**
     * The registry records the release and its files are in place at the runtime path.
     *
     * @since  2.0.0
     */
    case Committed = 'committed';
}
