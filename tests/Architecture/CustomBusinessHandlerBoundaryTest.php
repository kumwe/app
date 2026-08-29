<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionCommand;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionHandler;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionResult;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewHandler;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewQuery;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewResult;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;

/**
 * Prevents typed custom business handlers from becoming a transport, persistence, or service-locator escape.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class CustomBusinessHandlerBoundaryTest extends TestCase
{
    /**
     * Proves every custom application contract remains independent of delivery and persistence frameworks.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCustomBusinessContractsContainNoTransportPersistenceOrContainerDependencies(): void
    {
        foreach ($this->customSources() as $path => $source) {
            foreach (
                [
                'Psr\\Http',
                'ServerRequestInterface',
                'Doctrine\\DBAL',
                'Connection',
                'Repository',
                'ContainerInterface',
                'Laminas\\ServiceManager',
                'Kumwe\\App\\Kernel\\Container',
                'callable',
                'Closure',
                ] as $forbidden
            ) {
                self::assertStringNotContainsString($forbidden, $source, $path);
            }
        }
    }

    /**
     * Proves handlers expose only validated query or command objects and bounded result DTOs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHandlerInterfacesExposeOnlyTheTypedApplicationBoundary(): void
    {
        foreach (
            [
                CustomBusinessViewHandler::class => [CustomBusinessViewQuery::class, CustomBusinessViewResult::class],
                CustomBusinessActionHandler::class => [
                    CustomBusinessActionCommand::class,
                    CustomBusinessActionResult::class,
                ],
            ] as $handler => [$request, $result]
        ) {
            $method = new ReflectionMethod($handler, 'handle');
            self::assertTrue($method->isPublic());
            $parameters = $method->getParameters();
            self::assertCount(1, $parameters);
            self::assertSame($request, $this->namedType($parameters[0]->getType()));
            self::assertSame($result, $this->namedType($method->getReturnType()));

            $context = (new ReflectionClass($request))->getProperty('context');
            self::assertTrue($context->isPublic());
            self::assertSame(ExecutionContext::class, $this->namedType($context->getType()));
        }
    }

    /**
     * Proves the shared generated-business facade has real custom view and action dispatch paths.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedBusinessFacadeDispatchesPublishedCustomContracts(): void
    {
        $path = dirname(__DIR__, 2) . '/src/BusinessSurface/Application/BusinessSurfaceService.php';
        $source = file_get_contents($path);
        self::assertIsString($source, 'Could not read ' . $path . '.');

        self::assertStringContainsString('public function customView(', $source);
        self::assertStringContainsString('$this->customBusiness->view(', $source);
        self::assertStringContainsString('$this->customActions->execute(', $source);
    }

    /**
     * Proves both authenticated browser adapters use fixed custom-view routes and core-owned projections.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedBrowserCustomViewsUseFixedRoutesBeforeGenericRecords(): void
    {
        $root = dirname(__DIR__, 2);
        $container = (string) file_get_contents($root . '/src/Kernel/ContainerFactory.php');
        foreach (['/portal/business', '/administrator/business'] as $base) {
            $custom = "'" . $base . "/{definition}/{record}/views/{view}',";
            $generic = "'" . $base . "/{definition}/{record}',";
            self::assertStringContainsString($custom, $container);
            self::assertLessThan(strpos($container, $generic), strpos($container, $custom));
        }
        foreach (['administrator', 'portal'] as $surface) {
            $template = (string) file_get_contents(
                $root . '/templates/' . $surface . '/business-custom-view.twig',
            );
            self::assertStringContainsString('data_projection', $template);
            self::assertStringNotContainsString('json_encode', $template);
            self::assertStringNotContainsString('<textarea', $template);
        }
        $controller = (string) file_get_contents(
            $root . '/src/BusinessSurface/Delivery/Browser/GeneratedBusinessBrowserController.php',
        );
        self::assertStringContainsString('public function customView(', $controller);
        self::assertStringContainsString('$this->business->customView(', $controller);
        self::assertStringContainsString('$this->customViews->present(', $controller);
    }

    /**
     * Proves fixed selector routes call the shared facade and graphical forms expose no raw reference editor.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedBrowserSelectorsAndStructuredEditorsAreCoreOwned(): void
    {
        $root = dirname(__DIR__, 2);
        $container = (string) file_get_contents($root . '/src/Kernel/ContainerFactory.php');
        foreach (['/portal/business', '/administrator/business'] as $base) {
            $selector = "'" . $base . "/{definition}/{record}/choices/relations/{related}',";
            $generic = "'" . $base . "/{definition}/{record}',";
            self::assertStringContainsString($selector, $container);
            self::assertLessThan(strpos($container, $generic), strpos($container, $selector));
        }
        $controller = (string) file_get_contents(
            $root . '/src/BusinessSurface/Delivery/Browser/GeneratedBusinessBrowserController.php',
        );
        self::assertStringContainsString('$this->business->relationChoices(', $controller);
        self::assertStringContainsString('$this->business->mediaChoices(', $controller);
        self::assertStringContainsString('$this->business->ownedLineForm(', $controller);
        self::assertStringContainsString('$this->business->ownedLineFieldChoices(', $controller);
        self::assertStringContainsString('choices/owned-lines/', $container);
        foreach (['administrator', 'portal'] as $surface) {
            $fields = (string) file_get_contents($root . '/templates/' . $surface . '/_business-fields.twig');
            self::assertStringNotContainsString('json_encode', $fields);
            self::assertStringNotContainsString('business-structured-source', $fields);
            self::assertStringContainsString('prepare_structure', $fields);
            self::assertStringContainsString('<select', $fields);
            $detail = (string) file_get_contents($root . '/templates/' . $surface . '/business-detail.twig');
            self::assertStringNotContainsString('Target record ID', $detail);
            self::assertStringContainsString('owned_line_form', $detail);
        }
    }

    /**
     * Read every custom application source in deterministic filename order.
     *
     * @return  array<string, string>  Source text keyed by repository-relative path.
     *
     * @since   2.0.0
     */
    private function customSources(): array
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/src/BusinessSurface/Application/Custom';
        $sources = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = substr($file->getPathname(), strlen($root) + 1);
            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents, 'Could not read ' . $path . '.');
            $sources[$path] = $contents;
        }
        ksort($sources, SORT_STRING);
        return $sources;
    }

    /**
     * Resolve one non-built-in reflected type without comparing its source spelling.
     *
     * @param   ?\ReflectionType  $type  Runtime type declaration to inspect.
     *
     * @return  class-string  Declared application contract.
     *
     * @since   2.0.0
     */
    private function namedType(?\ReflectionType $type): string
    {
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertFalse($type->isBuiltin());

        /** @var class-string $name */
        $name = $type->getName();
        return $name;
    }
}
