<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DateTimeImmutable;

final readonly class RetryDecision
{
    public function __construct(
        public FailureClassification $classification,
        public bool $shouldRetry,
        public int $delaySeconds,
        public ?DateTimeImmutable $retryAt,
    ) {
    }
}
