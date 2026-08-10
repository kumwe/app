<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessReporting;

use DateTimeImmutable;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\CMS\Application\Authorization\AuthenticatedSurface;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessRecord\Application\Query\BusinessRecordQueryPurpose;
use Kumwe\CMS\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\CMS\BusinessReporting\Application\BusinessRecordReportReader;
use Kumwe\CMS\BusinessReporting\Application\ExportService;
use Kumwe\CMS\BusinessReporting\Application\ReportDefinitionRegistry;
use Kumwe\CMS\BusinessReporting\Application\ReportScopeResolver;
use Kumwe\CMS\BusinessReporting\Application\ReportService;
use Kumwe\CMS\BusinessReporting\Delivery\Administrator\AdministratorReportHandler;
use Kumwe\CMS\BusinessReporting\Delivery\Api\ReportApiPresenter;
use Kumwe\CMS\BusinessReporting\Delivery\Portal\PortalReportHandler;
use Kumwe\CMS\BusinessReporting\Domain\ReportDefinition;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinitionRegistry;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSession;
use Kumwe\CMS\Portal\Application\PortalSession;
use Kumwe\CMS\Portal\Application\PortalSessionIdentity;
use Kumwe\CMS\Portal\Contribution\PortalNavigationRegistry;
use Kumwe\CMS\Portal\Contribution\PortalTemplateRegistry;
use Kumwe\CMS\Portal\Contribution\PortalWorkspaceRegistry;
use Kumwe\CMS\Portal\Domain\PortalContext;
use Kumwe\CMS\Portal\Presentation\PortalNavigationVisibility;
use Kumwe\CMS\Portal\Presentation\PortalRenderer;
use Kumwe\CMS\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\CMS\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversClass(AdministratorReportHandler::class)]
#[CoversClass(PortalReportHandler::class)]
final class ReportBrowserErrorResponseTest extends TestCase
{
    public function testAdministratorRendersInvalidJsonAsGenericAccessibleHtml(): void
    {
        $principal = AuthorizationContext::principal(['business.record.report']);
        $context = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'administrator-report-error-test',
            surface: AuthenticatedSurface::Administrator,
        );
        $session = new AdministratorSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb621',
            $principal,
            str_repeat('a', 43),
            new DateTimeImmutable('2026-08-10T12:00:00+00:00'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/administrator/reports/acme.open_items')
            ->withParsedBody(['parameters_json' => '{commercially-sensitive'])
            ->withAttribute('operation', 'execute')
            ->withAttribute('report', 'acme.open_items')
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session);

        $response = (new AdministratorReportHandler(
            $this->reports(),
            $this->exports(),
            new ReportApiPresenter(),
            $this->administratorRenderer(),
            new StreamFactory(),
        ))->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('role="alert"', (string) $response->getBody());
        self::assertStringContainsString('data-csrf="' . str_repeat('a', 43) . '"', (string) $response->getBody());
        self::assertStringNotContainsString('commercially-sensitive', (string) $response->getBody());
        self::assertStringNotContainsString('valid JSON', (string) $response->getBody());
    }

    public function testPortalRendersInvalidJsonAsGenericAccessibleHtml(): void
    {
        $principal = AuthorizationContext::principal(['portal.access', 'business.record.report']);
        $context = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'portal-report-error-test',
            surface: AuthenticatedSurface::Portal,
        );
        $now = new DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $session = new PortalSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb622',
            new PortalSessionIdentity($principal, new PortalContext(SiteContext::default(), null), 1),
            str_repeat('b', 43),
            $now,
            null,
            $now->modify('+1 hour'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/portal/reports/acme.open_items')
            ->withParsedBody(['parameters_json' => '{commercially-sensitive'])
            ->withAttribute('operation', 'execute')
            ->withAttribute('report', 'acme.open_items')
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context)
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $session);

        $response = (new PortalReportHandler(
            $this->reports(),
            $this->exports(),
            new ReportApiPresenter(),
            $this->portalRenderer(),
            new StreamFactory(),
        ))->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('role="alert"', (string) $response->getBody());
        self::assertStringNotContainsString('commercially-sensitive', (string) $response->getBody());
        self::assertStringNotContainsString('valid JSON', (string) $response->getBody());
    }

    private function reports(): ReportService
    {
        return new ReportService(
            new ReportDefinitionRegistry([]),
            new class implements BusinessRecordReportReader {
                public function browse(
                    ExecutionContext $context,
                    string $definitionIdentifier,
                    RecordQuerySpecification $specification,
                    ?string $organizationIdentifier,
                    BusinessRecordQueryPurpose $purpose,
                ): RecordBrowseResult {
                    return new RecordBrowseResult([]);
                }
            },
            $this->createStub(AuthorizationGateway::class),
            new class implements ReportScopeResolver {
                public function resolve(
                    ExecutionContext $context,
                    ReportDefinition $report,
                    ?string $assertedOrganization,
                ): ?string {
                    return $assertedOrganization;
                }
            },
        );
    }

    private function exports(): ExportService
    {
        return (new ReflectionClass(ExportService::class))->newInstanceWithoutConstructor();
    }

    private function administratorRenderer(): AdministratorRenderer
    {
        $template = '<div role="alert" data-csrf="{{ csrf }}">{{ report_error }}</div>';
        return new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader(['business-report.twig' => $template])),
            new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(
                new ArrayLoader(['business-report.twig' => $template]),
            )),
        );
    }

    private function portalRenderer(): PortalRenderer
    {
        $workspaces = new PortalWorkspaceRegistry();
        $capabilities = new CapabilityDefinitionRegistry();
        return new PortalRenderer(
            new Environment(new ArrayLoader([
                'portal/business-report.twig' => '<div role="alert">{{ report_error }}</div>',
            ]), ['strict_variables' => true]),
            new PortalNavigationRegistry($workspaces, $capabilities, new AuthorizationPolicyRegistry()),
            new PortalTemplateRegistry(),
            $this->createStub(PortalNavigationVisibility::class),
        );
    }
}
