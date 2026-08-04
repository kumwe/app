<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

interface MigrationRepository
{
    public function ensureLedger(): void;

    /**
     * @return array<string, string> Map of migration ID to checksum.
     */
    public function applied(): array;

    public function record(string $id, string $checksum, int $executionMilliseconds): void;
}
