<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Infrastructure\Execution;

use InvalidArgumentException;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaEnvironment;

/**
 * Business-schema environment identity taken from configuration rather than probed from the server.
 *
 * Recovery drills and schema plans are bound to the engine and the binary that produced them, and the
 * version and release checks are constant-time comparisons against these strings. Reading them from
 * validated configuration — the database driver, the server version Doctrine is told to assume, and the
 * application release — keeps that comparison independent of what any one connection or replica would
 * report, so a plan cannot be approved against one identity and executed against another. The three
 * values are checked once, here, which is why no caller re-validates them.
 *
 * @since  2.0.0
 */
final readonly class ConfiguredBusinessSchemaEnvironment implements BusinessSchemaEnvironment
{
    /**
     * Pin this process to one database engine, server version, and application release.
     *
     * @param   string  $driver         Engine in use: mariadb, mysql, or pgsql.
     * @param   string  $serverVersion  Server version the connection is configured for.
     * @param   string  $release        Release identifier of the running build.
     *
     * @throws  InvalidArgumentException  When the driver is outside the three supported engines, or the
     *          server version or release is empty, over 191 bytes, or holds control characters.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $driver,
        private string $serverVersion,
        private string $release,
    ) {
        if (!in_array($driver, ['mariadb', 'mysql', 'pgsql'], true)) {
            throw new InvalidArgumentException('The business-schema database driver is unsupported.');
        }
        foreach ([$serverVersion, $release] as $value) {
            if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('A business-schema environment identity is invalid.');
            }
        }
    }

    /**
     * Name the database engine every schema plan and drill on this deployment is bound to.
     *
     * @return  string  The configured driver: mariadb, mysql, or pgsql.
     *
     * @since   2.0.0
     */
    public function databaseDriver(): string
    {
        return $this->driver;
    }

    /**
     * Report the server version the schema decisions on this deployment were made against.
     *
     * @return  string  The configured version text, compared verbatim against recorded drill evidence.
     *
     * @since   2.0.0
     */
    public function databaseServerVersion(): string
    {
        return $this->serverVersion;
    }

    /**
     * Report the application release that is doing the schema work.
     *
     * @return  string  The configured release identifier, compared verbatim against drill evidence.
     *
     * @since   2.0.0
     */
    public function applicationRelease(): string
    {
        return $this->release;
    }
}
