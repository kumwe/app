<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Demo\Infrastructure;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Demo\Infrastructure\DemoExampleExtensionInstaller;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;

/**
 * Proves the example installer discovers the shipped set and stays idempotent per identifier.
 *
 * The fresh-install path signs and installs through the real pipeline and is exercised end to end
 * against a database; what belongs here is the release contract around it — which examples exist,
 * that unknown names are refused, and that an already-listed identifier is confirmed or reactivated
 * instead of repackaged.
 *
 * @since  2.0.0
 */
#[CoversClass(DemoExampleExtensionInstaller::class)]
final class DemoExampleExtensionInstallerTest extends TestCase
{
    /**
     * The shipped examples discover from disk in stable alphabetical order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDiscoversTheShippedExamplesAlphabetically(): void
    {
        self::assertSame(
            [
                'announcements',
                'asset-inspection',
                'audit-listener',
                'horizon-theme',
                'minimal-administrator-template',
                'minimal-template',
            ],
            $this->installer($this->createStub(ExtensionManager::class))->available(),
        );
    }

    /**
     * An example this release does not ship is refused before any service is touched.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusesAnUnshippedExampleName(): void
    {
        $manager = $this->createMock(ExtensionManager::class);
        $manager->expects(self::never())->method('installed');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not shipped');

        $this->installer($manager)->install($this->context(), 'ecommerce');
    }

    /**
     * An identifier the registry lists as active is confirmed without another install.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConfirmsAnAlreadyActiveExampleWithoutReinstalling(): void
    {
        $manager = $this->createMock(ExtensionManager::class);
        $manager->method('installed')->willReturn([
            ['identifier' => 'kumwe/announcements-example', 'status' => 'active'],
        ]);
        $manager->expects(self::never())->method('install');
        $manager->expects(self::never())->method('activate');

        $result = $this->installer($manager)->install($this->context(), 'announcements');

        self::assertSame(
            [
                'identifier' => 'kumwe/announcements-example',
                'installed' => false,
                'activated' => false,
                'contributions' => [],
            ],
            $result,
        );
    }

    /**
     * A disabled identifier is reactivated in place rather than packaged again.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReactivatesADisabledExampleWithoutRepackaging(): void
    {
        $manager = $this->createMock(ExtensionManager::class);
        $manager->method('installed')->willReturn([
            ['identifier' => 'kumwe/audit-listener-example', 'status' => 'disabled'],
        ]);
        $manager->expects(self::never())->method('install');
        $manager->expects(self::once())->method('activate')
            ->with('kumwe/audit-listener-example')
            ->willReturn(['identifier' => 'kumwe/audit-listener-example', 'status' => 'active']);

        $result = $this->installer($manager)->install($this->context(), 'audit-listener');

        self::assertSame(
            [
                'identifier' => 'kumwe/audit-listener-example',
                'installed' => false,
                'activated' => true,
                'contributions' => [],
            ],
            $result,
        );
    }

    /**
     * Build the installer over the real repository examples with a stubbed extension pipeline.
     *
     * @param   ExtensionManager  $manager  Stubbed or mocked canonical pipeline.
     *
     * @return  DemoExampleExtensionInstaller  Installer under test.
     *
     * @since   2.0.0
     */
    private function installer(ExtensionManager $manager): DemoExampleExtensionInstaller
    {
        $trust = (new ReflectionClass(TrustStore::class))->newInstanceWithoutConstructor();
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-12T00:00:00+00:00'));

        return new DemoExampleExtensionInstaller(dirname(__DIR__, 4), $manager, $trust, $clock);
    }

    /**
     * Build the administrator context every scenario runs under.
     *
     * @return  \Kumwe\App\Application\Authorization\ExecutionContext  Provenance-bound test context.
     *
     * @since   2.0.0
     */
    private function context(): \Kumwe\App\Application\Authorization\ExecutionContext
    {
        return AuthorizationContext::human(['extensions.manage']);
    }
}
