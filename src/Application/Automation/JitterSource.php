<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

interface JitterSource
{
    public function between(int $minimum, int $maximum): int;
}
