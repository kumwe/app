<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Query;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessRecord\Application\RecordRequestGuard;

final readonly class RecordHistoryQuery
{
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $recordId,
        public ?string $organizationIdentifier = null,
        public int $limit = 100,
        public ?int $beforeVersion = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::organization($organizationIdentifier);
        if ($limit < 1 || $limit > 200 || ($beforeVersion !== null && $beforeVersion < 1)) {
            throw new InvalidArgumentException('A record-history window is outside its safe bound.');
        }
    }
}
