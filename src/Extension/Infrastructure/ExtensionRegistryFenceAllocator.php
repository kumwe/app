<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Issues the monotonically increasing token that makes a stale extension registry lease harmless.
 *
 * The cross-process `extension-registry` Redis lease can be lost — expired under load, or dropped when
 * a replica stalls — while its holder carries on believing it still owns the registry. Every lease is
 * therefore paired with a fence drawn from a single database row: taking a lease bumps that row, and
 * `DoctrineExtensionManager` re-reads it at each step of a registry mutation, so an operation still
 * holding an older fence is refused rather than overwriting the newer holder's work. `RedisLockedExtensionManager`
 * and `ConsoleAdministratorThemeRecovery` allocate one for every lease they hand out.
 *
 * @since  2.0.0
 */
final readonly class ExtensionRegistryFenceAllocator
{
    /**
     * Bind the allocator to the registry fence row and the clock that stamps it.
     *
     * @param  Connection      $database  Connection the fence row is read and updated on, in a
     *         transaction of its own.
     * @param  TableNames      $tables    Resolver for the prefixed `extension_registry_fence` table name.
     * @param  ClockInterface  $clock     Source of the `updated_at` stamp written with each allocation.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Reserve the next fence value for a caller that is about to take a registry lease.
     *
     * The read and the write share one transaction. Every platform except SQLite selects the singleton
     * row `FOR UPDATE`, and the update is a compare-and-set against the value just read, so two
     * allocators racing for the registry cannot walk away holding the same fence.
     *
     * @return  int  The freshly reserved fence, one higher than the value stored before the call.
     *
     * @throws  RuntimeException  When the singleton fence row is missing or does not hold a
     *          non-negative integer, or when another allocation changed it between the read and the update.
     *
     * @since   2.0.0
     */
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
