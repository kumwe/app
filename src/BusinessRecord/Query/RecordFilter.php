<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

interface RecordFilter
{
    /** @return array<string, mixed> */
    public function toArray(): array;

    public function operationCount(): int;

    public function depth(): int;

    public function relationDepth(): int;
}
