<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSurface\Application;

use Kumwe\CMS\Application\Authorization\AuthenticatedSurface;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\MembershipContext;
use Kumwe\CMS\Application\Authorization\OrganizationContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\WorkspaceContext;
use Kumwe\CMS\BusinessSurface\Application\GeneratedBusinessActionStepUp;
use Kumwe\CMS\BusinessSurface\Application\GeneratedBusinessStepUpInputRejected;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Application\StepUp\AuthorizationStepUpProofAdapter;
use Kumwe\CMS\Tests\Support\GeneratedActionCapturingStepUpProvider;
use Kumwe\CMS\Tests\Support\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GeneratedBusinessActionStepUp::class)]
/**
 * Verifies exact-purpose generated action proof issuance independently of HTTP rendering.
 *
 * @since  2.0.0
 */
final class GeneratedBusinessActionStepUpTest extends TestCase
{
    /**
     * A recovery credential and action must share one server-owned elevated transaction context.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecoveryProofUsesServerContextAndElevatesOnlyTheRotatedSession(): void
    {
        $provider = new GeneratedActionCapturingStepUpProvider();
        $stepUp = new GeneratedBusinessActionStepUp(
            new AuthorizationStepUpProofAdapter(),
            new ImmediateTransactionManager(),
        );
        $context = $this->context();
        [$elevated, $verification] = $stepUp->execute(
            $context,
            'business.record.action:approve',
            [
                'verification_method' => 'recovery',
                'verification' => 'requester-recovery-code',
                'purpose' => 'business.record.action:attacker',
                'site' => 'attacker',
            ],
            '192.0.2.10',
            $provider,
            static fn (ExecutionContext $stepped): ExecutionContext => $stepped,
        );

        self::assertSame('business.record.action:approve', $provider->lastIntent?->purpose);
        self::assertSame('default', $provider->lastIntent?->siteIdentifier);
        self::assertSame('acme', $provider->lastIntent?->organizationIdentifier);
        self::assertSame('north', $provider->lastIntent?->workspaceIdentifier);
        self::assertSame('018f0000-0000-7000-8000-000000000002', $provider->lastIntent?->sessionId);
        self::assertSame('requester-recovery-code', $provider->credential);
        self::assertSame('192.0.2.10', $provider->source);
        self::assertSame($verification->rotatedSession->sessionId, $elevated->sessionId());
        self::assertSame(AuthenticationStrength::MultiFactor, $elevated->authenticationStrength());
        self::assertSame(AuthenticatedSurface::Portal, $elevated->surface());
        self::assertSame('business.record.action:approve', $elevated->stepUpProof()?->purpose());
    }

    /**
     * An unknown method must fail as verification input before action work is called.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnknownVerificationMethodFailsBeforeActionExecution(): void
    {
        $provider = new GeneratedActionCapturingStepUpProvider();
        $stepUp = new GeneratedBusinessActionStepUp(
            new AuthorizationStepUpProofAdapter(),
            new ImmediateTransactionManager(),
        );
        $executed = false;

        $this->expectException(GeneratedBusinessStepUpInputRejected::class);
        $this->expectExceptionMessage('Choose an authenticator or recovery code.');
        try {
            $stepUp->execute(
                $this->context(),
                'business.record.action:approve',
                ['verification_method' => 'password', 'verification' => 'not-accepted'],
                'unknown',
                $provider,
                static function (ExecutionContext $_context) use (&$executed): void {
                    $executed = true;
                },
            );
        } finally {
            self::assertNull($provider->lastIntent);
            self::assertFalse($executed);
        }
    }

    /**
     * Build a portal context carrying the exact organization, workspace, and old session bindings.
     *
     * @return  ExecutionContext  Password-authenticated requester context.
     *
     * @since   2.0.0
     */
    private function context(): ExecutionContext
    {
        $principal = AuthenticatedPrincipal::issueFromStrings(
            new \stdClass(),
            '018f0000-0000-7000-8000-000000000001',
            ['business.record.action'],
        );
        $membership = new MembershipContext(
            '018f0000-0000-7000-8000-000000000003',
            OrganizationContext::fromString('acme'),
            WorkspaceContext::fromString('north'),
            1,
            1,
        );

        return $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'generated-business-step-up-request',
            'generated-business-step-up-correlation',
            AuthenticatedSurface::Portal,
            $membership,
            '018f0000-0000-7000-8000-000000000002',
        );
    }
}
