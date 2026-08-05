<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Install;

interface ExtensionInstallReconciler
{
    public function reconcile(): int;

    public function hasPending(): bool;
}
