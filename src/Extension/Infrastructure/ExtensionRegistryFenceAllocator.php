<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
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
            $suffix = $this->database->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
            $current = $this->database->fetchOne(sprintf(
                'SELECT fence FROM %s WHERE singleton_key = 1%s',
                $this->tables->quoted('extension_registry_fence'),
                $suffix,
            ));
            if (!is_int($current) && (!is_string($current) || preg_match('/^[0-9]+$/D', $current) !== 1)) {
                throw new RuntimeException('The extension registry database fence is invalid.');
            }
            $currentFence = (int) $current;
            $next = $currentFence + 1;
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET fence = ?, updated_at = ? WHERE singleton_key = 1 AND fence = ?',
                $this->tables->quoted('extension_registry_fence'),
            ), [$next, $this->clock->now(), $currentFence], [
                Types::BIGINT, Types::DATETIME_IMMUTABLE, Types::BIGINT,
            ]);
            if ($affected !== 1) {
                throw new RuntimeException('The extension registry database fence changed concurrently.');
            }

            return $next;
        });
    }
}
