<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRevisionCursor;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordRevision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessRecordRevisionCursor::class)]
/**
 * Proves the history cursor states the whole ordering key, or says plainly that it does not.
 *
 * @since  2.0.0
 */
final class BusinessRecordRevisionCursorTest extends TestCase
{
    /**
     * Proves a cursor taken from a revision carries every component the ordering key sorts on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACursorTakenFromARevisionResolvesTheWholeOrderingKey(): void
    {
        $revision = self::revision('018f22e2-7c8b-7ab0-8f3a-88e8026bb901', 7, 7);
        $cursor = BusinessRecordRevisionCursor::after($revision);

        self::assertTrue($cursor->isTotal());
        self::assertSame(7, $cursor->recordVersion);
        self::assertSame(7, $cursor->revisionNumber);
        self::assertSame('018f22e2-7c8b-7ab0-8f3a-88e8026bb901', $cursor->recordKey);
    }

    /**
     * Proves a version-only cursor reports itself partial rather than pretending to resolve ties.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAVersionOnlyCursorReportsThatItCannotResolveATie(): void
    {
        $cursor = BusinessRecordRevisionCursor::atVersion(4);

        self::assertFalse($cursor->isTotal());
        self::assertSame(4, $cursor->recordVersion);
        self::assertNull($cursor->revisionNumber);
        self::assertNull($cursor->recordKey);
    }

    /**
     * Proves a record version below one is refused where it is built rather than at the statement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANonPositiveRecordVersionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BusinessRecordRevisionCursor::atVersion(0);
    }

    /**
     * Build one revision the cursor can be taken from.
     *
     * @param   string  $recordKey       Internal storage key the revision belongs to.
     * @param   int     $recordVersion   Record version the revision describes.
     * @param   int     $revisionNumber  Position of the revision in the record's log.
     *
     * @return  BusinessRecordRevision  A valid entry carrying those ordering components.
     *
     * @since   2.0.0
     */
    private static function revision(string $recordKey, int $recordVersion, int $revisionNumber): BusinessRecordRevision
    {
        return new BusinessRecordRevision(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb900',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb8ff',
            1,
            'default',
            null,
            $recordKey,
            hash('sha256', 'revision-cursor-identity'),
            $recordVersion,
            $revisionNumber,
            'update',
            ['label' => 'Cursor fixture'],
            ['label'],
            'tester',
            new DateTimeImmutable('2026-08-15T00:00:00', new DateTimeZone('UTC')),
        );
    }
}
