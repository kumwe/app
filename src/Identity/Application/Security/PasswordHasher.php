<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Security;

interface PasswordHasher
{
    public function hash(#[\SensitiveParameter] string $plainTextPassword): string;

    public function verify(#[\SensitiveParameter] string $plainTextPassword, string $passwordHash): bool;

    public function needsRehash(string $passwordHash): bool;
}
