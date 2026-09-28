<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use Kumwe\Access\MembershipDirectory;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationRepository;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService;
use Kumwe\App\BusinessSurface\Presentation\Field\SdkFieldConfigurationAdmission;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\Approval\StepUpProofConsumer;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;

/**
 * Builds the real `BusinessSecurityAdministrationService` over a repository answering a fixed overview.
 *
 * The capability gate, the token and step-up redaction and the step-up requirement of every write stay the
 * service's own, so a machine adapter under test is proven against the same refusals the screen meets.
 *
 * @since  2.0.0
 */
trait BuildsBusinessSecurityService
{
    /**
     * Build the service over a repository whose overview carries one organization, a token and a credential row.
     *
     * @return  BusinessSecurityAdministrationService  Service under test.
     *
     * @since   2.0.0
     */
    private function businessSecurityService(): BusinessSecurityAdministrationService
    {
        $repository = $this->createStub(BusinessSecurityAdministrationRepository::class);
        $repository->method('overview')->willReturn([
            'organizations' => [['identifier' => 'north', 'name' => 'North']],
            'tokens' => [['id' => 'token-row']],
            'step_up_credentials' => [['id' => 'credential-row']],
        ]);
        $memberships = $this->createStub(MembershipDirectory::class);
        $memberships->method('current')->willReturn(true);

        return new BusinessSecurityAdministrationService(
            $repository,
            AuthorizationContext::gateway(),
            (new ExtensionContributionRegistrySet(
                new DeterministicCanonicalEncoder(),
                new SdkFieldConfigurationAdmission(),
            ))->authorizationPolicies(),
            $memberships,
            $this->createStub(StepUpProofConsumer::class),
            new ImmediateTransactionManager(),
            new RecordingAuditRecorder(),
            new MovableAuditClock(new DateTimeImmutable('2026-09-24T10:00:00+00:00')),
        );
    }
}
