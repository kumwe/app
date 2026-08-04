<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Install;

use Kumwe\CMS\Extension\Domain\ExtensionManifest;

interface ExtensionLifecycle
{
    /** Stage files outside the public extension tree without executing package code. */
    public function stage(AtomicInstallPlan $plan, ExtensionManifest $manifest, string $archiveFile): void;

    /** Activate staged files and compiled maps as the transaction's final visible operation. */
    public function activate(AtomicInstallPlan $plan): void;

    /** Compensate database, files, and generated maps after any failed operation. */
    public function rollback(AtomicInstallPlan $plan): void;
}
