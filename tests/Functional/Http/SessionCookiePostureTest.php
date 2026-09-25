<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Http;

use Kumwe\App\Administrator\Http\Handler\AdministratorLoginHandler;
use Kumwe\App\Administrator\Http\Handler\AdministratorLogoutHandler;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\App\Portal\Application\PortalPasswordIdentity;
use Kumwe\App\Portal\Application\PortalSessionStore;
use Kumwe\App\Portal\Http\Handler\PortalLoginHandler;
use Kumwe\App\Portal\Http\Handler\PortalLogoutHandler;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Mezzio\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClassConstant;

/**
 * Pins the exact `Set-Cookie` attribute strings the live pipeline emits on both browser surfaces.
 *
 * The cookie attributes are what keeps a session token off the wire in the clear and away from
 * scripts and cross-site requests, and the `Secure` flag is decided by the container from the
 * configured base URL rather than by any handler. Two kernels are therefore booted here, one behind an
 * `https://` base URL and one behind plain `http://`, and each is driven through the real pipeline: the
 * sign-in form's protected token cookie, the session cookie a successful sign-in issues, the clearing
 * cookie a sign-out answers with, and the refusal a sign-out without its token meets. Only the
 * `https://` kernel may add `Secure`; every other attribute is identical across postures.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorLoginHandler::class)]
#[CoversClass(AdministratorLogoutHandler::class)]
#[CoversClass(PortalLoginHandler::class)]
#[CoversClass(PortalLogoutHandler::class)]
#[CoversClass(ContainerFactory::class)]
final class SessionCookiePostureTest extends TestCase
{
    /**
     * Browser user agent every session in this test is bound to.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string USER_AGENT = 'Kumwe cookie posture test browser';

    /**
     * The administrator sign-in, session and sign-out cookies carry the exact attributes for each transport posture.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorCookiesCarryTheExactAttributesForEachTransportPosture(): void
    {
        TestKernelFactory::create(Environment::fromGlobals());
        foreach (['https' => '; Secure', 'http' => ''] as $scheme => $secure) {
            $container = $this->kernel($scheme);
            $application = $this->application($container);
            $origin = $scheme . '://kumwe.test';

            $form = $application->handle($this->request('GET', $origin . '/administrator/login'));
            self::assertSame(200, $form->getStatusCode(), $scheme);
            self::assertMatchesRegularExpression(
                '/^kumwe_administrator_login_csrf=[A-Za-z0-9_-]{43}; Path=\/administrator\/login; Max-Age=600; '
                    . 'HttpOnly; SameSite=Strict' . preg_quote($secure, '/') . '$/D',
                $form->getHeaderLine('Set-Cookie'),
                $scheme,
            );
            $loginToken = $this->csrfField((string) $form->getBody());

            $signedIn = $application->handle(
                $this->request('POST', $origin . '/administrator/login')
                    ->withCookieParams([AdministratorLoginHandler::LOGIN_CSRF_COOKIE_NAME => $loginToken])
                    ->withParsedBody([
                        'email' => TestKernelFactory::ADMINISTRATOR_EMAIL,
                        'password' => TestKernelFactory::ADMINISTRATOR_PASSWORD,
                        '_csrf' => $loginToken,
                    ]),
            );
            self::assertSame(303, $signedIn->getStatusCode(), $scheme);
            self::assertSame('/administrator', $signedIn->getHeaderLine('Location'));
            $cookies = $signedIn->getHeader('Set-Cookie');
            self::assertCount(2, $cookies, $scheme);
            self::assertMatchesRegularExpression(
                '/^kumwe_administrator=[A-Za-z0-9_-]{43,512}; Path=\/administrator; Max-Age=[1-9][0-9]*; '
                    . 'HttpOnly; SameSite=Strict' . preg_quote($secure, '/') . '$/D',
                $cookies[0],
                $scheme,
            );
            self::assertSame(
                'kumwe_administrator_login_csrf=; Path=/administrator/login; Max-Age=0; HttpOnly; SameSite=Strict'
                    . $secure,
                $cookies[1],
                $scheme,
            );
            $sessionToken = $this->cookieValue($cookies[0]);

            $dashboard = $application->handle(
                $this->request('GET', $origin . '/administrator')
                    ->withCookieParams(['kumwe_administrator' => $sessionToken]),
            );
            self::assertSame(200, $dashboard->getStatusCode(), $scheme);
            self::assertSame('', $dashboard->getHeaderLine('Set-Cookie'), 'A page view never reissues the cookie.');
            $csrf = $this->csrfField((string) $dashboard->getBody());

            $forged = $application->handle(
                $this->request('POST', $origin . '/administrator/logout')
                    ->withCookieParams(['kumwe_administrator' => $sessionToken])
                    ->withParsedBody(['_csrf' => 'not-the-token']),
            );
            self::assertSame(403, $forged->getStatusCode(), 'Sign-out is a deliberate act behind the CSRF guard.');
            self::assertSame('', $forged->getHeaderLine('Set-Cookie'));
            self::assertSame(200, $application->handle(
                $this->request('GET', $origin . '/administrator')
                    ->withCookieParams(['kumwe_administrator' => $sessionToken]),
            )->getStatusCode(), 'A refused sign-out leaves the session intact.');

            $signedOut = $application->handle(
                $this->request('POST', $origin . '/administrator/logout')
                    ->withCookieParams(['kumwe_administrator' => $sessionToken])
                    ->withParsedBody(['_csrf' => $csrf]),
            );
            self::assertSame(303, $signedOut->getStatusCode(), $scheme);
            self::assertSame('/administrator/login', $signedOut->getHeaderLine('Location'));
            self::assertSame(
                ['kumwe_administrator=deleted; Path=/administrator; Max-Age=0; HttpOnly; SameSite=Strict' . $secure],
                $signedOut->getHeader('Set-Cookie'),
                $scheme,
            );
            $afterwards = $application->handle(
                $this->request('GET', $origin . '/administrator')
                    ->withCookieParams(['kumwe_administrator' => $sessionToken]),
            );
            self::assertSame(303, $afterwards->getStatusCode(), 'The stored session is gone, not just the cookie.');
            self::assertSame('/administrator/login', $afterwards->getHeaderLine('Location'));
        }
    }

    /**
     * The portal sign-in token cookie and sign-out clearing cookie carry the exact attributes for each posture.
     *
     * The portal session itself is issued through the store, because a pipeline sign-in needs an
     * organization membership the integration administrator does not hold; the cookie the sign-in
     * handler would issue is pinned exactly by its unit test. What the pipeline proves here is the
     * container-decided `Secure` flag on the token cookie and the clearing cookie, and that a sign-out
     * without the portal token is refused while the session stays alive.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPortalCookiesCarryTheExactAttributesForEachTransportPosture(): void
    {
        $seed = TestKernelFactory::create(Environment::fromGlobals());
        $authority = TestKernelFactory::administratorContext($seed);
        $principal = AuthenticatedPrincipal::of($authority);
        self::assertNotNull($principal);
        foreach (['https' => '; Secure', 'http' => ''] as $scheme => $secure) {
            $container = $this->kernel($scheme);
            $application = $this->application($container);
            $origin = $scheme . '://kumwe.test';

            $form = $application->handle($this->request('GET', $origin . '/portal/login'));
            self::assertSame(200, $form->getStatusCode(), $scheme);
            self::assertMatchesRegularExpression(
                '/^kumwe_portal_login_csrf=[A-Za-z0-9_-]{43}; Path=\/portal\/login; Max-Age=600; '
                    . 'HttpOnly; SameSite=Strict' . preg_quote($secure, '/') . '$/D',
                $form->getHeaderLine('Set-Cookie'),
                $scheme,
            );

            $sessions = $container->get(PortalSessionStore::class);
            self::assertInstanceOf(PortalSessionStore::class, $sessions);
            $issued = $sessions->create(
                new PortalPasswordIdentity($principal, $principal->securityEpoch()),
                new PortalContext($authority->site(), null),
                self::USER_AGENT,
            );

            $home = $application->handle(
                $this->request('GET', $origin . '/portal')->withCookieParams(['kumwe_portal' => $issued->cookieToken]),
            );
            self::assertSame(200, $home->getStatusCode(), $scheme);
            self::assertSame('', $home->getHeaderLine('Set-Cookie'), 'A page view never reissues the cookie.');

            $forged = $application->handle(
                $this->request('POST', $origin . '/portal/logout')
                    ->withCookieParams(['kumwe_portal' => $issued->cookieToken])
                    ->withParsedBody(['_csrf' => 'not-the-token']),
            );
            self::assertSame(403, $forged->getStatusCode(), 'Sign-out is a deliberate act behind the CSRF guard.');
            self::assertSame('', $forged->getHeaderLine('Set-Cookie'));

            $signedOut = $application->handle(
                $this->request('POST', $origin . '/portal/logout')
                    ->withCookieParams(['kumwe_portal' => $issued->cookieToken])
                    ->withParsedBody(['_csrf' => $issued->session->csrfToken]),
            );
            self::assertSame(303, $signedOut->getStatusCode(), $scheme);
            self::assertSame('/portal/login', $signedOut->getHeaderLine('Location'));
            self::assertSame(
                ['kumwe_portal=; Path=/portal; Max-Age=0; HttpOnly; SameSite=Strict' . $secure],
                $signedOut->getHeader('Set-Cookie'),
                $scheme,
            );
            $afterwards = $application->handle(
                $this->request('GET', $origin . '/portal')->withCookieParams(['kumwe_portal' => $issued->cookieToken]),
            );
            self::assertSame(303, $afterwards->getStatusCode(), 'The stored session is gone, not just the cookie.');
            self::assertSame('/portal/login', $afterwards->getHeaderLine('Location'));
        }
    }

    /**
     * Boot a full kernel whose base URL uses the given scheme, keeping every other setting of this deployment.
     *
     * @param   string  $scheme  `https` or `http`.
     *
     * @return  Container  Booted application container.
     *
     * @since   2.0.0
     */
    private function kernel(string $scheme): Container
    {
        $globals = Environment::fromGlobals();
        $keys = (new ReflectionClassConstant(Environment::class, 'PROCESS_KEYS'))->getValue();
        self::assertIsArray($keys);
        $values = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $value = $globals->optionalString($key);
            if ($value !== null) {
                $values[$key] = $value;
            }
        }
        $values['APP_BASE_URL'] = $scheme . '://kumwe.test';
        $values['APP_TRUSTED_HOSTS'] = 'kumwe.test';

        return (new ContainerFactory())->create(new Environment($values));
    }

    /**
     * Resolve the HTTP application from a booted container.
     *
     * @param   Container  $container  Booted container.
     *
     * @return  Application  The Mezzio pipeline.
     *
     * @since   2.0.0
     */
    private function application(Container $container): Application
    {
        $application = $container->get(Application::class);
        self::assertInstanceOf(Application::class, $application);

        return $application;
    }

    /**
     * Build a request bound to the test browser and the trusted host.
     *
     * @param   string  $method  HTTP method.
     * @param   string  $uri     Absolute URI on the kernel's origin.
     *
     * @return  ServerRequestInterface  Request carrying the host and user agent.
     *
     * @since   2.0.0
     */
    private function request(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withHeader('Host', 'kumwe.test')
            ->withHeader('User-Agent', self::USER_AGENT);
    }

    /**
     * Read the first `_csrf` hidden field out of a rendered page.
     *
     * @param   string  $html  Rendered page.
     *
     * @return  string  The token the page echoes.
     *
     * @since   2.0.0
     */
    private function csrfField(string $html): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $html, $matches);
        $token = $matches[1] ?? null;
        self::assertIsString($token, 'The page carries a token.');

        return $token;
    }

    /**
     * Read the value out of a `Set-Cookie` header line.
     *
     * @param   string  $cookie  Header line.
     *
     * @return  string  Cookie value before the first attribute.
     *
     * @since   2.0.0
     */
    private function cookieValue(string $cookie): string
    {
        preg_match('/^[^=]+=([^;]+);/', $cookie, $matches);
        $value = $matches[1] ?? null;
        self::assertIsString($value);

        return $value;
    }
}
