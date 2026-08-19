<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversNothing]
final class ExtensionContributionBoundaryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testDeliveryCodeAndTemplatesCannotMutateContributionRegistries(): void
    {
        foreach (
            [
                'src/Administrator/Http/Handler',
                'src/Delivery',
                'examples/extensions/announcements/src/Delivery',
            ] as $directory
        ) {
            foreach ($this->phpFiles($directory) as $file) {
                $source = $this->contents($file);
                self::assertStringNotContainsString('ExtensionContributionRegistrySet', $source, $file);
                self::assertStringNotContainsString('OwnedExtensionContributionRegistrar', $source, $file);
                self::assertDoesNotMatchRegularExpression('/->(?:registerOwned|registrar)\s*\(/', $source, $file);
            }
        }

        foreach ($this->files('templates', 'twig') as $file) {
            self::assertDoesNotMatchRegularExpression(
                '/(?:contribution|navigation|route|view)Registry/i',
                $this->contents($file),
                $file,
            );
        }
    }

    public function testExtensionsReceiveAnOwnerBoundRegistrarOnlyDuringContributionPhase(): void
    {
        $loader = $this->contents('src/Extension/Runtime/ExtensionRuntimeLoader.php');
        $active = $this->contents('src/Extension/Runtime/ActiveExtensionSet.php');
        $container = $this->contents('src/Kernel/ContainerFactory.php');

        self::assertStringContainsString('$active->contribute();', $loader);
        self::assertStringContainsString('$active->boot();', $loader);
        self::assertLessThan(strpos($loader, '$active->boot();'), strpos($loader, '$active->contribute();'));
        self::assertStringContainsString('ContributionOwner::extension($extension[\'identifier\'])', $active);
        self::assertStringNotContainsString(
            'AdministratorNavigationRegistry::class =>',
            $this->runtimeAllowlist($container),
        );
        self::assertSame(1, substr_count($container, 'new ExtensionContributionRegistrySet('));
    }

    /**
     * Trusted custom handlers receive the canonical record boundary without privileged infrastructure.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRuntimeAllowlistExposesCanonicalBusinessServiceOnly(): void
    {
        $container = $this->contents('src/Kernel/ContainerFactory.php');
        $allowlist = $this->runtimeAllowlist($container);

        self::assertStringContainsString('BusinessRecordService::class =>', $allowlist);
        self::assertStringNotContainsString('Connection::class =>', $allowlist);
        self::assertStringNotContainsString('Repository::class =>', $allowlist);
        self::assertStringNotContainsString('Container::class =>', $allowlist);
    }

    public function testRecoveryCompositionCannotLoadExtensionCodeOrExtensionContributions(): void
    {
        $container = $this->contents('src/Kernel/ContainerFactory.php');
        self::assertStringContainsString('return $this->build($environment, true, false);', $container);
        self::assertStringContainsString('$loadRuntime', $container);
        self::assertStringContainsString(': new ActiveExtensionSet(', $container);

        $twig = $this->contents('src/Presentation/Twig/IsolatedTwigEnvironmentFactory.php');
        $recovery = substr($twig, (int) strpos($twig, 'public function recoveryAdministrator'));
        $recovery = substr($recovery, 0, (int) strpos($recovery, 'private function surfaceLoader'));
        self::assertStringNotContainsString('extensionViewPaths', $recovery);
        self::assertStringNotContainsString('extensionNamespace', $recovery);
    }

    public function testGatewayContainsNoClosedActionResourceOrSystemCapabilityCatalog(): void
    {
        $gateway = $this->contents('src/Application/Authorization/DenyByDefaultAuthorizationGateway.php');
        $registry = $this->contents('src/Application/Authorization/AuthorizationPolicyRegistry.php');
        $core = $this->contents('src/Extension/Contribution/CoreExtensionContributions.php');

        self::assertStringNotContainsString('SYSTEM_CAPABILITIES', $gateway);
        self::assertStringNotContainsString('INSTALLATION_GLOBAL_SYSTEM_IDENTITIES', $gateway);
        self::assertStringNotContainsString('ACTION_RESOURCES', $registry);
        self::assertStringContainsString('$registrar->capability(', $core);
        self::assertStringContainsString('$registrar->resourcePolicy(', $core);
    }

    public function testAdministratorProvisioningDerivesCapabilitiesFromTheLiveTypedCatalog(): void
    {
        $gateway = $this->contents(
            'src/Identity/Infrastructure/Administration/DoctrineAdministratorIdentityGateway.php',
        );

        self::assertStringNotContainsString('ADMINISTRATOR_CAPABILITIES', $gateway);
        self::assertStringContainsString(
            "capabilityDefinitions()->ownedBy('core')",
            $gateway,
        );
        self::assertStringContainsString('$definition->allowsHumanGrant()', $gateway);
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        return $this->files($directory, 'php');
    }

    /** @return list<string> */
    private function files(string $directory, string $extension): array
    {
        $result = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root . '/' . $directory));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === $extension) {
                $result[] = substr($file->getPathname(), strlen($this->root) + 1);
            }
        }
        sort($result, SORT_STRING);
        return $result;
    }

    private function runtimeAllowlist(string $container): string
    {
        $start = strpos($container, '))->load([');
        self::assertNotFalse($start);
        $end = strpos($container, '], $contributionRegistries)', $start);
        self::assertNotFalse($end);
        return substr($container, $start, $end - $start);
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, sprintf('Could not read %s.', $path));
        return $contents;
    }
}
