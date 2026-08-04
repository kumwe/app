<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

interface JobHandler
{
    public function type(): string;

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void;
}
