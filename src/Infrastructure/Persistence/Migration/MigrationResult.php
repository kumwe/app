<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

final readonly class MigrationResult
{
    /**
     * @param list<string> $applied
     */
    public function __construct(public array $applied)
    {
    }

    public function changed(): bool
    {
        return $this->applied !== [];
    }
}
