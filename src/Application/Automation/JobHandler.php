<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use Kumwe\CMS\Application\Authorization\ExecutionContext;

interface JobHandler
{
    public function type(): string;

    /** @param array<string, mixed> $payload */
    public function handle(array $payload, ExecutionContext $context): void;
}
