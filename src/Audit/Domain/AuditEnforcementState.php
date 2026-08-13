<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Domain;

/**
 * Whether database-level append-only enforcement is actually present on the server holding the trail.
 *
 * The tamper-evidence stack makes two different promises, and this separates them. Digest chaining,
 * the monotonic position, the anchor ledger and verification are *evidence*: they make a mutated,
 * deleted, reordered or inserted row detectable after the fact, and they work on every supported
 * server. The append-only triggers are *prevention*: they make the write fail at the database in the
 * first place. Prevention needs a privilege the server may refuse to grant — a managed MySQL with
 * binary logging enabled and no `SUPER` is the common case — so it is a property of the deployment,
 * never of the release, and it can differ between two installations running identical code.
 *
 * The value is therefore always *observed* from the server's own catalog rather than remembered from
 * whatever the migration managed to do, which is what keeps it true after a dump is restored onto a
 * different server, after a DBA grants the missing privilege, and after someone drops the triggers.
 *
 * @since  2.0.0
 */
enum AuditEnforcementState: string
{
    /**
     * The triggers are present, so the database itself refuses an `UPDATE` or an unsanctioned `DELETE`.
     *
     * @since  2.0.0
     */
    case Active = 'active';

    /**
     * The triggers are absent, so nothing but application discipline keeps the trail append-only.
     *
     * @since  2.0.0
     */
    case NotInstalled = 'not_installed';

    /**
     * Report whether database-level prevention is in force.
     *
     * @return  bool  True only when the guards are installed on this server.
     *
     * @since   2.0.0
     */
    public function installed(): bool
    {
        return $this === self::Active;
    }

    /**
     * Describe the state in the words an operator reading a verification report needs.
     *
     * @return  string  One sentence naming what is and is not being enforced.
     *
     * @since   2.0.0
     */
    public function summary(): string
    {
        return match ($this) {
            self::Active => 'Database-level append-only enforcement is installed on this server.',
            self::NotInstalled => 'Database-level append-only enforcement is NOT installed on this server; '
                . 'the trail is append-only by application-level discipline only. Tamper evidence is '
                . 'unaffected: digests, witness links, anchors and verification all still hold.',
        };
    }
}
