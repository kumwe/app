<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSecurity\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthenticatedSurface;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\MembershipContext;
use Kumwe\CMS\Application\Authorization\OrganizationContext;
use Kumwe\CMS\Application\Authorization\ResourcePolicyTarget;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\StepUpProof;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationRepository;
use Kumwe\CMS\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService;
use Kumwe\CMS\BusinessSecurity\Application\Administration\BusinessSecurityScopeDenied;
use Kumwe\CMS\BusinessSecurity\Application\Administration\SelfEscalationDenied;
use Kumwe\CMS\BusinessSecurity\Application\Approval\StepUpProofConsumer;
use Kumwe\CMS\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\CMS\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinition;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Extension\Contribution\ResourcePolicyDefinition;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(BusinessSecurityAdministrationService::class)]
#[CoversClass(SelfEscalationDenied::class)]
final class BusinessSecurityAdministrationServiceTest extends TestCase
{
    private const ACTOR = '0191574f-f0b8-7bf3-a9aa-91c6b8244e10';
    private const TARGET = '0191574f-f0b8-7bf3-a9aa-91c6b8244e11';
    private const MEMBERSHIP = '0191574f-f0b8-7bf3-a9aa-91c6b8244e12';
    private const ROLE = '0191574f-f0b8-7bf3-a9aa-91c6b8244e13';
    private const DEFINITION = '0191574f-f0b8-7bf3-a9aa-91c6b8244e14';
    private const ORGANIZATION = '0191574f-f0b8-7bf3-a9aa-91c6b8244e17';

    public function testActorCannotChangeOwnMembershipEvenWithManagementCapability(): void
    {
        $repository = $this->createMock(BusinessSecurityAdministrationRepository::class);
        $repository->expects(self::once())->method('membershipAuthority')->willReturn([
            'user_id' => self::ACTOR,
            'organization_id' => self::ORGANIZATION,
            'organization_identifier' => 'acme',
        ]);
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::never())->method('assertAllowed');
        $stepUp = $this->createMock(StepUpProofConsumer::class);
        $stepUp->expects(self::never())->method('consume');

        $service = $this->service($repository, $authorization, $stepUp);

