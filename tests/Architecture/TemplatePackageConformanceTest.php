<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Domain\ExtensionType;
use Kumwe\CMS\Presentation\Application\ThemePackageValidator;
use Kumwe\CMS\Presentation\ThemeSurface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the shipped template packages to their installable KIS 1.0 reference contract.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class TemplatePackageConformanceTest extends TestCase
{
    /**
     * Repository root containing core templates and example packages.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Resolve the repository root for static package inspection.
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
     * Proves the complete site example parses, publishes real assets, and passes activation validation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSiteReferencePackageIsInstallableAndActivationReady(): void
    {
        $this->assertPackageConforms('minimal-template', ThemeSurface::Site);

        self::assertFileExists(
            $this->root . '/examples/extensions/minimal-template/templates/site/home.twig',
        );
        self::assertFileExists(
            $this->root . '/examples/extensions/minimal-template/templates/site/page.twig',
        );
    }

    /**
     * Proves the administrator example parses, publishes real assets, and passes KIS shell validation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorReferencePackageIsInstallableAndActivationReady(): void
    {
        $this->assertPackageConforms('minimal-administrator-template', ThemeSurface::Administrator);

        $templates = glob(
            $this->root . '/examples/extensions/minimal-administrator-template/templates/administrator/*.twig',
        );
        self::assertSame([
            $this->root
            . '/examples/extensions/minimal-administrator-template/templates/administrator/layout.twig',
        ], $templates);
    }

    /**
     * Proves the authoring guide names the current standard, override boundary, and deterministic gate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTemplateAuthoringGuideCarriesTheKisConformanceContract(): void
    {
        $documentation = $this->contents('docs/templates.md');

        foreach (
            [
                'kis-1.0',
                '@kis',
                'Complete site override contract',
                'KIS 1.0 administrator shell contract',
                'extension:conformance',
                'cmp /tmp/kumwe-template-proof/template-a.zip',
                'theme:administrator:recover',
            ] as $contract
        ) {
            self::assertStringContainsString($contract, $documentation);
        }
    }

    /**
     * Assert one reference directory carries a strict manifest, provider, assets, and valid theme tree.
     *
     * @param   string        $directory  Example directory below `examples/extensions`.
     * @param   ThemeSurface  $surface    Theme surface whose activation contract must pass.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertPackageConforms(string $directory, ThemeSurface $surface): void
    {
        $package = $this->root . '/examples/extensions/' . $directory;
        $manifest = ExtensionManifest::fromJson($this->contents(
            'examples/extensions/' . $directory . '/kumwe.json',
        ));

        self::assertSame(ExtensionType::Template, $manifest->type());
        $compatibility = $manifest->templateCompatibility();
        self::assertNotNull($compatibility);
        self::assertSame(1, $compatibility->contract());
        self::assertSame('kis-1.0', $compatibility->standard());
        self::assertFileExists($package . '/src/Provider.php');
        self::assertNotEmpty($manifest->assets());
        foreach ($manifest->assets() as $asset) {
            self::assertFileExists($package . '/' . $asset);
        }
        self::assertStringContainsString('kis-1.0', $this->contents(
            'examples/extensions/' . $directory . '/README.md',
        ));

        (new ThemePackageValidator($this->root . '/templates'))->validate(
            $package . '/templates/' . $surface->value,
            $surface,
            $compatibility,
        );
        self::addToAssertionCount(1);
    }

    /**
     * Read a repository file or fail with its relative path.
     *
     * @param   string  $path  Repository-relative file path.
     *
     * @return  string  Complete file contents.
     *
     * @since   2.0.0
     */
    private function contents(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, sprintf('Unable to read %s.', $path));

        return $contents;
    }
}
