<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Driver\Exception as DriverError;
use PDOException;
use Throwable;

/**
 * Recognises the narrow set of driver errors that mean "this server will not let you install triggers".
 *
 * Installing the append-only guards needs a privilege a managed database may simply refuse, and the
 * refusal has to be told apart from every other reason a `CREATE TRIGGER` can fail — a typo in the
 * statement, a missing table, a lost connection — because those must still abort the migration loudly.
 * The distinction is made on the driver's own error code and SQLSTATE, never on message text, which is
 * localised, and never on the DBAL exception class, which is not a reliable signal here: Doctrine maps
 * MySQL 1142 and 1227 onto `ConnectionException` while leaving 1419 as a bare `DriverException`, so
 * three refusals of the same kind arrive as two unrelated types.
 *
 * The whole chain is walked, because the code lives on the innermost driver exception while DBAL hands
 * the caller a wrapper, and a `PDOException` reached directly carries the driver code in `errorInfo`
 * rather than in `getCode()`, which holds the SQLSTATE string instead.
 *
 * @since  2.0.0
 */
final class AuditEnforcementRefusal
{
    /**
     * MySQL and MariaDB error codes that report a refusal to create the trigger, not a broken statement.
     *
     * `1419` is raised when binary logging is enabled and the account lacks `SUPER`, which is the
     * default posture of Amazon RDS, Cloud SQL and Azure Database for MySQL. `1227` is the generic
     * "you need a specific privilege" refusal, and `1142` is the `TRIGGER` command being denied on the
     * table outright.
     *
     * @var    list<int>
     * @since  2.0.0
     */
    private const array MYSQL_CODES = [1419, 1227, 1142];

    /**
     * PostgreSQL SQLSTATE for `insufficient_privilege`, its equivalent of the MySQL refusals.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string POSTGRES_SQLSTATE = '42501';

    /**
     * Report whether a failure is the server declining the privilege rather than rejecting the work.
     *
     * @param   Throwable  $error  Failure raised while installing an append-only guard.
     *
     * @return  bool  True only when a driver in the chain reported a privilege or capability refusal.
     *
     * @since   2.0.0
     */
    public static function matches(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if (self::refusesAt($current)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Test one link of the exception chain against the known refusal codes.
     *
     * @param   Throwable  $error  One exception from the chain, driver-level or DBAL wrapper.
     *
     * @return  bool  True when this link carries a refusal code or SQLSTATE.
     *
     * @since   2.0.0
     */
    private static function refusesAt(Throwable $error): bool
    {
        if ($error instanceof PDOException) {
            $info = $error->errorInfo;

            return self::refuses(
                is_array($info) && is_string($info[0] ?? null) ? $info[0] : null,
                is_array($info) && is_int($info[1] ?? null) ? $info[1] : null,
            );
        }
        if ($error instanceof DriverError) {
            $code = $error->getCode();

            return self::refuses($error->getSQLState(), is_int($code) ? $code : null);
        }

        return false;
    }

    /**
     * Decide one SQLSTATE and driver code pair.
     *
     * @param   ?string  $sqlState  SQLSTATE the driver reported, or null when it reported none.
     * @param   ?int     $code      Driver-specific error code, or null when it is not an integer.
     *
     * @return  bool  True when either value names a privilege or capability refusal.
     *
     * @since   2.0.0
     */
    private static function refuses(?string $sqlState, ?int $code): bool
    {
        return $sqlState === self::POSTGRES_SQLSTATE || ($code !== null && in_array($code, self::MYSQL_CODES, true));
    }
}
