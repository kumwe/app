<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\CMS\Infrastructure\Persistence\Type\DoctrineTemporalTypes;
use Kumwe\CMS\Kernel\Configuration\DatabaseConfiguration;
use Pdo\Mysql;

final readonly class DoctrineConnectionFactory
{
    public function __construct(private DatabaseConfiguration $configuration)
    {
    }

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
