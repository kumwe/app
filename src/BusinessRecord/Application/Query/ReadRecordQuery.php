<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Query;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessRecord\Application\RecordRequestGuard;

final readonly class ReadRecordQuery
{
    /** @var list<string> */
    public array $projection;

    /** @param list<string> $projection */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public string $recordId,
        public ?string $organizationIdentifier = null,
        array $projection = [],
        public bool $includeArchived = false,
        public bool $includeDeleted = false,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::record($recordId);
        RecordRequestGuard::organization($organizationIdentifier);
        if (count($projection) > 64) {
            throw new InvalidArgumentException('A business-record read projection exceeds 64 fields.');
        }
        foreach ($projection as $field) {
            RecordRequestGuard::handle($field, 'projection');
        }
        $this->projection = array_values(array_unique($projection));
    }
}
