<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;

final readonly class MigrationRunner
{
    public function __construct(
        private Connection $database,
        private MigrationRepository $repository,
        private MigrationLock $lock,
        private TransactionManager $transactions,
        private MigrationPlan $plan,
        private AuthorizationGateway $authorization,
        private NonTransactionalMigrationRecovery $nonTransactionalRecovery,
    ) {
    }

    public function migrate(ExecutionContext $context): MigrationResult
    {
        $this->authorize($context);
        return $this->lock->synchronized(function (): MigrationResult {
            $this->repository->ensureLedger();
            $applied = $this->repository->applied();
            $this->nonTransactionalRecovery->assertKnownAttempts($this->plan->ids());
            $this->plan->assertCompatible($applied);
            $completed = [];

            foreach ($this->plan->all() as $migration) {
                $id = $migration->id();
                $checksum = $migration->checksum();

                if (isset($applied[$id])) {
                    if ($this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                        $this->nonTransactionalRecovery->reconcileApplied($migration);
                    }

                    continue;
                }

                $started = hrtime(true);
                $operation = function () use ($migration, $id, $checksum, $started): void {
                    $migration->up($this->database);
                    $elapsed = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
                    $this->repository->record($id, $checksum, $elapsed);
                };

                $platform = $this->database->getDatabasePlatform();
                if ($platform instanceof PostgreSQLPlatform) {
                    $this->transactions->transactional($operation);
                } elseif ($platform instanceof AbstractMySQLPlatform) {
                    $action = $this->nonTransactionalRecovery->prepare($migration);
                    if ($action === NonTransactionalMigrationAction::Execute) {
                        $migration->up($this->database);
                    }
                    $elapsed = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
                    $this->repository->record($id, $checksum, $elapsed);
                    $this->nonTransactionalRecovery->complete($migration);
                } else {
                    $operation();
                }
                $completed[] = $id;
            }

            return new MigrationResult($completed);
        });
    }

    /**
     * @return list<Migration>
     */
    public function pending(ExecutionContext $context): array
    {
        $this->authorize($context);
        $this->repository->ensureLedger();
        $this->nonTransactionalRecovery->assertKnownAttempts($this->plan->ids());

        return $this->plan->pending($this->repository->applied());
    }

    private function authorize(ExecutionContext $context): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('system.migrate'),
            AuthorizationResource::collection('database_schema'),
        );
    }
}
