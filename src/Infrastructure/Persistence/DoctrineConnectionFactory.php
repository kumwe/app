<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\Infrastructure\Persistence\Type\DoctrineTemporalTypes;
use Kumwe\App\Kernel\Configuration\DatabaseConfiguration;
use Pdo\Mysql;

/**
 * Opens the shared DBAL connection every Kumwe repository, migration and lease works through.
 *
 * `ContainerFactory` shares a single connection built here, so this is the only place the session can be
 * settled: the microsecond-preserving temporal types are installed before the connection exists, and the
 * session time zone is pinned to UTC so a server configured for a local zone cannot shift the instants
 * Kumwe stores. Engine differences are confined to this class — `mysql` and `mariadb` both bind to
 * `pdo_mysql` and differ from `pgsql` only in driver, character set and the shape of the TLS parameters.
 *
 * @since  2.0.0
 */
final readonly class DoctrineConnectionFactory
{
    /**
     * Bind the factory to the settings it opens the connection from.
     *
     * @param  DatabaseConfiguration  $configuration  Validated driver, host, credentials, SSL mode and
     *         server version for this deployment's database.
     *
     * @since  2.0.0
     */
    public function __construct(private DatabaseConfiguration $configuration)
    {
    }

    /**
     * Build a connection with precise temporal types, a UTC session and the configured transport policy.
     *
     * The connection comes back already open rather than lazy, because the time-zone statement is issued
     * here: an unreachable server or rejected credentials therefore fail while the container is being
     * built instead of inside whichever query happened to run first. On PostgreSQL the configured SSL
     * mode is passed through as `sslmode`; on MySQL and MariaDB every mode but `disable` sets the
     * driver's server-certificate check, which is asked for only under `verify-ca` and `verify-full`.
     *
     * @return  Connection  An open connection whose session time zone is UTC.
     *
     * @throws  \Doctrine\DBAL\Exception  When the parameters do not resolve to a usable driver, the
     *          server cannot be reached, or it rejects the time-zone statement.
     *
     * @since   2.0.0
     */
    public function create(): Connection
    {
        DoctrineTemporalTypes::register();
        $driver = $this->configuration->driver === 'pgsql' ? 'pdo_pgsql' : 'pdo_mysql';
        $parameters = [
            'driver' => $driver,
            'host' => $this->configuration->host,
            'port' => $this->configuration->port,
            'dbname' => $this->configuration->database,
            'user' => $this->configuration->user,
            'password' => $this->configuration->password,
            'serverVersion' => $this->configuration->serverVersion,
            'charset' => $this->configuration->driver === 'pgsql' ? 'utf8' : 'utf8mb4',
        ];

        if ($this->configuration->driver === 'pgsql') {
            $parameters['sslmode'] = $this->configuration->sslMode;
        } elseif ($this->configuration->sslMode !== 'disable') {
            $parameters['driverOptions'] = [
                Mysql::ATTR_SSL_VERIFY_SERVER_CERT => in_array(
                    $this->configuration->sslMode,
                    ['verify-ca', 'verify-full'],
                    true,
                ),
            ];
        }

        $connection = DriverManager::getConnection($parameters);
        $connection->executeStatement(
            $this->configuration->driver === 'pgsql'
                ? "SET TIME ZONE 'UTC'"
                : "SET time_zone = '+00:00'",
        );

        return $connection;
    }
}
