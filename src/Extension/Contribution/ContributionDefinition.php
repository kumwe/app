<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

interface ContributionDefinition
{
    public function identifier(): string;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
