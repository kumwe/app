<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Security;

final readonly class ForwardedRequest
{
    public function __construct(
        public string $clientAddress,
        public ?string $scheme,
        public ?string $host,
        public ?int $port,
        public bool $authoritySupplied,
    ) {
    }
}
