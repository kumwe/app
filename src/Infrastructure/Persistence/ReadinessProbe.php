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

/**
 * Readiness verdict recomputed against the live database and every dependency this install wired.
 *
 * This is the thorough end of `ReadinessStatus`, and deliberately not what `/health/ready` serves: it
 * spends real queries on every call, so it suits a deployment gate, a container start-up probe, or the
 * `app:health` command, while the continuously polled endpoint answers from `LocalRuntimeReadinessProbe`
 * instead. It refuses traffic for a database that is unreachable, has no migration ledger, is behind or
 * ahead of the migration set this build ships, or still carries an unresolved recovery attempt from a
 * crashed non-transactional migration — so a half-migrated schema is never served from. Redis, the
 * extension trust store and the compiled runtime map are optional constructor arguments and are simply
 * skipped where an installation does not wire them.
 *
 * @since  2.0.0
 */
final readonly class ReadinessProbe implements ReadinessStatus
{
    /**
     * Wire the dependencies whose combined health decides readiness.
     *
     * @param  Connection                         $database         Connection probed with a trivial query and then
     *         searched for the migration ledger table.
     * @param  LoggerInterface                    $logger           Sink for the warning recorded when a check raises
     *         instead of answering.
     * @param  TableNames                         $tables           Resolves the prefixed physical name of the
     *         `schema_migrations` ledger table.
     * @param  MigrationRepository                $migrations       Reads the ledger of migrations this database has
     *         already applied.
     * @param  MigrationPlan                      $plan             Migration set this build ships, compared against
     *         the applied ledger.
     * @param  NonTransactionalMigrationRecovery  $recovery         Reports migration attempts left unresolved by a
     *         crash on a platform whose DDL commits implicitly.
     * @param  RedisRuntime|null                  $redis            Redis binding to ping, or null when this
     *         installation runs without Redis.
     * @param  TrustStore|null                    $trust            Extension trust boundary to re-check, or null when
     *         extension support is not wired.
     * @param  ExtensionRuntimeMapCompiler|null   $runtime          Compiler asked whether the loaded runtime
     *         generation is still authoritative, or null to leave the runtime out of the verdict.
     * @param  RuntimeMaterializationState|null   $materialization  Generation this process loaded; a compiler
     *         supplied without it never reads as ready.
     *
     * @since  2.0.0
     */
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

    /**
     * Re-check every wired dependency and report whether this process is fit to serve.
     *
     * The checks run in a fixed order and stop at the first refusal: a trivial query that both opens the
     * lazy connection and proves it usable, then Redis and the trust store where they are wired, then the
     * presence of the migration ledger, the plan being fully and compatibly applied, the absence of an
     * unresolved non-transactional recovery attempt, and finally whether the runtime generation this
     * process loaded is still authoritative. Every throwable raised along the way is caught, logged at
     * warning level with the exception attached, and answered as not-ready, so an unreachable dependency
     * drains this worker rather than surfacing to the caller as an error.
     *
     * @return  bool  True only when every wired dependency checks out; false on the first that does not.
     *
     * @since   2.0.0
     */
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
