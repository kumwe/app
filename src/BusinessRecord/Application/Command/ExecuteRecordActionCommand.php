<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Command;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\IdempotencyKey;
use Kumwe\CMS\BusinessRecord\Application\RecordRequestGuard;

final readonly class ExecuteRecordActionCommand
{
    /** @var array<string, mixed> */
    public array $input;

    /** @param array<string, mixed> $input */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $recordId,
        public int $expectedVersion,
        public string $action,
        public IdempotencyKey $idempotencyKey,
        array $input = [],
        public ?string $organizationIdentifier = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::expectedVersion($expectedVersion);
        RecordRequestGuard::handle($action, 'action');
        RecordRequestGuard::organization($organizationIdentifier);
        RecordRequestGuard::values($input, true);
        $this->input = $input;
    }
}
