<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Media\Application;

use DateTimeImmutable;
use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\Media\Application\MediaStorage;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Audit\Application\AuditRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Pins that a media library page number can never overflow the slice offset.
 *
 * The administrator media screen reads its page number straight from the query string, and PHP turns a
 * digit string past the integer range into `PHP_INT_MAX`. Multiplying that by the page size used to overflow
 * into a float and fail the request with a type error; such a page now lies past the end of any library and
 * is answered as an empty page.
 *
 * @since  2.0.0
 */
#[CoversClass(MediaService::class)]
final class MediaServiceBrowseBoundsTest extends TestCase
{
    /**
     * Ordinary pages slice the library and pages at the integer limit are empty rather than failing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPageNumberAtTheIntegerLimitIsAnEmptyPageRatherThanAnOverflow(): void
    {
        $assets = [];
        foreach (['a', 'b', 'c'] as $index => $name) {
            $assets[] = new MediaAsset(
                sprintf('018f22e2-7c8b-7ab0-8f3a-88e8026bb70%d', $index),
                $name . '.png',
                'image/png',
                10,
                new DateTimeImmutable('2026-09-24T00:00:00+00:00'),
                '/nonexistent/' . $name . '.png',
            );
        }
        $storage = $this->createStub(MediaStorage::class);
        $storage->method('all')->willReturn($assets);
        $media = new MediaService(
            $storage,
            AuthorizationContext::gateway(),
            $this->createStub(AuditRecorder::class),
            $this->createStub(ClockInterface::class),
            1_000_000,
        );
        $context = AuthorizationContext::human(['content.read']);

        self::assertSame(['b.png'], array_column($media->browse($context, '', 'all', 2, 1)->items, 'name'));
        foreach ([PHP_INT_MAX, intdiv(PHP_INT_MAX, 24) + 1, intdiv(PHP_INT_MAX, 24)] as $page) {
            $result = $media->browse($context, '', 'all', $page, 24);
            self::assertSame([], $result->items, (string) $page);
            self::assertSame(3, $result->total);
            self::assertSame($page, $result->page);
        }
    }
}
