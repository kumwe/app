<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Command;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\IdempotencyKey;
use Kumwe\CMS\BusinessRecord\Application\RecordRequestGuard;

final readonly class UpdateRecordCommand
{
    /** @var array<string, mixed> */
    public array $values;

    /** @param array<string, mixed> $values */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $recordId,
        public int $expectedVersion,
        array $values,
        public IdempotencyKey $idempotencyKey,
        public ?string $organizationIdentifier = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::expectedVersion($expectedVersion);
        RecordRequestGuard::organization($organizationIdentifier);
        RecordRequestGuard::values($values);
        $this->values = $values;
    }
}
