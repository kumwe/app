<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use Kumwe\CMS\Application\Authorization\SystemIdentity;
use LogicException;

/** Classifies built-in jobs whose effect is installation-wide rather than site-local. */
final readonly class JobExecutionScope
{
    /** @var array<string, SystemIdentity> */
    private const INSTALLATION_GLOBAL = [
        'extensions.runtime.rebuild' => SystemIdentity::ExtensionMaterializer,
        'system.idempotency.purge' => SystemIdentity::InstallationMaintenance,
    ];

    public function isInstallationGlobal(string $jobType): bool
    {
        return isset(self::INSTALLATION_GLOBAL[$jobType]);
    }

    public function executionClass(string $jobType): JobExecutionClass
    {
        return $this->isInstallationGlobal($jobType)
            ? JobExecutionClass::Installation
            : JobExecutionClass::Site;
    }

    public function systemIdentity(string $jobType): SystemIdentity
    {
        return self::INSTALLATION_GLOBAL[$jobType]
            ?? throw new LogicException(sprintf('Job type "%s" is not installation-global.', $jobType));
    }

    public function assertStoredClass(string $jobType, string $stored): JobExecutionClass
    {
        $executionClass = JobExecutionClass::tryFrom($stored)
            ?? throw new LogicException('A persisted job execution class is invalid.');
        if ($executionClass !== $this->executionClass($jobType)) {
            throw new LogicException(sprintf(
                'Persisted execution class for job type "%s" does not match its declaration.',
                $jobType,
            ));
        }

        return $executionClass;
    }
}
