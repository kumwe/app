<?php

declare(strict_types=1);

namespace Kumwe\App\Kernel\Configuration;

use InvalidArgumentException;
use Kumwe\App\Shared\Domain\DatabaseTablePrefix;

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
     * @param   string  $driver                  Engine to bind to: `pgsql`, `mysql`, or `mariadb`.
     * @param   string  $host                    Host name or IP address of the database server.
     * @param   int     $port                    TCP port the server listens on, between 1 and 65535.
     * @param   string  $database                Name of the database Kumwe's tables live in.
     * @param   string  $user                    Account Kumwe authenticates as.
     * @param   string  $password                Secret for that account; never include it in log or error output.
     * @param   string  $tablePrefix             Prefix concatenated onto every physical table name, validated
     *          against `DatabaseTablePrefix` because it reaches SQL unquoted.
     * @param   string  $sslMode                 Transport policy: `disable`, `prefer`, `require`, `verify-ca`,
     *          or `verify-full`.
     * @param   string  $serverVersion           Engine version Doctrine assumes when choosing platform
     *          behaviour, so it need not probe the server to find out.
     * @param   string  $auditRetentionUser      Separate login audit retention runs as, or empty when this
     *          process holds no retention credential and retention therefore refuses to delete.
     * @param   string  $auditRetentionPassword  Secret for that login; never include it in log or error output.
     *
     * @throws  InvalidArgumentException  When the driver, host, port, table prefix, SSL mode, server
     *          version, or audit retention credential is missing or outside the accepted set.
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
        public string $auditRetentionUser = '',
        public string $auditRetentionPassword = '',
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

        if (
            ($auditRetentionUser === '') !== ($auditRetentionPassword === '')
            || ($auditRetentionUser !== ''
                && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.$-]{0,62}$/D', $auditRetentionUser) !== 1)
        ) {
            throw new InvalidArgumentException(
                'DB_AUDIT_RETENTION_USER and DB_AUDIT_RETENTION_PASSWORD must both name a plain database login.',
            );
        }
    }

    /**
     * Report whether this process was given the separate audit retention credential.
     *
     * @return  bool  True when a retention login and its secret are configured.
     *
     * @since   2.0.0
     */
    public function hasAuditRetentionCredential(): bool
    {
        return $this->auditRetentionUser !== '';
    }

    /**
     * Derive the settings for a connection authenticated as the audit retention principal.
     *
     * @return  self  The same server, database and transport policy with the retention login.
     *
     * @throws  InvalidArgumentException  When no retention credential is configured.
     *
     * @since   2.0.0
     */
    public function forAuditRetention(): self
    {
        if (!$this->hasAuditRetentionCredential()) {
            throw new InvalidArgumentException('No audit retention database credential is configured.');
        }

        return new self(
            $this->driver,
            $this->host,
            $this->port,
            $this->database,
            $this->auditRetentionUser,
            $this->auditRetentionPassword,
            $this->tablePrefix,
            $this->sslMode,
            $this->serverVersion,
        );
    }
}
