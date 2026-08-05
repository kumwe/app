<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

interface TrustRuntimeInvalidator
{
    /** Records authoritative runtime invalidation inside the caller's transaction. */
    public function advance(string $reason, ?string $extensionIdentifier = null): int;

    /** Materializes the authoritative generation locally after commit. */
    public function materialize(): int;

    /** Discards only replica-local state so it can be rebuilt from authoritative storage. */
    public function discardLocal(): void;
}
