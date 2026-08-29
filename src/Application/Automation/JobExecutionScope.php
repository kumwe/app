<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\JobContributionDefinition;
use LogicException;

/**
 * Classifies built-in jobs whose effect is installation-wide rather than site-local.
 *
 * The table below is the only declaration of that classification. The queue and scheduler stamp the
 * derived class onto every row they write and re-check it on every row they read, so a rewritten
 * `execution_scope` column cannot promote site-owned work into installation-global work. For the
 * global types the same table also names the internal identity permitted to execute them.
 *
 * @since  2.0.0
 */
final class JobExecutionScope
{
    /**
     * Installation-global job types, each mapped to the internal identity allowed to run it.
     *
     * @var    array<string, SystemIdentity>
     * @since  2.0.0
     */
    private const INSTALLATION_GLOBAL = [
        'audit.anchor.record' => SystemIdentity::InstallationMaintenance,
        'audit.retention.enforce' => SystemIdentity::InstallationMaintenance,
        'audit.trail.verify' => SystemIdentity::InstallationMaintenance,
        'business.record.idempotency.purge' => SystemIdentity::InstallationMaintenance,
        'extensions.runtime.rebuild' => SystemIdentity::ExtensionMaterializer,
        'extensions.trust.revocations.synchronize' => SystemIdentity::ExtensionMaterializer,
        'studio.content-authoring-context.purge' => SystemIdentity::InstallationMaintenance,
        'system.idempotency.purge' => SystemIdentity::InstallationMaintenance,
    ];

    /**
     * Job types permitted to execute without a site scope.
     *
     * @var    array<string, SystemIdentity>  Trusted active global job declarations.
     * @since  2.0.0
     */
    private array $installationGlobal;

    /**
     * Compile extension-owned installation-wide jobs into the same execution-scope authority table.
     *
     * @param  iterable<JobContributionDefinition>  $contributedJobs  Active signed job definitions.
     *
     * @since  2.0.0
     */
    public function __construct(iterable $contributedJobs = [])
    {
        $this->replace($contributedJobs);
    }

    /**
     * Replace extension classifications after the signed contribution phase completes.
     *
     * @param   iterable<JobContributionDefinition>  $contributedJobs  Complete active declaration set.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function replace(iterable $contributedJobs): void
    {
        $this->installationGlobal = self::INSTALLATION_GLOBAL;
        foreach ($contributedJobs as $job) {
            if ($job->installationWide()) {
                $this->installationGlobal[$job->identifier()] = SystemIdentity::InstallationMaintenance;
            }
        }
        ksort($this->installationGlobal, SORT_STRING);
    }

    /**
     * Report whether a job type is declared installation-global.
     *
     * @param   string  $jobType  Registered job type name, as declared by its handler.
     *
     * @return  bool  True when the type is one of the installation-global built-ins.
     *
     * @since   2.0.0
     */
    public function isInstallationGlobal(string $jobType): bool
    {
        return isset($this->installationGlobal[$jobType]);
    }

    /**
     * Derive the execution class a job type must be stored and executed under.
     *
     * @param   string  $jobType  Registered job type name, as declared by its handler.
     *
     * @return  JobExecutionClass  Installation for the declared global types, Site for every other type.
     *
     * @since   2.0.0
     */
    public function executionClass(string $jobType): JobExecutionClass
    {
        return $this->isInstallationGlobal($jobType)
            ? JobExecutionClass::Installation
            : JobExecutionClass::Site;
    }

    /**
     * Name the internal identity that may execute an installation-global job type.
     *
     * @param   string  $jobType  Registered job type name, which must be declared installation-global.
     *
     * @return  SystemIdentity  Identity the worker builds the job's execution context from.
     *
     * @throws  LogicException  When the job type is not declared installation-global.
     *
     * @since   2.0.0
     */
    public function systemIdentity(string $jobType): SystemIdentity
    {
        return $this->installationGlobal[$jobType]
            ?? throw new LogicException(sprintf('Job type "%s" is not installation-global.', $jobType));
    }

    /**
     * Check the execution class read back from storage against the one the job type declares.
     *
     * Every reader of a persisted schedule or job row runs this before acting on it, so a stale or
     * tampered `execution_scope` value is rejected instead of granting the row the claim path and
     * principal of the other class.
     *
     * @param   string  $jobType  Registered job type name taken from the same row.
     * @param   string  $stored   Persisted execution class as its backing string.
     *
     * @return  JobExecutionClass  The stored class, once it agrees with the declaration.
     *
     * @throws  LogicException  When the stored value is unknown, or disagrees with the declaration.
     *
     * @since   2.0.0
     */
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
