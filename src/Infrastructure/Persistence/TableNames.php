<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

final readonly class TableNames
{
    public function __construct(private Connection $connection, private string $prefix)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,30}$/D', $prefix) !== 1) {
            throw new InvalidArgumentException('The database table prefix is invalid.');
        }
    }

    /** @return non-empty-string */
    public function raw(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name) !== 1) {
            throw new InvalidArgumentException('The database table name is invalid.');
        }

        return $this->prefix . $name;
    }

    /** @return non-empty-string */
    public function quoted(string $name): string
    {
        return $this->connection->quoteSingleIdentifier($this->raw($name));
    }
}
