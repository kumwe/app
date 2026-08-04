<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Joomla\Database\DatabaseInterface;

final readonly class Version202608040001CreateSystemTables implements Migration
{
    private const ID = '20260804000100_create_system_tables';

    public function __construct(private string $schema)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function checksum(): string
    {
        return hash('sha256', self::ID . ':' . $this->schema . ':' . $this->sqlTemplate());
    }

    public function up(DatabaseInterface $database): void
    {
        $database->setQuery(sprintf(
            $this->sqlTemplate(),
            $database->quoteName($this->schema),
            $database->quoteName('system_settings'),
        ))->execute();
    }

    private function sqlTemplate(): string
    {
        return 'CREATE TABLE %s.%s ('
            . 'setting_key varchar(191) PRIMARY KEY, '
            . 'setting_value jsonb NOT NULL, '
            . 'is_secret boolean NOT NULL DEFAULT false, '
            . 'version integer NOT NULL DEFAULT 1 CHECK (version > 0), '
            . 'created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')';
    }
}
