<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Handler;

use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Handler\AdministratorExtensionActionHandler;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\Localization\Application\Translator;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;

#[CoversClass(AdministratorExtensionActionHandler::class)]
final class AdministratorExtensionActionHandlerTest extends TestCase
{
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    public function testAdministratorThemeActivationRequiresItsDedicatedCapability(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('activate')->willThrowException(
            new InsufficientCapability('themes.administrator.manage'),
        );
        $response = $this->handler($extensions)->handle($this->request(
            ['extensions.manage'],
        ));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('themes.administrator.manage', (string) $response->getBody());
    }

    public function testAdministratorThemeActivationPassesPasswordOnlyToStepUpBoundary(): void
    {
        $extensions = $this->createMock(ExtensionManager::class);
        $extensions->expects(self::once())->method('activate')->with(
            'acme/corporate',
            self::isInstanceOf(ExecutionContext::class),
            ThemeSurface::Administrator,
            'current password',
        )->willReturn(['identifier' => 'acme/corporate']);
        $response = $this->handler($extensions)->handle($this->request([
            'extensions.manage',
            'themes.administrator.manage',
        ]));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/administrator/extensions', $response->getHeaderLine('Location'));
    }

    public function testManagerCapabilityRaceIsReturnedAsForbidden(): void
    {
        $extensions = $this->createStub(ExtensionManager::class);
        $extensions->method('activate')->willThrowException(
            new InsufficientCapability('themes.administrator.manage'),
        );
        $response = $this->handler($extensions)->handle($this->request([
            'extensions.manage',
            'themes.administrator.manage',
        ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('insufficient-capability', (string) $response->getBody());
    }

    public function testReactivationNeedingASchemaPlanIsALocalizedConflict(): void
    {
        $extensions = $this->createStub(ExtensionManager::class);
        $extensions->method('activate')->willThrowException(new BusinessSchemaConflict(
            'An extension schema requires an approved synchronization plan before reactivation.',
        ));
        $translator = $this->createMock(Translator::class);
        $translator->expects(self::once())->method('translate')->with(
            'core.administrator.extensions.schema_plan_required',
            ['extension' => 'acme/corporate'],
        )->willReturn('Localized refusal');
        $response = $this->handler($extensions, $translator)->handle($this->request([
            'extensions.manage',
            'themes.administrator.manage',
        ]));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame([
            'type' => 'urn:kumwe:problem:business-schema-conflict',
            'title' => 'Conflict',
            'status' => 409,
            'detail' => 'Localized refusal',
        ], json_decode((string) $response->getBody(), true, 4, JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $capabilities */
    private function request(array $capabilities): \Psr\Http\Message\ServerRequestInterface
    {
        $principal = AuthorizationContext::principal($capabilities, self::ACTOR);
        $context = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'administrator-theme-test',
        );
        $session = new AdministratorSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb302',
            $principal,
            'csrf-token',
            new DateTimeImmutable('+1 hour'),
        );

        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/extensions/action')
            ->withParsedBody([
                'identifier' => 'acme/corporate',
                'action' => 'activate',
                'surface' => 'administrator',
                'current_password' => 'current password',
            ])
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContextAttribute::NAME, $context);
    }

    private function handler(
        ExtensionManager $extensions,
        ?Translator $translator = null,
    ): AdministratorExtensionActionHandler {
        return new AdministratorExtensionActionHandler(
            $extensions,
            (new \ReflectionClass(TrustStore::class))->newInstanceWithoutConstructor(),
            $translator ?? $this->createStub(Translator::class),
        );
    }
}
