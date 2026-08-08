<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\CMS\Shared\Domain\DatabaseTablePrefix;

final readonly class TableNames
{
    public function __construct(private Connection $connection, private string $prefix)
    {
        if (!DatabaseTablePrefix::isValid($prefix)) {
            throw new InvalidArgumentException('The database table prefix is invalid.');
        }
    }

    /** @return non-empty-string */
    public function raw(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name) !== 1) {
            throw new InvalidArgumentException('The database table name is invalid.');
        }

        $physicalName = $this->prefix . $name;
        if (strlen($physicalName) > 63) {
            throw new InvalidArgumentException('The prefixed database table name exceeds the portable 63-byte limit.');
        }

        return $physicalName;
    }

    /** @return non-empty-string */
    public function quoted(string $name): string
    {
        /** @var non-empty-string $quoted */
        $quoted = $this->connection->quoteSingleIdentifier($this->raw($name));

        return $quoted;
    }
}
