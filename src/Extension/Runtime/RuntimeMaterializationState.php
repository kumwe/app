<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

final readonly class RuntimeMaterializationState
{
    public function __construct(
        public string $replicaId,
        public int $generation,
        public string $publicationChecksum,
        public string $trustHmac,
        public bool $trusted,
        public ?VerifiedRuntimePublication $publication = null,
    ) {
    }

    public static function unavailable(string $replicaId): self
    {
        return new self($replicaId, -1, '', '', false);
    }
}
