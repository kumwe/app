<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel\Configuration;

use InvalidArgumentException;
use Kumwe\CMS\Shared\Domain\DatabaseTablePrefix;

/**
 * Validated connection settings for the relational database that holds all Kumwe state.
 *
 * `ConfigurationFactory` builds one instance from the `DB_*` variables and hands it to
 * `DoctrineConnectionFactory`, which turns it into a DBAL connection, and to `TableNames` and the
 * physical name compiler, which build table names from the prefix. Every rule lives in the
 * constructor so an unreachable host, an out-of-range port, or a prefix that is unsafe to
 * concatenate into SQL stops the process at boot instead of surfacing on the first query.
 *
 * @since  2.0.0
 */
final readonly class DatabaseConfiguration
{
    /**
     * Capture and validate the settings needed to open a connection.
     *
     * @param   string  $driver         Engine to bind to: `pgsql`, `mysql`, or `mariadb`.
     * @param   string  $host           Host name or IP address of the database server.
     * @param   int     $port           TCP port the server listens on, between 1 and 65535.
     * @param   string  $database       Name of the database Kumwe's tables live in.
     * @param   string  $user           Account Kumwe authenticates as.
     * @param   string  $password       Secret for that account; never include it in log or error output.
     * @param   string  $tablePrefix    Prefix concatenated onto every physical table name, validated
     *          against `DatabaseTablePrefix` because it reaches SQL unquoted.
     * @param   string  $sslMode        Transport policy: `disable`, `prefer`, `require`, `verify-ca`,
     *          or `verify-full`.
     * @param   string  $serverVersion  Engine version Doctrine assumes when choosing platform
     *          behaviour, so it need not probe the server to find out.
     *
     * @throws  InvalidArgumentException  When the driver, host, port, table prefix, SSL mode, or
     *          server version is missing or outside the accepted set.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $user,
        public string $password,
        public string $tablePrefix,
        public string $sslMode,
        public string $serverVersion,
    ) {
        if (!in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            throw new InvalidArgumentException('DB_DRIVER must be pgsql, mysql, or mariadb.');
        }
        if (
            filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            && filter_var($host, FILTER_VALIDATE_IP) === false
        ) {
            throw new InvalidArgumentException('The database host is invalid.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The database port is invalid.');
        }

        if (!DatabaseTablePrefix::isValid($tablePrefix)) {
            throw new InvalidArgumentException('The database table prefix is invalid.');
        }

        if (!in_array($sslMode, ['disable', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            throw new InvalidArgumentException('The database SSL mode is invalid.');
        }

        if (trim($serverVersion) === '') {
            throw new InvalidArgumentException('The database server version is required.');
        }
    }
}
