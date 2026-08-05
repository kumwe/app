<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

final readonly class TokenDelegation
{
    /** @param non-empty-list<string> $capabilities */
    public function __construct(public string $subjectId, public array $capabilities)
    {
    }
}
