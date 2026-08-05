<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use InvalidArgumentException;

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
        public string $leaseToken,
    ) {
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
                $leaseToken,
            ) !== 1
        ) {
            throw new InvalidArgumentException('A stored job requires a canonical lease fencing token.');
        }
    }
}
