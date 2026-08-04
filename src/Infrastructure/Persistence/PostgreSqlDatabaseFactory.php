<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

use Joomla\Database\DatabaseFactory;
use Joomla\Database\DatabaseInterface;
use Kumwe\CMS\Kernel\Configuration\DatabaseConfiguration;
use RuntimeException;

final readonly class PostgreSqlDatabaseFactory
{
    public function __construct(private DatabaseConfiguration $configuration)
    {
    }

    public function create(): DatabaseInterface
    {
        $sslEnabled = in_array($this->configuration->sslMode, ['require', 'verify-full'], true);
        $database = (new DatabaseFactory())->getDriver('pgsql', [
            'host' => $this->configuration->host,
            'port' => $this->configuration->port,
            'database' => $this->configuration->database,
            'user' => $this->configuration->user,
            'password' => $this->configuration->password,
            'ssl' => [
                'enable' => $sslEnabled,
                'cipher' => null,
                'ca' => null,
                'capath' => null,
                'key' => null,
                'cert' => null,
                'verify_server_cert' => $this->configuration->sslMode === 'verify-full',
            ],
        ]);

        $database->connect();
        $database->setQuery("SET TIME ZONE 'UTC'")->execute();
        $schema = $database->quoteName($this->configuration->schema);

        if (!is_string($schema)) {
            throw new RuntimeException('The database returned an invalid quoted search path.');
        }

        $database->setQuery(sprintf(
            'SET search_path TO %s, public',
            $schema,
        ))->execute();

        return $database;
    }
}
