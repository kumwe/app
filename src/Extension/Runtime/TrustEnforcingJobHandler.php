<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\Extension\Spi\Application\Automation\JobHandler;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\JobContributionDefinition;

/**
 * Re-establishes exact runtime-generation and package trust before extension job code executes.
 *
 * A contributed job implementation is bound once while the runtime loads and is then executed by the
 * worker for as long as the process lives, so the worker on its own cannot express that the package was
 * superseded, deactivated or had its signing key revoked afterwards. The binding registrar therefore
 * wraps every contributed job handler in this one, which applies the same fence as a Studio preview
 * renderer: the boot generation is checked before and again inside the installation-wide lifecycle
 * lock, package trust is re-run against the exact signed runtime entry that loaded the code, and only
 * then does the delegate run. A refusal propagates so the job fails closed rather than reaching code the
 * installation no longer trusts.
 *
 * @since  2.0.0
 */
final readonly class TrustEnforcingJobHandler implements JobHandler
{
    /**
     * Bind an implementation to its exact compiled publication entry and live trust authorities.
     *
     * @param  JobHandler              $inner         Owner-local SDK implementation.
     * @param  TrustStore              $trust         Live package trust boundary.
     * @param  ExtensionExecutionGate  $execution     Exact boot-generation fence.
     * @param  string                  $extension     Canonical `vendor/name` package owner.
     * @param  array<string, mixed>    $runtimeEntry  Exact signed compiled entry that loaded the code.
     *
     * @since  2.0.0
     */
    public function __construct(
        private JobHandler $inner,
        private TrustStore $trust,
        private ExtensionExecutionGate $execution,
        private string $extension,
        private array $runtimeEntry,
    ) {
    }

    /**
     * Execute only while the same signed runtime entry is active and trusted.
     *
     * @param   JobContributionDefinition  $definition  Signed job declaration the implementation is bound to.
     * @param   array<string, mixed>       $payload     Payload already validated against the signed schema.
     * @param   ExecutionContext           $context     Host-issued execution context for this job run.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When this process no longer holds the current trusted generation, or
     *          the compiled entry no longer describes the authoritative release.
     * @throws  \Kumwe\App\Extension\Application\Trust\UntrustedPackage  When the package is no longer
     *          trusted; it is quarantined before the refusal is raised.
     *
     * @since   2.0.0
     */
    public function handle(JobContributionDefinition $definition, array $payload, ExecutionContext $context): void
    {
        $this->execution->assertCurrent();

        $this->trust->synchronizedLifecycle(
            function () use ($definition, $payload, $context): void {
                $this->execution->assertCurrent();
                $this->trust->enforceRuntimeTrust($this->extension, $this->runtimeEntry);

                $this->inner->handle($definition, $payload, $context);
            },
        );
    }

    /**
     * Report whether the exact boot publication and package trust still authorize this implementation.
     *
     * @return  bool  True only while the exact compiled owner/version entry remains current and trusted.
     *
     * @since   2.0.0
     */
    public function isAvailable(): bool
    {
        if (!$this->execution->isCurrent()) {
            return false;
        }
        try {
            return $this->trust->synchronizedLifecycle(function (): bool {
                if (!$this->execution->isCurrent()) {
                    return false;
                }
                $this->trust->enforceRuntimeTrust($this->extension, $this->runtimeEntry);

                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }
}
