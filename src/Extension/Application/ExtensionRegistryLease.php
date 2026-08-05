<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application;

interface ExtensionRegistryLease
{
    public function fence(): int;

    public function renew(): void;
}