        $this->expectException(SelfEscalationDenied::class);
        $service->changeMembershipStatus(
            $this->bearerContext(),
            self::MEMBERSHIP,
            'active',
            1,
        );
    }

    public function testRoleAssignmentAppliesDelegationCeilingAndConsumesExactProof(): void
    {
        $now = new DateTimeImmutable('2026-08-09T10:00:00+00:00');
        $context = $this->multiFactorContext('business.security.membership.role.assign', $now);
        $repository = $this->createMock(BusinessSecurityAdministrationRepository::class);
        $repository->expects(self::exactly(2))->method('membershipAuthority')->willReturn([
            'user_id' => self::TARGET,
            'organization_id' => self::ORGANIZATION,
            'organization_identifier' => 'acme',
        ]);
        $repository->expects(self::exactly(2))->method('roleGrants')->with(self::ROLE)->willReturn([[
            'capability' => 'business.record.read',
            'scope_type' => 'site',
            'scope_identifier' => SiteContext::DEFAULT,
        ]]);
        $repository->expects(self::once())->method('assignMembershipRole')->with(
            self::MEMBERSHIP,
            self::ROLE,
            SiteContext::DEFAULT,
            self::ACTOR,
            $now,
        );
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::exactly(2))->method('assertCanDelegate');
        $authorization->expects(self::once())->method('assertAllowed');
        $stepUp = $this->createMock(StepUpProofConsumer::class);
        $stepUp->expects(self::once())->method('consume')->with(
            $context->stepUpProof(),
            $context,
            'business.security.membership.role.assign',
            $now,
        )->willReturn('0191574f-f0b8-7bf3-a9aa-91c6b8244e15');

        $service = $this->service($repository, $authorization, $stepUp, $now);
        $service->assignRole($context, self::MEMBERSHIP, self::ROLE);
    }

    public function testStructuredPolicyFieldsProduceTypedAstAndExplicitDisclosure(): void
    {
        $now = new DateTimeImmutable('2026-08-09T10:00:00+00:00');
        $context = $this->multiFactorContext('business.security.resource_policy.create', $now);
        $repository = $this->createMock(BusinessSecurityAdministrationRepository::class);
        $repository->method('definitionFieldTypes')->willReturn(['owner_id' => 'string']);
        $repository->method('definitionActions')->willReturn(['approve']);
        $repository->expects(self::once())->method('insertResourcePolicy')->with(
            self::isType('string'),
            'invoice.owner',
            'business.record.browse',
            'business.record.browse',
            'deny',
            null,
            self::DEFINITION,
            [
                'type' => 'comparison',
                'field' => 'owner_id',
                'operator' => 'not_equal',
                'value_type' => 'string',
                'value' => self::TARGET,
            ],
            self::callback(static fn (array $rules): bool => $rules['detail'] === ['owner_id']
                && $rules['actions'] === ['approve']
                && $rules['create'] === []
                && $rules['mcp'] === []
                && $rules['relation'] === []
                && $rules['include'] === []
                && $rules['public_reference'] === []
                && count($rules) === count(FieldAccessUsage::cases()) + 1),
            self::matchesRegularExpression('/^[0-9a-f]{64}$/D'),
            10,
            self::ACTOR,
            SiteContext::DEFAULT,
            $now,
        );
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::once())->method('assertAllowed');
        $stepUp = $this->createMock(StepUpProofConsumer::class);
        $stepUp->expects(self::once())->method('consume')->willReturn(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e15',
        );
        $service = $this->service($repository, $authorization, $stepUp, $now);

        $service->createResourcePolicy(
            $context,
            'invoice.owner',
            'business.record.browse',
            'deny',
            null,
            self::DEFINITION,
            'comparison',
            'owner_id',
            'not_equal',
            'string',
            self::TARGET,
            ['detail' => ['owner_id'], 'actions' => ['approve']],
            10,
        );
    }

    public function testPolicyApiRejectsRawJsonInsteadOfInterpretingIt(): void
    {
        $repository = $this->createStub(BusinessSecurityAdministrationRepository::class);
        $repository->method('definitionFieldTypes')->willReturn(['owner_id' => 'string']);
        $repository->method('definitionActions')->willReturn([]);
        $service = $this->service(
            $repository,
            $this->createStub(AuthorizationGateway::class),
            $this->createStub(StepUpProofConsumer::class),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->createResourcePolicy(
            $this->bearerContext(),
            'invoice.raw',
            'business.record.browse',
            'deny',
            null,
            self::DEFINITION,
            '{"type":"constant","value":true}',
            null,
            null,
            null,
            null,
            [],
        );
    }

    /**
     * Prove an extension-owned capability follows the same typed business-record policy path as core.
     *
     * @since  2.0.0
     */
    public function testStructuredPolicyAcceptsExtensionCapabilityBoundToBusinessRecords(): void
    {
        $now = new DateTimeImmutable('2026-08-09T10:00:00+00:00');
        $context = $this->multiFactorContext('business.security.resource_policy.create', $now);
        $registries = new ExtensionContributionRegistrySet();
        $owner = ContributionOwner::extension('acme/invoices');
        $capability = new CapabilityDefinition(
            'acme.invoices.record.audit',
            'Audit invoices',
            'Audit invoice records through the shared business-record policy path.',
            ['site', 'business_record'],
        );
        $policy = new ResourcePolicyDefinition(
            'acme.invoices.record.audit-policy',
            $capability->id,
            [new ResourcePolicyTarget('business_record')],
        );
        $declared = new ManifestContributionSet(
            $owner,
            capabilities: [$capability],
            resourcePolicies: [$policy],
        );
        $registrar = $registries->registrar($owner, $declared);
        $registrar->capability($capability);
        $registrar->resourcePolicy($policy);
        $registrar->complete();

        $repository = $this->createMock(BusinessSecurityAdministrationRepository::class);
        $repository->method('definitionFieldTypes')->willReturn(['owner_id' => 'string']);
        $repository->method('definitionActions')->willReturn([]);
        $repository->expects(self::once())->method('insertResourcePolicy')->with(
            self::isType('string'),
            'invoice.extension-audit',
            'acme.invoices.record.audit',
            'acme.invoices.record.audit',
            'deny',
            null,
            self::DEFINITION,
            ['type' => 'constant', 'value' => true],
            self::isType('array'),
            self::matchesRegularExpression('/^[0-9a-f]{64}$/D'),
            0,
            self::ACTOR,
            SiteContext::DEFAULT,
            $now,
        );
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::once())->method('assertAllowed');
        $stepUp = $this->createMock(StepUpProofConsumer::class);
        $stepUp->expects(self::once())->method('consume')->willReturn(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e15',
        );

        $this->service(
            $repository,
            $authorization,
            $stepUp,
            $now,
            $registries->authorizationPolicies(),
        )->createResourcePolicy(
            $context,
            'invoice.extension-audit',
            'acme.invoices.record.audit',
            'deny',
            null,
            self::DEFINITION,
            'constant',
            null,
            null,
            null,
            'true',
            [],
        );
    }

    /**
     * Prove an inactive allow policy cannot be activated when it could enlarge the actor's own access.
     *
     * @since  2.0.0
     */
    public function testActorCannotActivateAllowPolicyAffectingOwnAuthority(): void
    {
        $now = new DateTimeImmutable('2026-08-09T10:00:00+00:00');
        $context = $this->multiFactorContext(
            'business.security.resource_policy.status',
            $now,
            ['business.record.browse'],
        );
        $repository = $this->createMock(BusinessSecurityAdministrationRepository::class);
        $repository->expects(self::exactly(2))->method('resourcePolicyAuthority')->willReturn([
            'effect' => 'allow',
            'capability' => 'business.record.browse',
            'organization_id' => null,
            'organization_identifier' => null,
        ]);
        $repository->expects(self::never())->method('updateResourcePolicyStatus');
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::once())->method('assertAllowed');
        $stepUp = $this->createMock(StepUpProofConsumer::class);
        $stepUp->expects(self::once())->method('consume')->willReturn(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e15',
        );

        $service = $this->service($repository, $authorization, $stepUp, $now);

        $this->expectException(SelfEscalationDenied::class);
        $service->changeResourcePolicyStatus($context, self::MEMBERSHIP, 'active', 1);
    }

    public function testSeparationRuleRejectsApprovalActionWithoutLiveApprovalRequestPolicy(): void
    {
        $repository = $this->createMock(BusinessSecurityAdministrationRepository::class);
        $repository->expects(self::never())->method('insertSeparationRule');
        $service = $this->service(
            $repository,
            $this->createStub(AuthorizationGateway::class),
            $this->createStub(StepUpProofConsumer::class),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->createSeparationRule(
            $this->bearerContext(),
            'invoice.approval',
            'business_record',
            'business.record.action:approve_invoice',
            'content.read',
            null,
            null,
            null,
            1,
            true,
        );
    }

    /**
     * Prove organization-scoped administration cannot target another organization branch in the site.
     *
     * @since  2.0.0
     */
    public function testOrganizationContextCannotChangeMembershipInAnotherOrganization(): void
    {
        $now = new DateTimeImmutable('2026-08-09T10:00:00+00:00');
        $context = $this->multiFactorContext(
            'business.security.membership.status',
            $now,
            membership: new MembershipContext(
                '0191574f-f0b8-7bf3-a9aa-91c6b8244e18',
                OrganizationContext::fromString('acme'),
                null,
                3,
                7,
            ),
        );
        $repository = $this->createMock(BusinessSecurityAdministrationRepository::class);
        $repository->expects(self::once())->method('membershipAuthority')->with(
            self::MEMBERSHIP,
            SiteContext::DEFAULT,
            false,
        )->willReturn([
            'user_id' => self::TARGET,
            'organization_id' => self::ORGANIZATION,
            'organization_identifier' => 'other-organization',
        ]);
        $repository->expects(self::never())->method('updateMembershipStatus');
        $authorization = $this->createMock(AuthorizationGateway::class);
        $authorization->expects(self::never())->method('assertAllowed');
        $stepUp = $this->createMock(StepUpProofConsumer::class);
        $stepUp->expects(self::never())->method('consume');

        $this->expectException(BusinessSecurityScopeDenied::class);
        $this->service($repository, $authorization, $stepUp, $now)->changeMembershipStatus(
            $context,
            self::MEMBERSHIP,
            'active',
            1,
        );
    }

    private function service(
        BusinessSecurityAdministrationRepository $repository,
        AuthorizationGateway $authorization,
        StepUpProofConsumer $stepUp,
        ?DateTimeImmutable $now = null,
        ?AuthorizationPolicyRegistry $policies = null,
    ): BusinessSecurityAdministrationService {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $audit = $this->createStub(AuditRecorder::class);
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now ?? new DateTimeImmutable('2026-08-09T10:00:00+00:00'));
        $memberships = $this->createStub(MembershipDirectory::class);
        $memberships->method('current')->willReturn(true);

        return new BusinessSecurityAdministrationService(
            $repository,
            $authorization,
            $policies ?? (new ExtensionContributionRegistrySet())->authorizationPolicies(),
            $memberships,
            $stepUp,
            $transactions,
            $audit,
            $clock,
        );
    }

    private function bearerContext(): ExecutionContext
    {
        $provenance = new \stdClass();
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            self::ACTOR,
            ['business.security.manage'],
        );

        return ExecutionContext::issueHuman(
            $provenance,
            $principal,
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'business-security-test',
            surface: AuthenticatedSurface::Administrator,
        );
    }

    /**
     * Build a proof-bearing administrator context with optional extra effective capabilities.
     *
     * @param   string             $purpose  Exact purpose bound into the proof.
     * @param   DateTimeImmutable  $now  Trusted test instant.
     * @param   list<string>       $capabilities  Additional effective capabilities for the actor.
     * @param   ?MembershipContext $membership  Optional server-resolved organization scope.
     *
     * @return  ExecutionContext  Multi-factor administrator execution context.
     *
     * @since   2.0.0
     */
    private function multiFactorContext(
        string $purpose,
        DateTimeImmutable $now,
        array $capabilities = [],
        ?MembershipContext $membership = null,
    ): ExecutionContext {
        $provenance = new \stdClass();
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            self::ACTOR,
            ['business.security.manage', ...$capabilities],
        );
        $session = '0191574f-f0b8-7bf3-a9aa-91c6b8244e16';
        $proof = new StepUpProof(
            self::ACTOR,
            $session,
            SiteContext::default(),
            $membership?->organization(),
            'totp',
            $now->modify('-1 minute'),
            $now->modify('+4 minutes'),
            str_repeat('N', 32),
            workspace: $membership?->workspace(),
            purpose: $purpose,
        );

        return ExecutionContext::issueHuman(
            $provenance,
            $principal,
            SiteContext::default(),
            AuthenticationStrength::MultiFactor,
            'business-security-test',
            surface: AuthenticatedSurface::Administrator,
            membership: $membership,
            sessionId: $session,
            stepUpProof: $proof,
        );
    }
}
