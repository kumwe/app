<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Application;

use InvalidArgumentException;
use Kumwe\CMS\Presentation\Application\ThemePackageValidator;
use Kumwe\CMS\Presentation\ThemeSurface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThemePackageValidator::class)]
final class ThemePackageValidatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kumwe-validator-' . bin2hex(random_bytes(8));
        foreach (['/core/site', '/core/administrator', '/theme'] as $directory) {
            self::assertTrue(mkdir($this->root . $directory, 0700, true));
        }
        file_put_contents($this->root . '/core/administrator/layout.twig', 'core');
        file_put_contents($this->root . '/core/site/home.twig', 'home');
        file_put_contents($this->root . '/core/site/page.twig', 'page');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testValidSiteContractCompilesBeforeActivation(): void
    {
        file_put_contents($this->root . '/theme/home.twig', '{% include "page.twig" %}');
        file_put_contents($this->root . '/theme/page.twig', '{{ title|default("ok") }}');

        (new ThemePackageValidator($this->root . '/core'))->validate(
            $this->root . '/theme',
            ThemeSurface::Site,
        );
        self::addToAssertionCount(1);
    }

    public function testMissingRequiredEntryIsRejected(): void
    {
        file_put_contents($this->root . '/theme/home.twig', 'home');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('page.twig');

        (new ThemePackageValidator($this->root . '/core'))->validate(
            $this->root . '/theme',
            ThemeSurface::Site,
        );
    }

    public function testInvalidTwigIsRejected(): void
    {
        file_put_contents($this->root . '/theme/layout.twig', '{% invalid %}');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be compiled');

        (new ThemePackageValidator($this->root . '/core'))->validate(
            $this->root . '/theme',
            ThemeSurface::Administrator,
        );
    }

    public function testAdministratorShellMustPreserveProtectedOutlets(): void
    {
        file_put_contents($this->root . '/theme/layout.twig', '<div>decorative shell</div>');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('title/content blocks');

        (new ThemePackageValidator($this->root . '/core'))->validate(
            $this->root . '/theme',
            ThemeSurface::Administrator,
        );
    }

    public function testAdministratorShellRendersSentinelChildAndMainLandmark(): void
    {
        file_put_contents(
            $this->root . '/theme/layout.twig',
            '<!doctype html><html><head><title>{% block title %}{% endblock %}</title></head>'
            . '<body><main>{% block content %}{% endblock %}</main></body></html>',
        );

        (new ThemePackageValidator($this->root . '/core'))->validate(
            $this->root . '/theme',
            ThemeSurface::Administrator,
        );
        self::addToAssertionCount(1);
    }
}
