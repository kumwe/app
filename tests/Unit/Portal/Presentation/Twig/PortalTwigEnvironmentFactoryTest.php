<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Portal\Presentation\Twig;

use Kumwe\CMS\Portal\Presentation\Twig\PortalTwigEnvironmentFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PortalTwigEnvironmentFactory::class)]
final class PortalTwigEnvironmentFactoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kumwe-portal-twig-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . '/core/portal', 0700, true));
        self::assertTrue(mkdir($this->root . '/core/interface-standard', 0700, true));
        self::assertTrue(mkdir($this->root . '/extension', 0700, true));
        self::assertNotFalse(file_put_contents($this->root . '/core/portal/page.twig', 'core portal'));
        self::assertNotFalse(file_put_contents(
            $this->root . '/core/interface-standard/example.twig',
            'KIS component',
        ));
        self::assertNotFalse(file_put_contents($this->root . '/extension/page.twig', 'extension portal'));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function testPortalExposesCoreKisAndOwnerIsolatedExtensionTemplates(): void
    {
        $twig = (new PortalTwigEnvironmentFactory(false))->create(
            $this->root . '/core',
            ['acme/tools' => $this->root . '/extension'],
            $this->root . '/cache',
        );

        self::assertSame('core portal', $twig->render('portal/page.twig'));
        self::assertSame('KIS component', $twig->render('@kis/example.twig'));
        self::assertSame(
            'extension portal',
            $twig->render('@extension-61636d652f746f6f6c73/page.twig'),
        );
    }
}
