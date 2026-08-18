<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Administrator\Http\Handler;

use Kumwe\CMS\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\CMS\Tests\Support\InterfaceTranslation;
use DateTimeImmutable;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorLoginHandler;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSession;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\CMS\Identity\Application\Administration\CreatedAdministratorSession;
use Kumwe\CMS\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\CMS\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

#[CoversClass(AdministratorLoginHandler::class)]
final class AdministratorLoginHandlerTest extends TestCase
{
    public function testCreatesAdministratorSessionInConfiguredSiteContext(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access']);
        $identities = $this->createStub(AdministratorIdentityGateway::class);
        $identities->method('authenticate')->willReturn($principal);
        $sessions = $this->createMock(AdministratorSessionStore::class);
        $sessions->expects(self::once())->method('create')->with(
            self::callback(static fn (ExecutionContext $context): bool =>
                $context->site()->identifier() === 'corporate'),
            'Kumwe test browser',
        )->willReturn(new CreatedAdministratorSession(
            'opaque-session-token',
            new AdministratorSession(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb711',
                $principal,
                'csrf-token',
                new DateTimeImmutable('+1 hour'),
            ),
        ));
        $handler = new AdministratorLoginHandler(
            $identities,
            $sessions,
            $this->renderer(),
            InterfaceTranslation::translator(),
            false,
            3600,
            SiteContext::fromString('corporate'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/login')
            ->withHeader('User-Agent', 'Kumwe test browser')
            ->withParsedBody(['email' => 'owner@example.test', 'password' => 'secret password']);

        $response = $handler->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/administrator', $response->getHeaderLine('Location'));
    }


    /**
     * A wrong credential re-renders the form at 401 with the catalogue's rejection, keeping the email.
     *
     * The rejection names both fields rather than the one that was wrong, so it cannot be used to
     * confirm that an address exists; the typed address survives, and the submitted password does
     * not reach the rendered page.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAWrongCredentialRerendersWithTheCatalogueRejection(): void
    {
        $identities = $this->createStub(AdministratorIdentityGateway::class);
        $identities->method('authenticate')->willReturn(null);

        $response = $this->handler($identities)->handle($this->submission());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = (string) $response->getBody();
        self::assertStringContainsString('The email address or password is incorrect.', $body);
        self::assertStringContainsString('owner@example.test', $body);
        self::assertStringNotContainsString('wrong password', $body);
    }

    /**
     * A throttled address is refused at 429 with the shared wording and a Retry-After.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAThrottledAddressIsRefusedWithTheSharedWording(): void
    {
        $identities = $this->createStub(AdministratorIdentityGateway::class);
        $identities->method('authenticate')->willThrowException(new AuthenticationThrottled());

        $response = $this->handler($identities)->handle($this->submission());

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('900', $response->getHeaderLine('Retry-After'));
        self::assertStringContainsString(
            'Too many unsuccessful authentication attempts.',
            (string) $response->getBody(),
        );
    }

    /**
     * Build the sign-in handler over a template that renders only the rejection and the address.
     *
     * @param   AdministratorIdentityGateway  $identities  Gateway deciding the credential's fate.
     *
     * @return  AdministratorLoginHandler  The handler as the container composes it.
     *
     * @since   2.0.0
     */
    private function handler(AdministratorIdentityGateway $identities): AdministratorLoginHandler
    {
        return new AdministratorLoginHandler(
            $identities,
            $this->createStub(AdministratorSessionStore::class),
            new AdministratorRenderer(
                new AdministratorTwigEnvironment(new ArrayLoader([
                    'login.twig' => '{{ error }}|{{ email }}',
                ])),
                new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(new ArrayLoader())),
            ),
            InterfaceTranslation::translator(),
            false,
            3600,
            SiteContext::fromString('corporate'),
        );
    }

    /**
     * A posted sign-in carrying an address and a password.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  The submission under test.
     *
     * @since   2.0.0
     */
    private function submission(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/login')
            ->withParsedBody(['email' => 'owner@example.test', 'password' => 'wrong password']);
    }

    private function renderer(): AdministratorRenderer
    {
        return new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader()),
            new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(new ArrayLoader())),
        );
    }
}
