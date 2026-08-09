<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Portal\Http;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinitionRegistry;
use Kumwe\CMS\Http\Middleware\TrustedProxyMiddleware;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Application\StepUp\StepUpProvider;
use Kumwe\CMS\Identity\Domain\StepUp\RotatedStepUpSession;
use Kumwe\CMS\Identity\Domain\StepUp\StepUpEnrollmentCompletion;
use Kumwe\CMS\Identity\Domain\StepUp\StepUpEnrollmentSetup;
use Kumwe\CMS\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\CMS\Identity\Domain\StepUp\StepUpMethod;
use Kumwe\CMS\Identity\Domain\StepUp\StepUpVerification;
use Kumwe\CMS\Portal\Application\PortalSession;
use Kumwe\CMS\Portal\Application\PortalSessionIdentity;
use Kumwe\CMS\Portal\Contribution\PortalNavigationRegistry;
use Kumwe\CMS\Portal\Contribution\PortalTemplateRegistry;
use Kumwe\CMS\Portal\Contribution\PortalWorkspaceRegistry;
use Kumwe\CMS\Portal\Domain\PortalContext;
use Kumwe\CMS\Portal\Http\Handler\PortalSecurityHandler;
use Kumwe\CMS\Portal\Presentation\PortalRenderer;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversClass(PortalSecurityHandler::class)]
final class PortalSecurityHandlerTest extends TestCase
{
    public function testConfirmationUsesOnlyResolvedSessionContextAndPublishesRotatedCookieAndCodes(): void
    {
        $provider = new CapturingStepUpProvider();
        $handler = new PortalSecurityHandler($provider, $this->renderer(), true, 3600);
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://example.test/portal/security/totp/confirm'),
            'POST',
        ))
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $this->session())
            ->withAttribute(TrustedProxyMiddleware::ATTRIBUTE_CLIENT_ADDRESS, '192.0.2.5')
            ->withParsedBody([
                'enrollment_id' => '018f0000-0000-7000-8000-000000000010',
                'code' => '123456',
                'purpose' => 'business.approval.approve',
                'site' => 'attacker-site',
            ]);

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('kumwe_portal=rotated_cookie_token_', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Path=/portal', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('; Secure', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('0000-0000-0000-0000-0000-0000-0000-0000', (string) $response->getBody());
        self::assertSame('portal.step_up.enroll', $provider->lastIntent?->purpose);
        self::assertSame('default', $provider->lastIntent?->siteIdentifier);
        self::assertSame('018f0000-0000-7000-8000-000000000002', $provider->lastIntent?->sessionId);
        self::assertSame('192.0.2.5', $provider->source);
    }

    public function testChallengePurposeCannotBeSelectedByTheSubmittedForm(): void
    {
        $provider = new CapturingStepUpProvider();
        $handler = new PortalSecurityHandler($provider, $this->renderer(), false, 3600);
        $request = (new ServerRequest(
            [],
            [],
            new Uri('https://example.test/portal/security/challenge'),
            'POST',
        ))
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $this->session())
            ->withParsedBody(['code' => '123456', 'purpose' => 'business.approval.revoke']);

        $response = $handler->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/portal/security?verified=1', $response->getHeaderLine('Location'));
        self::assertSame('portal.step_up.challenge', $provider->lastIntent?->purpose);
    }

    private function renderer(): PortalRenderer
    {
        $workspaces = new PortalWorkspaceRegistry();
        $capabilities = new CapabilityDefinitionRegistry();
        return new PortalRenderer(
            new Environment(new ArrayLoader([
                'portal/security.twig' => '{{ portal_session.id }} {{ notice }} '
                    . '{% for code in recovery_codes %}{{ code }} {% endfor %}',
            ]), ['strict_variables' => true]),
            new PortalNavigationRegistry($workspaces, $capabilities, new AuthorizationPolicyRegistry()),
            new PortalTemplateRegistry(),
        );
    }

    private function session(): PortalSession
    {
        $now = new DateTimeImmutable('2026-08-09T10:00:00+00:00');
        $principal = AuthenticatedPrincipal::issueFromStrings(
            new \stdClass(),
            '018f0000-0000-7000-8000-000000000001',
            ['portal.access'],
        );
        return new PortalSession(
            '018f0000-0000-7000-8000-000000000002',
            new PortalSessionIdentity($principal, new PortalContext(SiteContext::default(), null), 1),
            str_repeat('c', 43),
            $now,
            null,
            $now->modify('+1 hour'),
        );
    }
}

final class CapturingStepUpProvider implements StepUpProvider
{
    public ?StepUpIntent $lastIntent = null;

    public string $source = '';

    public function beginEnrollment(string $subjectId, string $issuer, string $accountLabel): StepUpEnrollmentSetup
    {
        return new StepUpEnrollmentSetup(
            '018f0000-0000-7000-8000-000000000010',
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',
            'otpauth://totp/Kumwe?secret=ABCDEFGHIJKLMNOPQRSTUVWXYZ234567&issuer=Kumwe',
            new DateTimeImmutable('2026-08-09T10:10:00+00:00'),
        );
    }

    public function confirmEnrollment(
        StepUpIntent $intent,
        string $enrollmentId,
        string $code,
        string $source,
    ): StepUpEnrollmentCompletion {
        $this->lastIntent = $intent;
        $this->source = $source;
        return new StepUpEnrollmentCompletion(
            $this->verification($intent),
            array_map(
                static fn (int $value): string => implode('-', array_fill(0, 8, sprintf('%04x', $value))),
                range(0, 9),
            ),
        );
    }

    public function challenge(StepUpIntent $intent, string $code, string $source): StepUpVerification
    {
        $this->lastIntent = $intent;
        $this->source = $source;
        return $this->verification($intent);
    }

    public function recover(StepUpIntent $intent, string $recoveryCode, string $source): StepUpVerification
    {
        $this->lastIntent = $intent;
        $this->source = $source;
        return $this->verification($intent, StepUpMethod::RecoveryCode);
    }

    private function verification(
        StepUpIntent $intent,
        StepUpMethod $method = StepUpMethod::Totp,
    ): StepUpVerification {
        $now = new DateTimeImmutable('2026-08-09T10:01:00+00:00');
        return new StepUpVerification(
            $intent,
            '018f0000-0000-7000-8000-000000000011',
            $method,
            $now,
            $now->modify('+5 minutes'),
            str_repeat('n', 43),
            new RotatedStepUpSession(
                '018f0000-0000-7000-8000-000000000012',
                'rotated_cookie_token_' . str_repeat('x', 32),
                str_repeat('z', 43),
                $now->modify('+59 minutes'),
            ),
        );
    }
}
