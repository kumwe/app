<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Command;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\IdempotencyKey;
use Kumwe\CMS\BusinessRecord\Application\RecordRequestGuard;

final readonly class ReorderRecordLinesCommand
{
    /** @var list<string> */
    public array $orderedRecordIds;

    /** @param list<string> $orderedRecordIds */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $recordId,
        public int $expectedVersion,
        public string $relationship,
        array $orderedRecordIds,
        public IdempotencyKey $idempotencyKey,
        public ?string $organizationIdentifier = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::expectedVersion($expectedVersion);
        RecordRequestGuard::handle($relationship, 'relationship');
        RecordRequestGuard::organization($organizationIdentifier);
        if (count($orderedRecordIds) > 1000 || count(array_unique($orderedRecordIds)) !== count($orderedRecordIds)) {
            throw new InvalidArgumentException('A line reorder is duplicated or exceeds 1000 entries.');
        }
        foreach ($orderedRecordIds as $orderedRecordId) {
            RecordRequestGuard::record($orderedRecordId);
        }
        $this->orderedRecordIds = array_values($orderedRecordIds);
    }
}
