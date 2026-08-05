<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

/** @param non-empty-list<string> $capabilities */
final readonly class TokenRotation
{
    public function __construct(
        public string $subjectId,
        public string $email,
        public array $capabilities,
        public string $siteIdentifier,
        public string $audience,
        public string $purpose,
    ) {
    }
}
