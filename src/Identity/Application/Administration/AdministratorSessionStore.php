<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

use Kumwe\CMS\Application\Authorization\ExecutionContext;

interface AdministratorSessionStore
{
    public function create(ExecutionContext $context, string $userAgent): CreatedAdministratorSession;

    public function find(string $token, string $userAgent): ?AdministratorSession;

    public function delete(ExecutionContext $context, string $sessionId): void;

    public function purgeExpired(ExecutionContext $context): int;
}
