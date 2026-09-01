<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Presentation;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\App\Administrator\Presentation\AdministratorContributionRenderer;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\App\Application\Authorization\AuthenticatedSurface;
use Kumwe\App\Application\Authorization\AuthenticationStrength;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Extension\Contribution\AdministratorViewRegistry;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\IsolatedTwigEnvironmentFactory;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteRenderer;
use Kumwe\Extension\Spi\Contribution\AdministratorViewDefinition;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;
use Twig\Loader\ArrayLoader;

#[CoversClass(AdministratorContributionRenderer::class)]
final class AdministratorContributionRendererTest extends TestCase
{
    public function testCapabilitiesAreRouteBoundAndCannotCrossRender(): void
    {
        [$renderer, $owner, $provenance] = $this->renderer();
        $first = $renderer->forExtensionRoute($owner->identifier(), 'acme.routes.one');
        $second = $renderer->forExtensionRoute($owner->identifier(), 'acme.routes.two');
        $request = $this->request($provenance, AuthenticatedSurface::Administrator);

        self::assertInstanceOf(AdministratorRouteRenderer::class, $first);
        self::assertNotSame($first, $second);
        self::assertSame(
            'one|safe|csrf-safe|acme.routes.one|yes',
            $first->render([
                'marker' => 'safe',
                'csrf' => 'forged',
                'active_navigation' => 'acme.routes.two',
                'capabilities' => ['acme.routes.forged' => true],
                'view' => 'acme.routes.two',
            ], $request),
        );
        self::assertSame(
            'two|safe|csrf-safe|acme.routes.two|yes',
            $second->render(['marker' => 'safe', 'view' => 'acme.routes.one'], $request),
        );
    }

    public function testFabricatedOrWrongSurfaceRequestsAreRefused(): void
    {
        [$renderer, $owner, $provenance] = $this->renderer();
        $capability = $renderer->forExtensionRoute($owner->identifier(), 'acme.routes.one');
        $fabricated = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator/extensions/acme/routes')
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, new stdClass())
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, new stdClass());

        $this->assertRefused(static fn (): string => $capability->render([], $fabricated));
        $this->assertRefused(fn (): string => $capability->render(
            [],
            $this->request(new stdClass(), AuthenticatedSurface::Administrator),
        ));
        $this->assertRefused(fn (): string => $capability->render(
            [],
            $this->request($provenance, AuthenticatedSurface::Portal),
        ));
    }

    /** @return array{AdministratorRenderer, ContributionOwner, object} */
    private function renderer(): array
    {
        $provenance = new stdClass();
        $owner = ContributionOwner::extension('acme/routes');
        $views = new AdministratorViewRegistry();
        $views->register($owner, new AdministratorViewDefinition('acme.routes.one', 'one.twig'));
        $views->register($owner, new AdministratorViewDefinition('acme.routes.two', 'two.twig'));
        $namespace = IsolatedTwigEnvironmentFactory::extensionNamespace($owner->identifier());
        $template = static fn (string $name): string => $name
            . '|{{ marker }}|{{ csrf }}|{{ active_navigation }}|'
            . "{{ capabilities['acme.routes.open'] ? 'yes' : 'no' }}";
        $twig = new AdministratorTwigEnvironment(new ArrayLoader([
            '@' . $namespace . '/one.twig' => $template('one'),
            '@' . $namespace . '/two.twig' => $template('two'),
        ]));

        return [new AdministratorRenderer(
            $twig,
            new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(new ArrayLoader())),
            AdministratorNavigationRegistry::core(),
            extensionViews: $views,
            extensionRequestProvenance: $provenance,
        ), $owner, $provenance];
    }

    private function request(object $provenance, AuthenticatedSurface $surface): ServerRequestInterface
    {
        $principal = AuthenticatedPrincipal::issueFromStrings(
            $provenance,
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
            ['acme.routes.open'],
        );
        $session = new AdministratorSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb400',
            $principal,
            'csrf-safe',
            new DateTimeImmutable('+1 hour'),
        );
        $context = ExecutionContext::issueHuman(
            $provenance,
            $principal,
            SiteContext::default(),
            AuthenticationStrength::Password,
            'route-render-request',
            surface: $surface,
            sessionId: $session->id,
        );

        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator/extensions/acme/routes')
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context);
    }

    /** @param callable(): string $render */
    private function assertRefused(callable $render): void
    {
        try {
            $render();
            self::fail('An untrusted administrator rendering request must be refused.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
