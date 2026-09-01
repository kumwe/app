<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves that the extension contract has one package owner rather than a translated App copy.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class ExtensionContractGateTest extends TestCase
{
    /**
     * Absolute repository root every checked artifact path is resolved against.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Bind the repository root before each check.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * Prove the App keeps no contract ledger of its own and names the SDK package sole authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSdkDependencyOwnsEveryContractArtifact(): void
    {
        self::assertFileDoesNotExist($this->root . '/docs/extension-contract/classification.json');
        self::assertFileDoesNotExist($this->root . '/docs/extension-contract/generations.json');
        self::assertDirectoryDoesNotExist($this->root . '/tests/Fixtures/ExtensionApi');

        $readme = file_get_contents($this->root . '/docs/extension-contract/README.md');
        self::assertIsString($readme);
        self::assertStringContainsString('kumwe/extension-sdk', $readme);
        self::assertStringContainsString('sole authority', $readme);
        self::assertStringContainsString('no second App-owned public-API ledger', file_get_contents(
            $this->root . '/tools/verify-extension-contract.php',
        ) ?: '');
    }

    /**
     * Prove composer requires the SDK package and wires extension:contract to its package-owned verifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testComposerRunsThePackageOwnedContractGate(): void
    {
        $composer = $this->document($this->root . '/composer.json');
        self::assertArrayHasKey('kumwe/extension-sdk', $composer['require'] ?? []);
        self::assertSame(
            'php tools/verify-extension-contract.php',
            $composer['scripts']['extension:contract'] ?? null,
        );
    }

    /**
     * Read one JSON document and assert it decodes to an array.
     *
     * @param   string  $path  Absolute path of the JSON document to read.
     *
     * @return array<string, mixed>
     *
     * @since 2.0.0
     */
    private function document(string $path): array
    {
        $bytes = file_get_contents($path);
        self::assertIsString($bytes, $path);
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $path);

        return $decoded;
    }
}
