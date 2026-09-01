<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Preview;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Preview\StudioPreviewStylesheet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StudioPreviewStylesheet::class)]
/**
 * Proves preview stylesheet activation holds the link inventory and URL grammar closed.
 *
 * @since  2.0.0
 */
final class StudioPreviewStylesheetTest extends TestCase
{
    /**
     * Prove the one sentinel link is replaced by the authenticated URL exactly once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSentinelLinkIsActivatedExactlyOnce(): void
    {
        $href = '/administrator/studio/preview/styles.css?grant=abc123';
        $html = '<link rel="stylesheet" href="' . StudioPreviewStylesheet::HREF_PLACEHOLDER . '"><p>ok</p>';

        $activated = StudioPreviewStylesheet::activate($html, $href, true);

        self::assertStringContainsString('href="' . $href . '"', $activated);
        self::assertStringNotContainsString(StudioPreviewStylesheet::HREF_PLACEHOLDER, $activated);
        self::assertSame('<p>ok</p>', StudioPreviewStylesheet::activate('<p>ok</p>', $href, false));
    }

    /**
     * Prove a stylesheet URL outside the authenticated preview route grammar is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testForeignStylesheetUrlIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('URL is invalid');

        StudioPreviewStylesheet::activate('<p>ok</p>', 'https://evil.example/styles.css', false);
    }

    /**
     * Prove a rendered document whose link inventory contradicts the grant is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContradictoryLinkInventoryIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('inventory is invalid');

        StudioPreviewStylesheet::activate(
            '<p>no sentinel</p>',
            '/administrator/studio/preview/styles.css?grant=abc123',
            true,
        );
    }
}
