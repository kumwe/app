<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Business;

use DateTimeImmutable;
use Kumwe\Access\AuthorizationDenied;
use Kumwe\Access\MembershipDirectory;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationRepository;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService;
use Kumwe\App\Delivery\Http\Api\Business\BusinessSecurityApiHandler;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\App\Tests\Support\MovableAuditClock;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use Kumwe\Approval\StepUpProofConsumer;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\BusinessSurface\Presentation\Field\SdkFieldConfigurationAdmission;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Pins the Business Security read model REST adapter to the administrator screen's overview.
 *
 * The real `BusinessSecurityAdministrationService` runs over a repository stub, so the capability gate and the
 * token and step-up redaction are the service's; the adapter only serializes the overview the screen renders,
 * uncached, and never becomes a write path.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessSecurityApiHandler::class)]
final class BusinessSecurityApiHandlerTest extends TestCase
{
    /**
     * The overview the screen renders is answered as uncached JSON, with the service's redactions applied.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheOverviewIsAnsweredAsUncachedJson(): void
    {
        $response = $this->handle(['business.security.manage']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $document = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame([['identifier' => 'north', 'name' => 'North']], $document['organizations']);
        self::assertSame([], $document['tokens'], 'Token rows are withheld from a caller without users.manage.');
        self::assertSame([], $document['step_up_credentials']);
    }

    /**
     * A credential without `business.security.manage` is refused by the service itself.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheServiceAuthorizationRefusalPropagates(): void
    {
        $this->expectException(AuthorizationDenied::class);

        $this->handle(['content.read']);
    }

    /**
     * Run one overview request under a bearer principal holding the named capabilities.
     *
     * @param   list<string>  $capabilities  Capabilities the bearer principal holds.
     *
     * @return  ResponseInterface  Handler response.
     *
     * @since   2.0.0
     */
    private function handle(array $capabilities): ResponseInterface
    {
        $repository = $this->createStub(BusinessSecurityAdministrationRepository::class);
        $repository->method('overview')->willReturn([
            'organizations' => [['identifier' => 'north', 'name' => 'North']],
            'tokens' => [['id' => 'token-row']],
            'step_up_credentials' => [['id' => 'credential-row']],
        ]);
        $memberships = $this->createStub(MembershipDirectory::class);
        $memberships->method('current')->willReturn(true);
        $service = new BusinessSecurityAdministrationService(
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
        $principal = AuthorizationContext::principal($capabilities);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/api/v1/business-security')
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $principal->context(
                SiteContext::default(),
                AuthenticationStrength::BearerToken,
                'business-security-api-test',
            ));

        return (new BusinessSecurityApiHandler($service))->handle($request);
    }
}
