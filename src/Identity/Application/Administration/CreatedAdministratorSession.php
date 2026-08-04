<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

final readonly class CreatedAdministratorSession
{
    public function __construct(public string $token, public AdministratorSession $session)
    {
    }
}
