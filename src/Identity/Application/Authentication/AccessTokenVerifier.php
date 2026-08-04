<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Authentication;

interface AccessTokenVerifier
{
    public function verify(string $token): ?AuthenticatedPrincipal;
}
