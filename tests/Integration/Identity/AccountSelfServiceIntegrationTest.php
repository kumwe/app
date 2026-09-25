<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Identity;

use Kumwe\App\Administrator\Http\Handler\AdministratorAccountHandler;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\App\Portal\Application\PortalPasswordIdentity;
use Kumwe\App\Portal\Application\PortalSessionStore;
use Kumwe\App\Portal\Http\Handler\PortalAccountHandler;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Access\Capability;
use Kumwe\Context\Value\AuthenticationStrength;
use Laminas\Diactoros\ServerRequestFactory;
use Mezzio\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * Exercises ordinary-user password replacement through both complete HTTP authentication pipelines.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorAccountHandler::class)]
#[CoversClass(PortalAccountHandler::class)]
final class AccountSelfServiceIntegrationTest extends TestCase
{
    /**
     * Select each independently authenticated browser surface.
     *
     * @return  array<string, array{string}>  Both delivery pipelines.
     *
     * @since   2.0.0
     */
    public static function surfaces(): array
    {
        return ['administrator' => ['administrator'], 'portal' => ['portal']];
    }

    /**
     * Refuse cross-site and invalid changes, then retire every old credential after a valid replacement.
     *
     * @param   string  $area  Browser authentication surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('surfaces')]
    public function testAnOrdinaryUserCanChangeOnlyTheirOwnPassword(string $area): void
    {
        [$application, $request, $identities, $tokens, $email, $token, $csrf] = $this->fixture($area);
        $form = [
            'current_password' => 'the original passphrase',
            'new_password' => 'a replacement passphrase',
            'new_password_confirmation' => 'a replacement passphrase',
            'user_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
        ];
        $page = $application->handle($request);
        self::assertSame(200, $page->getStatusCode(), (string) $page->getBody());
        self::assertStringContainsString('name="current_password"', (string) $page->getBody());
        self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));
        $post = $request->withMethod('POST');
        self::assertSame(403, $application->handle($post->withParsedBody($form))->getStatusCode());
        $form['_csrf'] = $csrf;
        foreach (
            [
            ['new_password_confirmation' => 'another replacement passphrase'],
            ['current_password' => 'a wrong current passphrase'],
            ['new_password' => 'short', 'new_password_confirmation' => 'short'],
            [
                'new_password' => 'the original passphrase',
                'new_password_confirmation' => 'the original passphrase',
            ],
            ] as $invalid
        ) {
            $response = $application->handle($post->withParsedBody(array_replace($form, $invalid)));
            self::assertSame(422, $response->getStatusCode());
            self::assertStringNotContainsString('value="the original passphrase"', (string) $response->getBody());
            self::assertStringNotContainsString('value="a replacement passphrase"', (string) $response->getBody());
        }
        self::assertNotNull($tokens->verify($token));
        $changed = $application->handle($post->withParsedBody($form));
        self::assertSame(303, $changed->getStatusCode());
        self::assertSame('/' . $area . '/login?password_changed=1', $changed->getHeaderLine('Location'));
        self::assertStringContainsString('Max-Age=0; HttpOnly; SameSite=Strict', $changed->getHeaderLine('Set-Cookie'));
        self::assertSame(303, $application->handle($request)->getStatusCode());
        self::assertNull($tokens->verify($token));
        self::assertNull($identities->authenticate($email, 'the original passphrase', 'account-test'));
        self::assertNotNull($identities->authenticate($email, 'a replacement passphrase', 'account-test'));
    }

    /**
     * Bound current-password guessing through the same production throttle on each browser route.
     *
     * @param   string  $area  Browser authentication surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('surfaces')]
    public function testWrongCurrentPasswordsAreThrottled(string $area): void
    {
        [$application, $request, , , , , $csrf] = $this->fixture($area);
        $request = $request->withMethod('POST')->withParsedBody([
            '_csrf' => $csrf,
            'current_password' => 'a wrong current passphrase',
            'new_password' => 'a replacement passphrase',
            'new_password_confirmation' => 'a replacement passphrase',
        ]);
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            self::assertSame(422, $application->handle($request)->getStatusCode());
        }
        $response = $application->handle($request);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('900', $response->getHeaderLine('Retry-After'));
    }

    /**
     * Provision a fresh person with only browser access and content read, never user-management authority.
     *
     * @param   string  $area  Browser authentication surface.
     *
     * @return  array{Application, ServerRequestInterface, AdministratorIdentityGateway, AccessTokenVerifier,
     *          string, string, string}  Live application, authenticated request, credential adapters and fixture facts.
     *
     * @since   2.0.0
     */
    private function fixture(string $area): array
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $authority = TestKernelFactory::administratorContext($container);
        $access = $container->get(AccessControlService::class);
        $identities = $container->get(AdministratorIdentityGateway::class);
        $tokens = $container->get(AccessTokenVerifier::class);
        $application = $container->get(Application::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        self::assertInstanceOf(AccessTokenVerifier::class, $tokens);
        self::assertInstanceOf(Application::class, $application);
        $marker = Uuid::uuid7()->toString();
        $email = 'account-' . $marker . '@example.test';
        $user = $access->createUser($authority, $email, 'Account owner', 'the original passphrase');
        $role = $access->createRole($authority, 'account-' . $marker, 'Ordinary account access');
        foreach (['administrator.access', 'portal.access', 'content.read'] as $capability) {
            $access->grant($authority, $role, $capability);
        }
        $access->assignRole($authority, $user, $role);
        $principal = $identities->authenticate($email, 'the original passphrase', 'account-test');
        self::assertNotNull($principal);
        self::assertFalse($principal->hasCapability(Capability::fromString('users.manage')));
        $token = $identities->issueAccessToken($authority, $email, 'Old credential', ['content.read']);
        $agent = 'Account self-service browser';
        if ($area === 'administrator') {
            $sessions = $container->get(AdministratorSessionStore::class);
            self::assertInstanceOf(AdministratorSessionStore::class, $sessions);
            $issued = $sessions->create(
                $principal->context($authority->site(), AuthenticationStrength::Password, 'account-test'),
                $agent,
            );
            $cookie = $issued->token;
            $csrf = $issued->session->csrfToken;
        } else {
            $sessions = $container->get(PortalSessionStore::class);
            self::assertInstanceOf(PortalSessionStore::class, $sessions);
            $issued = $sessions->create(
                new PortalPasswordIdentity($principal, $principal->securityEpoch()),
                new PortalContext($authority->site(), null),
                $agent,
            );
            $cookie = $issued->cookieToken;
            $csrf = $issued->session->csrfToken;
        }
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            rtrim(Environment::fromGlobals()->string('APP_BASE_URL'), '/') . '/' . $area . '/account',
        )
            ->withHeader('User-Agent', $agent)
            ->withCookieParams(['kumwe_' . $area => $cookie]);

        return [$application, $request, $identities, $tokens, $email, $token['token'], $csrf];
    }
}
