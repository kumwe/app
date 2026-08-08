<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Command;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\IdempotencyKey;
use Kumwe\CMS\BusinessRecord\Application\RecordRequestGuard;

final readonly class CreateRecordCommand
{
    /** @var array<string, mixed> */
    public array $values;

    /** @param array<string, mixed> $values */
    public function __construct(
        public ExecutionContext $context,
        public string $definitionIdentifier,
        array $values,
        public IdempotencyKey $idempotencyKey,
        public ?string $organizationIdentifier = null,
        public ?string $recordId = null,
    ) {
        RecordRequestGuard::definition($definitionIdentifier);
        RecordRequestGuard::organization($organizationIdentifier);
        RecordRequestGuard::values($values);
        if ($recordId !== null) {
            RecordRequestGuard::record($recordId);
        }
        $this->values = $values;
    }
}
