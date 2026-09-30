<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\Kernel\Configuration\DatabaseConfiguration;
use Throwable;

/**
 * Opens an administrative connection able to create and drop database principals for a security test.
 *
 * The runtime connection is used when it is a PostgreSQL superuser. Otherwise the account comes from
 * `KUMWE_TEST_DB_ADMIN_USER`/`KUMWE_TEST_DB_ADMIN_PASSWORD`, defaulting to `root` without a password, which
 * the CI database containers provide. Outside CI an unavailable account yields null so the caller can skip;
 * in CI (`CI` set) the failure propagates, because the adversarial evidence is required there.
 */
final class DatabaseAdministrator
{
    /**
     * Open the administrative connection.
     *
     * @param   Connection             $runtime   Integration runtime connection.
     * @param   DatabaseConfiguration  $settings  Integration database settings.
     *
     * @return  ?Connection  Administrative connection, or null outside CI when none is available.
     *
     * @throws  Throwable  In CI, when no administrative connection can be opened.
     */
    public static function open(Connection $runtime, DatabaseConfiguration $settings): ?Connection
    {
        $user = getenv('KUMWE_TEST_DB_ADMIN_USER');
        if (
            $user === false
            && $runtime->getDatabasePlatform() instanceof PostgreSQLPlatform
            && in_array(
                $runtime->fetchOne('SELECT rolsuper FROM pg_roles WHERE rolname = session_user'),
                [true, 't', 1, '1'],
                true,
            )
        ) {
            return $runtime;
        }
        $password = getenv('KUMWE_TEST_DB_ADMIN_PASSWORD');
        try {
            $connection = DriverManager::getConnection([
                'driver' => $settings->driver === 'pgsql' ? 'pdo_pgsql' : 'pdo_mysql',
                'host' => $settings->host,
                'port' => $settings->port,
                'dbname' => $settings->database,
                'user' => is_string($user) && $user !== '' ? $user : 'root',
                'password' => is_string($password) ? $password : '',
                'serverVersion' => $settings->serverVersion,
            ]);
            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (Throwable $unavailable) {
            if (getenv('CI') !== false) {
                throw $unavailable;
            }

            return null;
        }
    }
}
