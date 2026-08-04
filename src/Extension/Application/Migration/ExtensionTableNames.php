<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Migration;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

final readonly class ExtensionTableNames
{
    private string $namespace;

    public function __construct(
        private Connection $database,
        private TableNames $tables,
        ExtensionIdentifier $extension,
    ) {
        $this->namespace = str_replace(['/', '.', '-'], '_', $extension->value());
    }

    public function raw(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name) !== 1) {
            throw new InvalidArgumentException('An extension table name must be a safe lowercase identifier.');
        }

        return $this->tables->raw('ext_' . $this->namespace . '_' . $name);
    }

    public function quoted(string $name): string
    {
        return $this->database->quoteIdentifier($this->raw($name));
    }
}
