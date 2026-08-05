<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

interface IdempotencyPurger
{
    public function purgeExpired(int $batchSize = 1_000): int;
}
