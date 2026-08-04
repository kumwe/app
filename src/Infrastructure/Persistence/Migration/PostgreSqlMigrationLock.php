<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Joomla\Database\DatabaseInterface;
use RuntimeException;

final readonly class PostgreSqlMigrationLock implements MigrationLock
{
    private const LOCK_NAME = 'kumwe:core:migrations';

    public function __construct(private DatabaseInterface $database)
    {
    }

    public function synchronized(callable $operation): mixed
    {
        $lockName = $this->database->quote(self::LOCK_NAME);

        if (!is_string($lockName)) {
            throw new RuntimeException('The database returned an invalid quoted lock name.');
        }

        $this->database->setQuery(sprintf('SELECT pg_advisory_lock(hashtextextended(%s, 0))', $lockName))->execute();

        try {
            return $operation();
        } finally {
            $unlock = sprintf('SELECT pg_advisory_unlock(hashtextextended(%s, 0))', $lockName);
            $this->database->setQuery($unlock)->execute();
        }
    }
}
