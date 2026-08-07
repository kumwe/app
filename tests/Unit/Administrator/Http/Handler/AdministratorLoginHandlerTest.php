<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Administrator\Http\Handler;

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

    private function renderer(): AdministratorRenderer
    {
        return new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader()),
            new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(new ArrayLoader())),
        );
    }
}
