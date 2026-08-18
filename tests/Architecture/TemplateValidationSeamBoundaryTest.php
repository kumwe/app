<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use Kumwe\CMS\Application\Presentation\ThemePackageValidator;
use Kumwe\CMS\Extension\Domain\ThemeSurface;
use Kumwe\CMS\Presentation\Infrastructure\TwigThemePackageValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use SplFileInfo;

#[CoversClass(TwigThemePackageValidator::class)]
#[CoversClass(ThemeSurface::class)]
/**
 * Pins the template-validation seam: Application owns the contract, the Twig compiler adapts it.
 *
 * Refusing a broken theme before its activation is written is part of the activation use case, so the
 * contract for it is application-owned; parsing and compiling Twig is template-engine work and sits
 * behind that contract. These checks hold the seam by type and by token stream, which is what makes
 * them survive a rename: the port must stay an interface inside the application layer speaking domain
 * types only, the one class allowed to name the engine on this seam is the adapter, and no application
 * layer anywhere in the tree may import Twig inward again — the exact leak `P3-C` closed.
 *
 * @since  2.0.0
 */
final class TemplateValidationSeamBoundaryTest extends TestCase
{
    /**
     * The template-validation port is application-owned and the Twig adapter answers it from outside.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePortIsOwnedByApplicationAndAdaptedOutsideTheApplicationLayer(): void
    {
        $port = new ReflectionClass(ThemePackageValidator::class);
        $adapter = new ReflectionClass(TwigThemePackageValidator::class);

        self::assertTrue($port->isInterface(), 'The template-validation boundary must be a contract.');
        self::assertStringStartsWith('Kumwe\\CMS\\Application\\', $port->getName());
        self::assertTrue($adapter->implementsInterface(ThemePackageValidator::class));
        self::assertStringStartsWith(
            dirname(__DIR__, 2) . '/src/Application/Presentation/',
            (string) $port->getFileName(),
            'The port file must sit inside the application layer, not merely carry its namespace.',
        );
        self::assertStringStartsWith(
            dirname(__DIR__, 2) . '/src/Presentation/Infrastructure/',
            (string) $adapter->getFileName(),
            'The Twig adapter must live with the presentation module\'s infrastructure adapters.',
        );
    }

    /**
     * The port speaks domain types only: no engine type and no presentation type in any signature.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePortSignaturesNameNoEngineOrPresentationType(): void
    {
        foreach ((new ReflectionClass(ThemePackageValidator::class))->getMethods() as $method) {
            $declared = [];
            foreach ($method->getParameters() as $parameter) {
                $declared[] = $parameter->getType();
            }
            $declared[] = $method->getReturnType();
            foreach ($declared as $type) {
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }
                self::assertStringStartsNotWith('Twig\\', $type->getName());
                self::assertStringStartsNotWith('Kumwe\\CMS\\Presentation\\', $type->getName());
            }
        }
    }

    /**
     * The surface a theme is activated on is a domain value, importable from every layer that needs it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheThemeSurfaceVocabularyIsADomainValue(): void
    {
        $surface = new ReflectionEnum(ThemeSurface::class);

        self::assertStringStartsWith('Kumwe\\CMS\\Extension\\Domain\\', $surface->getName());
        self::assertStringStartsWith(
            dirname(__DIR__, 2) . '/src/Extension/Domain/',
            (string) $surface->getFileName(),
            'The surface enum must sit inside the extension domain, not merely carry its namespace.',
        );
    }

    /**
     * Operator input still resolves to a surface the same way from the enum's domain home.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOperatorInputStillResolvesToASurface(): void
    {
        self::assertNull(ThemeSurface::optional(null));
        self::assertNull(ThemeSurface::optional('   '));
        self::assertSame(ThemeSurface::Site, ThemeSurface::optional(' Site '));
        self::assertSame(ThemeSurface::Administrator, ThemeSurface::optional('ADMINISTRATOR'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A theme surface must be site or administrator.');
        ThemeSurface::optional('kiosk');
    }

    /**
     * No application-layer file anywhere in the tree names a Twig type in its executable code.
     *
     * Both the shared `src/Application` root and each module's own `src/<Module>/Application` count,
     * because the layer is defined by the directory a file lives in. The token stream is read instead of
     * the import list, so a fully qualified `\Twig\Environment` written inline fails here too, while the
     * word Twig in a documentation block does not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoApplicationLayerFileNamesTheTemplateEngine(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $offenders = [];
        $files = 0;
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            ) as $file
        ) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (!str_contains('/' . $relative, '/Application/')) {
                continue;
            }
            $files++;
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, sprintf('Could not read %s.', $relative));
            foreach (token_get_all($source) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                if (!in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                if (str_starts_with(ltrim($token[1], '\\'), 'Twig\\')) {
                    $offenders[] = sprintf('%s:%d %s', $relative, $token[2], $token[1]);
                }
            }
        }

        self::assertGreaterThan(0, $files, 'The application layers could not be enumerated.');
        self::assertSame(
            [],
            $offenders,
            'Application code must depend on the template-validation port, never on the engine.',
        );
    }
}
