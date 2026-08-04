<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Authorization;

use DomainException;

final class InsufficientCapability extends DomainException
{
    public function __construct(public readonly string $capability)
    {
        parent::__construct(sprintf('Capability %s is required for this operation.', $capability));
    }
}
