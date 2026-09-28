<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Extension;

use Kumwe\Access\AuthorizationDenied;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\App\Delivery\Http\Api\Extension\ExtensionApiHandler;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\UntrustedPackage;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(ExtensionApiHandler::class)]
final class ExtensionApiHandlerTest extends TestCase
{
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    /**
     * Remove the staging directory an install test created; the handler leaves it empty.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        if (is_dir(self::staging())) {
            rmdir(self::staging());
        }
    }

    public function testRestActivationPassesAuthorizedSiteSurface(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('activate')->with(
            'acme/corporate',
            self::isInstanceOf(ExecutionContext::class),
            ThemeSurface::Site,
            null,
        )->willReturn(['identifier' => 'acme/corporate', 'status' => 'active']);
        $handler = new ExtensionApiHandler(
            $extensions,
            new ProblemDetailsResponseFactory(),
            self::staging(),
        );
        $response = $handler->handle($this->request(['extensions.manage', 'themes.site.manage']));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRestAdministratorActivationForwardsCurrentPassword(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('activate')->with(
            'acme/corporate',
            self::isInstanceOf(ExecutionContext::class),
            ThemeSurface::Administrator,
            'correct horse battery staple',
        )->willReturn(['identifier' => 'acme/corporate', 'status' => 'active']);
        $handler = new ExtensionApiHandler(
            $extensions,
            new ProblemDetailsResponseFactory(),
            self::staging(),
        );
        $response = $handler->handle($this->request(
            ['extensions.manage', 'themes.administrator.manage'],
            'administrator',
            'correct horse battery staple',
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRestActivationRejectsMissingSurfaceCapability(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('activate')->willThrowException(
            $this->authorizationDenied('themes.site.manage'),
        );
        $handler = new ExtensionApiHandler(
            $extensions,
            new ProblemDetailsResponseFactory(),
            self::staging(),
        );
        $response = $handler->handle($this->request(['extensions.manage']));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('themes.site.manage', (string) $response->getBody());
    }

    public function testRestMapsStepUpThrottlingToControlledProblem(): void
    {
        $extensions = $this->createStub(ExtensionManager::class);
        $extensions->method('activate')->willThrowException(new AuthenticationThrottled());
        $handler = new ExtensionApiHandler(
            $extensions,
            new ProblemDetailsResponseFactory(),
            self::staging(),
        );
        $response = $handler->handle($this->request(['extensions.manage', 'themes.site.manage']));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('900', $response->getHeaderLine('Retry-After'));
    }

    public function testRestDisableRejectsMissingActiveSiteThemeCapability(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('disable')->willThrowException(
            $this->authorizationDenied('themes.site.manage'),
        );
        $handler = new ExtensionApiHandler(
            $extensions,
            new ProblemDetailsResponseFactory(),
            self::staging(),
        );
        $response = $handler->handle($this->request(['extensions.manage'], action: 'disable'));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('themes.site.manage', (string) $response->getBody());
    }

    public function testRestUninstallForwardsAdministratorStepUp(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->method('installed')->willReturn([[
            'identifier' => 'acme/corporate',
            'extension_type' => 'template',
            'theme_surfaces' => ['administrator'],
        ]]);
        $extensions->expects(self::once())->method('uninstall')->with(
            'acme/corporate',
            self::isInstanceOf(ExecutionContext::class),
            'correct horse battery staple',
        );
        $handler = new ExtensionApiHandler(
            $extensions,
            new ProblemDetailsResponseFactory(),
            self::staging(),
        );
        $response = $handler->handle($this->request(
            ['extensions.manage', 'themes.administrator.manage'],
            currentPassword: 'correct horse battery staple',
            action: 'uninstall',
            method: 'DELETE',
        ));

        self::assertSame(204, $response->getStatusCode());
    }

    /**
     * An install stages the raw body privately, forwards the signature pair, answers the listed row and cleans up.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRestInstallStagesTheBodyAndForwardsTheSignaturePair(): void
    {
        $staged = [];
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::exactly(2))->method('install')->willReturnCallback(
            static function (
                string $archive,
                ExecutionContext $context,
                ?string $keyId,
                ?string $signature,
            ) use (&$staged): array {
                $staged[] = [$archive, file_get_contents($archive), $keyId, $signature, $context->actorId()];

                return ['id' => 'registry-row', 'identifier' => 'acme/corporate', 'status' => 'disabled'];
            },
        );
        $listed = ['identifier' => 'acme/corporate', 'extension_type' => 'plugin', 'status' => 'disabled'];
        $extensions->method('installed')->willReturnOnConsecutiveCalls([], [['identifier' => 'acme/other'], $listed]);
        $handler = new ExtensionApiHandler($extensions, new ProblemDetailsResponseFactory(), self::staging());

        $unsigned = $handler->handle($this->installRequest('PK-unsigned', []));
        $signed = $handler->handle($this->installRequest('PK-signed', ['key_id' => 'acme', 'signature' => 'c2ln']));

        self::assertSame(201, $unsigned->getStatusCode());
        self::assertSame(201, $signed->getStatusCode());
        self::assertSame('no-store', $signed->getHeaderLine('Cache-Control'));
        self::assertSame(
            ['identifier' => 'acme/corporate', 'status' => 'disabled'],
            json_decode((string) $unsigned->getBody(), true),
        );
        self::assertSame($listed, json_decode((string) $signed->getBody(), true));
        self::assertSame(['PK-unsigned', null, null, self::ACTOR], array_slice($staged[0], 1));
        self::assertSame(['PK-signed', 'acme', 'c2ln', self::ACTOR], array_slice($staged[1], 1));
        foreach ($staged as [$archive]) {
            self::assertStringStartsWith(self::staging() . '/extension-', $archive);
            self::assertFileDoesNotExist($archive);
        }
    }

    /**
     * Stray or half-supplied query members, untrusted packages and denials are refused as the screen refuses.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRestInstallRefusalsLeaveNoStagedPackage(): void
    {
        $extensions = $this->createStub(ExtensionManager::class);
        $extensions->method('install')->willReturnCallback(static function (string $archive): array {
            throw match (file_get_contents($archive)) {
                'untrusted' => new UntrustedPackage('The package signature does not verify.'),
                default => new AuthorizationDenied(
                    self::ACTOR,
                    'extensions.manage',
                    'extension',
                    '*',
                    'default',
                    'extension-install',
                    'missing-capability',
                ),
            };
        });
        $handler = new ExtensionApiHandler($extensions, new ProblemDetailsResponseFactory(), self::staging());
        $cases = [
            ['PK', ['surface' => 'site'], 422, 'urn:kumwe:problem:validation-failed'],
            ['PK', ['key_id' => 'acme'], 422, 'urn:kumwe:problem:validation-failed'],
            ['PK', ['signature' => 'c2ln'], 422, 'urn:kumwe:problem:validation-failed'],
            ['PK', ['key_id' => '', 'signature' => 'c2ln'], 422, 'urn:kumwe:problem:validation-failed'],
            ['untrusted', [], 422, 'urn:kumwe:problem:validation-failed'],
            ['denied', [], 403, 'urn:kumwe:problem:authorization-denied'],
        ];
        foreach ($cases as [$body, $query, $status, $type]) {
            $response = $handler->handle($this->installRequest($body, $query));
            $problem = json_decode((string) $response->getBody(), true);

            self::assertSame($status, $response->getStatusCode(), json_encode($query) . ' ' . $body);
            self::assertIsArray($problem);
            self::assertSame($type, $problem['type']);
        }
        self::assertSame([], glob(self::staging() . '/extension-*') ?: []);
    }

    /**
     * Build one authenticated install request carrying a raw package body.
     *
     * @param   string                 $package  Raw package bytes.
     * @param   array<string, string>  $query    Query members.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  Install request.
     *
     * @since   2.0.0
     */
    private function installRequest(string $package, array $query): \Psr\Http\Message\ServerRequestInterface
    {
        $principal = AuthorizationContext::principal(['extensions.manage'], self::ACTOR);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/extensions?' . http_build_query($query))
            ->withQueryParams($query)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $principal->context(
                \Kumwe\Context\Value\SiteContext::default(),
                \Kumwe\Context\Value\AuthenticationStrength::BearerToken,
                'extension-install-test-request',
            ))
            ->withBody((new StreamFactory())->createStream($package));
    }

    /**
     * Private staging directory the handler under test writes packages into.
     *
     * @return  string  Absolute directory path.
     *
     * @since   2.0.0
     */
    private static function staging(): string
    {
        return sys_get_temp_dir() . '/kumwe-extension-api-handler-test-' . getmypid();
    }

    /** @param list<string> $capabilities */
    private function request(
        array $capabilities,
        string $surface = 'site',
        ?string $currentPassword = null,
        string $action = 'activate',
        string $method = 'POST',
    ): \Psr\Http\Message\ServerRequestInterface {
        $body = $action === 'activate' ? ['surface' => $surface] : [];
        if ($currentPassword !== null) {
            $body['current_password'] = $currentPassword;
        }

        $principal = AuthorizationContext::principal($capabilities, self::ACTOR);
        $context = $principal->context(
            \Kumwe\Context\Value\SiteContext::default(),
            \Kumwe\Context\Value\AuthenticationStrength::BearerToken,
            'theme-api-test-request',
        );

        return (new ServerRequestFactory())
            ->createServerRequest($method, 'https://kumwe.test/api/v1/extensions/acme/corporate/' . $action)
            ->withAttribute('vendor', 'acme')
            ->withAttribute('name', 'corporate')
            ->withAttribute(
                AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
                $principal,
            )
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withBody((new StreamFactory())->createStream(json_encode(
                $body === [] ? (object) [] : $body,
                JSON_THROW_ON_ERROR,
            )));
    }

    private function authorizationDenied(string $capability): AuthorizationDenied
    {
        return new AuthorizationDenied(
            self::ACTOR,
            $capability,
            'theme',
            'acme/corporate',
            'default',
            'theme-mutation',
            'missing-capability',
        );
    }
}
