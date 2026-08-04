<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

final readonly class StoredJob
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $queue,
        public string $type,
        public array $payload,
        public int $schemaVersion,
        public int $attempts,
        public int $maximumAttempts,
    ) {
    }
}
