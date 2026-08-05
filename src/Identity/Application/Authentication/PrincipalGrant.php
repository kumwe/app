<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Authentication;

use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\GrantScope;

final readonly class PrincipalGrant
{
    public function __construct(private Capability $capability, private GrantScope $scope)
    {
    }

    public function capability(): Capability
    {
        return $this->capability;
    }

    public function scope(): GrantScope
    {
        return $this->scope;
    }
}
