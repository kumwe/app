<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Application;

/**
 * Who, if anyone, the database lets remove audit evidence, observed from its catalog on every call.
 *
 * Deleting audit rows and appending a retention (`prune`) mark to the anchor ledger is refused by the
 * database unless the session is authenticated as the separately provisioned retention principal. The
 * principal is named by a database routine only a schema owner can replace, so neither a session variable
 * nor any ordinary data-manipulation statement can grant that authority. This state reports how that
 * boundary stands for the connection it was observed on; only `Separated` lets retention run.
 *
 * @since  2.0.0
 */
enum AuditRetentionAuthorityState: string
{
    /**
     * The authority triggers or the principal routine are absent, so the boundary is not enforced.
     *
     * @since  2.0.0
     */
    case NotInstalled = 'not_installed';

    /**
     * The boundary is installed but names no retention principal: nobody may delete evidence.
     *
     * @since  2.0.0
     */
    case Unassigned = 'unassigned';

    /**
     * The observed runtime session itself holds retention authority, so the separation is void.
     *
     * @since  2.0.0
     */
    case NotSeparated = 'not_separated';

    /**
     * A distinct retention principal is assigned and the observed runtime session is not it.
     *
     * @since  2.0.0
     */
    case Separated = 'separated';

    /**
     * The engine has no database principals (the SQLite test engine), so no separation can exist.
     *
     * @since  2.0.0
     */
    case SinglePrincipal = 'single_principal';

    /**
     * Report whether this posture lets the ordinary runtime session be told apart from retention authority.
     *
     * @return  bool  True for a separated principal, and for the principal-less SQLite test engine.
     *
     * @since   2.0.0
     */
    public function permitsRetention(): bool
    {
        return $this === self::Separated || $this === self::SinglePrincipal;
    }

    /**
     * Report whether this posture weakens prevention against the ordinary runtime principal.
     *
     * An unassigned principal is not a weakness: every deletion is refused and retention simply cannot
     * run. A missing boundary or a runtime session that is itself the retention principal is.
     *
     * @return  bool  True when the runtime principal could remove evidence it should not.
     *
     * @since   2.0.0
     */
    public function degradesPrevention(): bool
    {
        return $this === self::NotInstalled || $this === self::NotSeparated;
    }

    /**
     * Describe the posture for an operator reading a verification verdict.
     *
     * @return  string  One sentence naming what the database currently allows.
     *
     * @since   2.0.0
     */
    public function summary(): string
    {
        return match ($this) {
            self::NotInstalled => 'The audit retention authority guards are NOT installed; the database does not '
                . 'restrict audit deletion to a separate retention principal.',
            self::Unassigned => 'No audit retention principal is assigned; the database refuses every audit '
                . 'deletion and retention cannot run.',
            self::NotSeparated => 'The runtime database principal holds audit retention authority; assign a '
                . 'separate retention principal before relying on the prevention boundary.',
            self::Separated => 'Only the separately assigned retention principal may remove archived audit '
                . 'evidence; the runtime principal cannot.',
            self::SinglePrincipal => 'This engine has no database principals; retention authority cannot be '
                . 'separated here.',
        };
    }
}
