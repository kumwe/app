<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use DateTimeImmutable;

interface BusinessSchemaLifecycleObserver
{
    /** Retains all tables and data while reconciling installed runtime availability. */
    public function setOwnerActive(string $ownerIdentifier, bool $active, DateTimeImmutable $at): void;
}
