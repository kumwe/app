<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use InvalidArgumentException;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\RuntimePublicationMismatch;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Application\Trust\UntrustedPackage;
use Kumwe\Extension\Spi\Application\Automation\JobHandler;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Automation\JobContributionDefinition;
use RuntimeException;

/**
 * Re-establishes exact runtime-generation and package trust before extension job code executes.
 *
 * A contributed job implementation is bound once while the runtime loads and is then executed by the
 * worker for as long as the process lives, so the worker on its own cannot express that the package was
 * superseded, deactivated or had its signing key revoked afterwards. The binding registrar therefore
 * wraps every contributed job handler in this one, which applies the same fence as a Studio preview
 * renderer: package trust is re-read from committed authority against the exact signed runtime entry
 * that loaded the code, the boot generation must be current both before and after that read, and only
 * then does the delegate run. None of it takes the installation-wide lifecycle lock, which serializes
 * mutators and is taken without waiting, so two workers no longer refuse each other's jobs. A refusal
 * propagates so the job fails closed rather than reaching code the installation no longer trusts; the
 * worker's claim and settlement stay fenced by the same generation.
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
     * @throws  RuntimeException  When this process no longer holds the current trusted generation, the
     *          generation changed during the trust read, or the trust authority cannot be read.
     * @throws  RuntimePublicationMismatch  When the compiled entry no longer describes the authoritative
     *          release.
     * @throws  UntrustedPackage  When the package is no longer trusted; it is quarantined before the
     *          refusal is raised.
     *
     * @since   2.0.0
     */
    public function handle(JobContributionDefinition $definition, array $payload, ExecutionContext $context): void
    {
        if (!$this->trust->residentRuntimeTrusted($this->execution, $this->extension, $this->runtimeEntry)) {
            throw new RuntimeException('This process cannot execute a stale or untrusted extension generation.');
        }

        $this->inner->handle($definition, $payload, $context);
    }

    /**
     * Report whether the exact boot publication and package trust still authorize this implementation.
     *
     * A stale generation and a distrust verdict both answer false; a trust authority that cannot be read
     * propagates instead, because it is not a verdict about the package.
     *
     * @return  bool  True only while the exact compiled owner/version entry remains current and trusted.
     *
     * @throws  RuntimeException  When the trust authority cannot be read; `TrustStore` has logged it.
     *
     * @since   2.0.0
     */
    public function isAvailable(): bool
    {
        try {
            return $this->trust->residentRuntimeTrusted($this->execution, $this->extension, $this->runtimeEntry);
        } catch (UntrustedPackage | RuntimePublicationMismatch | InvalidArgumentException) {
            return false;
        }
    }
}
