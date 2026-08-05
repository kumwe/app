<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation\Job;

use Kumwe\CMS\Application\Automation\IdempotencyPurger;
use Kumwe\CMS\Application\Automation\JobHandler;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Domain\Capability;

final readonly class PurgeIdempotencyRecordsHandler implements JobHandler
{
    public function __construct(
        private IdempotencyPurger $records,
        private AuthorizationGateway $authorization,
    ) {
    }

    public function type(): string
    {
        return 'system.idempotency.purge';
    }

    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('automation.manage'),
            AuthorizationResource::item('automation_installation', $this->type()),
        );
        $batchSize = $payload['batch_size'] ?? 1_000;
        $maximumBatches = $payload['maximum_batches'] ?? 10;
        if (!is_int($batchSize) || !is_int($maximumBatches) || $maximumBatches < 1 || $maximumBatches > 100) {
            throw new \InvalidArgumentException('Idempotency purge limits are invalid.');
        }
        for ($batch = 0; $batch < $maximumBatches; $batch++) {
            if ($this->records->purgeExpired($batchSize) < $batchSize) {
                return;
            }
        }
    }
}
