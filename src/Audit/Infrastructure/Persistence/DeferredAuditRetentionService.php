<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Infrastructure\Persistence;

use Closure;
use Kumwe\App\Audit\Application\AuditRetentionResult;
use Kumwe\App\Audit\Application\AuditRetentionService;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Opens the separately credentialed audit retention connection only when a retention pass actually runs.
 *
 * The retention principal's connection is distinct from the runtime connection by design. Building it
 * when the container composes the retention drain would make every drain, and every process that merely
 * composes one, depend on a credential only audit retention uses; a wrong retention credential must
 * fail the retention pass, not unrelated work.
 *
 * @since  2.0.0
 */
final class DeferredAuditRetentionService implements AuditRetentionService
{
    /**
     * Retention service built on first use.
     *
     * @var    ?AuditRetentionService
     * @since  2.0.0
     */
    private ?AuditRetentionService $service = null;

    /**
     * Bind the factory that opens the retention connection and composes the service over it.
     *
     * @param  Closure(): AuditRetentionService  $factory  Builds the retention service on first use.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly Closure $factory)
    {
    }

    /**
     * Archive and prune every anchored audit row older than the retention window.
     *
     * @param   ExecutionContext  $context        Actor the pass is authorized and audited under.
     * @param   int               $retentionDays  Window in days; rows older than this become prunable.
     *
     * @return  AuditRetentionResult  What was archived and pruned, with its ledger and archive evidence.
     *
     * @throws  \InvalidArgumentException  When the window is not a positive number of days.
     * @throws  \RuntimeException  When archiving or the guarded delete cannot complete safely.
     * @throws  \Doctrine\DBAL\Exception  When the retention connection cannot be opened.
     * @throws  \Kumwe\Access\AuthorizationDenied  When the actor may not manage the audit trail.
     *
     * @since   2.0.0
     */
    public function prune(ExecutionContext $context, int $retentionDays): AuditRetentionResult
    {
        $this->service ??= ($this->factory)();

        return $this->service->prune($context, $retentionDays);
    }
}
