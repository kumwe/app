<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Content\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentRevision;
use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Content\Domain\PublicationWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentRevision::class)]
#[UsesClass(ContentEntry::class)]
#[UsesClass(ContentStatus::class)]
#[UsesClass(PublicationWindow::class)]
final class ContentRevisionTest extends TestCase
{
    private const ENTRY_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb151';
    private const REVISION_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026bb152';

    public function testCapturesImmutableSnapshotAndValidChecksum(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-04T10:00:00Z');
        $entry = ContentEntry::create(self::ENTRY_ID, 'Welcome', 'welcome', ['body' => 'Hello']);
        $revision = ContentRevision::capture(self::REVISION_ID, $entry, 1, $createdAt);

        self::assertSame(self::REVISION_ID, $revision->id());
        self::assertSame(self::ENTRY_ID, $revision->contentEntryId());
        self::assertSame(1, $revision->revisionNumber());
        self::assertSame($entry->snapshot(), $revision->snapshot());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $revision->checksum());
        self::assertTrue($revision->hasValidChecksum());
        self::assertSame($createdAt, $revision->createdAt());
    }

    public function testChecksumIsStableAcrossAssociativeKeyOrder(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-04T10:00:00Z');
        $first = ContentRevision::capture(
            self::REVISION_ID,
            ContentEntry::create(self::ENTRY_ID, 'Welcome', 'welcome', ['b' => 2, 'a' => 1]),
            1,
            $createdAt,
        );
        $second = ContentRevision::capture(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb153',
            ContentEntry::create(self::ENTRY_ID, 'Welcome', 'welcome', ['a' => 1, 'b' => 2]),
            1,
            $createdAt,
        );

        self::assertSame($first->checksum(), $second->checksum());
    }

    public function testRejectsInvalidRevisionNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentRevision::capture(
            self::REVISION_ID,
            ContentEntry::create(self::ENTRY_ID, 'Welcome', 'welcome'),
            0,
            new DateTimeImmutable(),
        );
    }
}
