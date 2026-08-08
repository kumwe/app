<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Command;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\IdempotencyKey;
use Kumwe\CMS\BusinessRecord\Application\RecordRequestGuard;

final readonly class RelateRecordsCommand
{
    /** @var array<string, mixed> */
    public array $targetValues;

    /** @param array<string, mixed> $targetValues Values are required only for an owned-line relationship. */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $recordId,
        public int $expectedVersion,
        public string $relationship,
        public string $targetRecordId,
        public IdempotencyKey $idempotencyKey,
        public ?int $position = null,
        public ?string $organizationIdentifier = null,
        array $targetValues = [],
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::record($targetRecordId);
        RecordRequestGuard::expectedVersion($expectedVersion);
        RecordRequestGuard::handle($relationship, 'relationship');
        RecordRequestGuard::organization($organizationIdentifier);
        if ($position !== null && ($position < 0 || $position > 1_000_000)) {
            throw new InvalidArgumentException('A business relationship position is outside its safe bound.');
        }
        RecordRequestGuard::values($targetValues, true);
        $this->targetValues = $targetValues;
    }
}
