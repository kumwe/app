<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Query;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessRecord\Application\RecordRequestGuard;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;

final readonly class BrowseRecordsQuery
{
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        public RecordQuerySpecification $specification,
        public ?string $organizationIdentifier = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::organization($organizationIdentifier);
    }
}
