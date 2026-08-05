<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationPlan;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationRepository;
use Kumwe\CMS\Infrastructure\Persistence\Migration\NonTransactionalMigrationRecovery;
use Kumwe\CMS\Infrastructure\Redis\RedisRuntime;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ReadinessProbe implements ReadinessStatus
{
    public function __construct(
        private Connection $database,
        private LoggerInterface $logger,
        private TableNames $tables,
        private MigrationRepository $migrations,
        private MigrationPlan $plan,
        private NonTransactionalMigrationRecovery $recovery,
        private ?RedisRuntime $redis = null,
        private ?TrustStore $trust = null,
        private ?ExtensionRuntimeMapCompiler $runtime = null,
        private ?RuntimeMaterializationState $materialization = null,
    ) {
    }

    public function ready(): bool
    {
        try {
            // DBAL 4 keeps Connection::connect() internal. A trivial query both
            // establishes the lazy connection and verifies that it is usable.
            $this->database->fetchOne('SELECT 1');

            if ($this->redis !== null && !$this->redis->ready()) {
                return false;
            }
            if ($this->trust !== null && !$this->trust->ready()) {
                return false;
            }

            if (
                !$this->database->createSchemaManager()->tablesExist([
                    $this->tables->raw('schema_migrations'),
                ])
            ) {
                return false;
            }

            if (!$this->plan->complete($this->migrations->applied())) {
                return false;
            }
            if ($this->recovery->hasUnresolvedAttempts()) {
                return false;
            }

            return $this->runtime === null
                || ($this->materialization !== null && $this->runtime->isCurrent($this->materialization));
        } catch (Throwable $exception) {
            $this->logger->warning('Readiness probe failed.', ['exception' => $exception]);

            return false;
        }
    }
}
