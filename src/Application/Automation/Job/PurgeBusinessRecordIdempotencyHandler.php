<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation\Job;

use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\JobHandler;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordIdempotencyPurger;
use Kumwe\CMS\Identity\Domain\Capability;

/**
 * Bounded retention driver for the business-record command idempotency ledger.
 *
 * The ledger is written by every typed record mutation and is otherwise append-only,
 * so an installation-global schedule owns its expiry. Batching keeps each transaction
 * short enough to avoid blocking concurrent record traffic.
 */
final readonly class PurgeBusinessRecordIdempotencyHandler implements JobHandler
{
    private const MAXIMUM_BATCH_SIZE = 1000;

    public function __construct(
        private BusinessRecordIdempotencyPurger $records,
        private AuthorizationGateway $authorization,
    ) {
    }

    public function type(): string
    {
        return 'business.record.idempotency.purge';
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('automation.manage'),
            AuthorizationResource::item('automation_installation', $this->type()),
        );
        $batchSize = $payload['batch_size'] ?? 500;
        $maximumBatches = $payload['maximum_batches'] ?? 10;
        if (
            !is_int($batchSize)
            || !is_int($maximumBatches)
            || $batchSize < 1
            || $batchSize > self::MAXIMUM_BATCH_SIZE
            || $maximumBatches < 1
            || $maximumBatches > 100
        ) {
            throw new InvalidArgumentException('Business-record idempotency purge limits are invalid.');
        }
        for ($batch = 0; $batch < $maximumBatches; $batch++) {
            if ($this->records->purge($batchSize) < $batchSize) {
                return;
            }
        }
    }
}
