<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

/**
 * Names the engine and the build that business-schema decisions on this deployment are bound to.
 *
 * A recovery drill proves that a backup can be restored on one engine, one server version and one
 * application release; approving or executing a destructive plan against a different identity would rest
 * on a proof nobody performed. `BusinessSchemaService` and `BusinessSchemaExecutor` therefore compare
 * stored `SchemaRecoveryEvidence` field by field against this port before they let such a plan through,
 * and the executor also branches its DDL strategy on the driver. Implementations answer for the process,
 * not for one connection: the three values must be settled before any comparison happens, stay constant
 * for the life of the process, and be safe to match byte for byte, because callers compare them exactly
 * rather than normalising them. Nothing here re-validates them, so an implementation owes its own checks.
 *
 * @since  2.0.0
 */
interface BusinessSchemaEnvironment
{
    /**
     * Name the database engine every schema plan and recovery drill on this deployment is bound to.
     *
     * @return  string  One of `mariadb`, `mysql` or `pgsql`; the executor branches on these exact spellings
     *          to pick its rewrite strategy and to judge whether interrupted DDL could have committed.
     *
     * @since   2.0.0
     */
    public function databaseDriver(): string;

    /**
     * Report the database server version the schema decisions on this deployment are made against.
     *
     * @return  string  Version text compared verbatim against the version recorded on recovery evidence.
     *
     * @since   2.0.0
     */
    public function databaseServerVersion(): string;

    /**
     * Report the application release that is doing the schema work.
     *
     * @return  string  Release identifier compared verbatim against the release recorded on recovery
     *          evidence, so a drill run by an older build cannot authorize this one.
     *
     * @since   2.0.0
     */
    public function applicationRelease(): string;
}
