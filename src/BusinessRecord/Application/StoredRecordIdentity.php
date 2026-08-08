<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final readonly class StoredRecordIdentity
{
    public function __construct(
        public string $recordKey,
        public string $recordId,
        public int $definitionVersion,
        public int $version,
    ) {
        RecordRequestGuard::record($recordId);
        if (!Uuid::isValid($recordKey)) {
            throw new InvalidArgumentException('A stored business-record key must be a canonical UUID.');
        }
        if ($definitionVersion < 1 || $version < 1) {
            throw new InvalidArgumentException('Stored business-record versions must be positive.');
        }
    }
}
