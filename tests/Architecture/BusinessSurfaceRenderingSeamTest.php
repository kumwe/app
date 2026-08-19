<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use Kumwe\App\BusinessSurface\Application\FieldModelContext;
use Kumwe\App\BusinessSurface\Application\FieldModelPresenter;
use Kumwe\App\BusinessSurface\Application\PresentedField;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationContext;
use Kumwe\App\BusinessSurface\Presentation\Field\RegistryFieldModelPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use SplFileInfo;

#[CoversClass(RegistryFieldModelPresenter::class)]
#[CoversClass(FieldModelContext::class)]
/**
 * Pins the business-surface rendering seam: application owns the contract, presentation adapts it.
 *
 * The generated-business facade decides what is presented and receives fully typed view models, but the
 * strategies that produce them are presentation code behind the frozen extension SPI. These checks hold
 * the seam in both directions: the port and its vocabulary live in the application layer and name no
 * presentation type, the adapter lives in the presentation layer and answers the port, no
 * business-surface application file names the presentation namespace at all any more, and the
 * application-owned context vocabulary cannot drift from the published `FieldPresentationContext`
 * without failing the build.
 *
 * @since  2.0.0
 */
final class BusinessSurfaceRenderingSeamTest extends TestCase
{
    /**
     * The rendering port is application-owned and its shipped adapter is presentation code answering it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRenderingPortIsOwnedByApplicationAndAdaptedByPresentation(): void
    {
        $port = new ReflectionClass(FieldModelPresenter::class);
        $adapter = new ReflectionClass(RegistryFieldModelPresenter::class);

        self::assertTrue($port->isInterface(), 'The rendering boundary must be a contract, not a class.');
        self::assertStringStartsWith('Kumwe\\App\\BusinessSurface\\Application\\', $port->getName());
        self::assertStringStartsWith('Kumwe\\App\\BusinessSurface\\Presentation\\', $adapter->getName());
        self::assertTrue($adapter->implementsInterface(FieldModelPresenter::class));
        self::assertStringStartsWith(
            dirname(__DIR__, 2) . '/src/BusinessSurface/Application/',
            (string) $port->getFileName(),
            'The port file must sit inside the application layer, not merely carry its namespace.',
        );
        self::assertStringStartsWith(
            dirname(__DIR__, 2) . '/src/BusinessSurface/Presentation/',
            (string) $adapter->getFileName(),
            'The adapter file must sit inside the presentation layer beside the registry it wraps.',
        );
    }

    /**
     * Nothing crossing the rendering port is a presentation type, in parameters or results.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePortSignaturesNameNoPresentationType(): void
    {
        foreach ([FieldModelPresenter::class, PresentedField::class] as $contract) {
            foreach ((new ReflectionClass($contract))->getMethods() as $method) {
                foreach ([...$method->getParameters()] as $parameter) {
                    $type = $parameter->getType();
                    if (!$type instanceof ReflectionNamedType) {
                        continue;
                    }
                    self::assertStringNotContainsString(
                        '\\Presentation\\',
                        $type->getName(),
                        sprintf('%s::%s() names a presentation type.', $contract, $method->getName()),
                    );
                }
                $return = $method->getReturnType();
                if ($return instanceof ReflectionNamedType) {
                    self::assertStringNotContainsString(
                        '\\Presentation\\',
                        $return->getName(),
                        sprintf('%s::%s() returns a presentation type.', $contract, $method->getName()),
                    );
                }
            }
        }
    }

    /**
     * No business-surface application file names the business-surface presentation namespace any more.
     *
     * The imports a file declares are the easy half. This reads the token stream instead, so a fully
     * qualified inline reference — the shape that carries no `use` line for a gate to find — fails here
     * too, while the same name in a documentation block does not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoBusinessSurfaceApplicationFileNamesThePresentationLayer(): void
    {
        $root = dirname(__DIR__, 2) . '/src/BusinessSurface/Application';
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
            $files++;
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, sprintf('Could not read %s.', $file->getPathname()));
            foreach (token_get_all($source) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                if (!in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $name = ltrim($token[1], '\\');
                if (str_starts_with($name, 'Kumwe\\App\\BusinessSurface\\Presentation\\')) {
                    $offenders[] = sprintf('%s:%d %s', $file->getFilename(), $token[2], $name);
                }
            }
        }

        self::assertGreaterThan(0, $files, 'The business-surface application layer could not be enumerated.');
        self::assertSame(
            [],
            $offenders,
            'Business-surface application code must reach presentation only through its rendering contract.',
        );
    }

    /**
     * The application context vocabulary and the published SPI contexts cannot drift apart.
     *
     * The frozen extension contract pins `FieldPresentationContext` where it is, so the application
     * layer speaks its own enumeration and the adapter translates by backing value. That translation is
     * only total while the two enumerations agree case for case, value for value, and on which contexts
     * accept submitted input — which is exactly what this pins.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheContextVocabulariesStayInLockstep(): void
    {
        $application = new ReflectionEnum(FieldModelContext::class);
        $presentation = new ReflectionEnum(FieldPresentationContext::class);

        $applicationCases = [];
        foreach (FieldModelContext::cases() as $case) {
            $applicationCases[$case->name] = $case->value;
        }
        $presentationCases = [];
        foreach (FieldPresentationContext::cases() as $case) {
            $presentationCases[$case->name] = $case->value;
        }

        self::assertSame($presentationCases, $applicationCases, 'The two context enumerations drifted apart.');
        self::assertSame((string) $presentation->getBackingType(), (string) $application->getBackingType());
        foreach (FieldModelContext::cases() as $case) {
            self::assertSame(
                FieldPresentationContext::from($case->value)->edits(),
                $case->edits(),
                sprintf('Context %s disagrees about accepting submitted input.', $case->name),
            );
        }
    }
}
