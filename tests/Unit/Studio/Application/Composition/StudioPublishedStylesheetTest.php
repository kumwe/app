<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Composition;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Studio\Application\Composition\StudioPublishedStylesheet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StudioPublishedStylesheet::class)]
/**
 * Proves the published stylesheet URL admits only usable page paths and bounded UTF-8 CSS bytes.
 *
 * @since  2.0.0
 */
final class StudioPublishedStylesheetTest extends TestCase
{
    /**
     * Prove the URL is digest-addressed under the delivery prefix and names the entry and page.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHrefIsDigestAddressedForTheExactRecordAndPage(): void
    {
        $css = 'body{color:#0c9189}';
        $href = StudioPublishedStylesheet::href(self::record(), '/products/probe', $css);

        self::assertStringStartsWith(StudioPublishedStylesheet::PATH_PREFIX, $href);
        self::assertStringContainsString(StudioPublishedStylesheet::digest($css), $href);
        self::assertStringContainsString('page=%2Fproducts%2Fprobe', $href);
    }

    /**
     * Prove a page path outside the canonical public grammar is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testForeignPagePathIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('page path');

        StudioPublishedStylesheet::href(self::record(), '/products/probe?x=1', 'body{}');
    }

    /**
     * Prove empty stylesheet bytes are refused rather than published as an addressable asset.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEmptyStylesheetBytesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stylesheet is invalid');

        StudioPublishedStylesheet::href(self::record(), '/products/probe', '');
    }

    /**
     * Prove the URL grammar is a closed static surface whose constructor holds no state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheUrlGrammarIsAClosedStaticSurface(): void
    {
        $constructor = new \ReflectionMethod(StudioPublishedStylesheet::class, '__construct');
        self::assertTrue($constructor->isPrivate());

        $instance = (new \ReflectionClass(StudioPublishedStylesheet::class))->newInstanceWithoutConstructor();
        $constructor->invoke($instance);
        self::assertSame([], get_object_vars($instance));
    }

    /**
     * Build one published record the stylesheet can belong to.
     *
     * @return  ContentRecord  Minimal published record fixture.
     *
     * @since   2.0.0
     */
    private static function record(): ContentRecord
    {
        $now = new DateTimeImmutable('2026-08-12T00:00:00+00:00');

        return new ContentRecord(
            ContentEntry::create(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb4aa',
                'Stylesheet probe',
                'stylesheet-probe',
                ['body' => 'Probe body'],
                ContentStatus::Published,
            ),
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb410',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb401',
            $now,
            $now,
        );
    }
}
