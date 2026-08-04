<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;

interface AdministratorSessionStore
{
    public function create(AuthenticatedPrincipal $principal, string $userAgent): CreatedAdministratorSession;

    public function find(string $token, string $userAgent): ?AdministratorSession;

    public function delete(string $sessionId): void;

    public function purgeExpired(): int;
}
