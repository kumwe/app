<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

use DateTimeImmutable;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;

final readonly class AdministratorSession
{
    public const REQUEST_ATTRIBUTE = self::class;

    public function __construct(
        public string $id,
        public AuthenticatedPrincipal $principal,
        public string $csrfToken,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
