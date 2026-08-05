<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractSQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use RuntimeException;

final readonly class ExtensionRegistryFenceAllocator
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
    ) {
    }

    public function allocate(): int
    {
        return $this->database->transactional(function (): int {
            $suffix = $this->database->getDatabasePlatform() instanceof AbstractSQLitePlatform ? '' : ' FOR UPDATE';
            $current = $this->database->fetchOne(sprintf(
                'SELECT fence FROM %s WHERE singleton_key = 1%s',
                $this->tables->quoted('extension_registry_fence'),
                $suffix,
            ));
            if (!is_numeric($current) || (int) $current < 0) {
                throw new RuntimeException('The extension registry database fence is invalid.');
            }
            $next = (int) $current + 1;
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET fence = ?, updated_at = ? WHERE singleton_key = 1 AND fence = ?',
                $this->tables->quoted('extension_registry_fence'),
            ), [$next, $this->clock->now(), (int) $current], [
                Types::BIGINT, Types::DATETIME_IMMUTABLE, Types::BIGINT,
            ]);
            if ($affected !== 1) {
                throw new RuntimeException('The extension registry database fence changed concurrently.');
            }

            return $next;
        });
    }
}
