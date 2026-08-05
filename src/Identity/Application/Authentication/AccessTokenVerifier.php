<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Authentication;

interface AccessTokenVerifier
{
    public function verify(
        string $token,
        string $audience = 'kumwe-http',
        string $purpose = 'api',
        string $siteIdentifier = 'default',
    ): ?AuthenticatedPrincipal;
}
