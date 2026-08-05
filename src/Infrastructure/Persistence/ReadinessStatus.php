<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

interface ReadinessStatus
{
    public function ready(): bool;
}
