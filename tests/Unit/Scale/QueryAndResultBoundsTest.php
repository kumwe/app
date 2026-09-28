<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Scale;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Infrastructure\Persistence\DoctrineContentRepository;
use Kumwe\App\Application\Automation\JobExecutionScope;
use Kumwe\App\Infrastructure\Automation\DoctrineJobQueue;
use Kumwe\Audit\Application\AuditRecorder;
use Kumwe\Content\Application\ContentBrowseQuery;
use Kumwe\Content\Application\ContentRecord;
use Kumwe\Content\Application\ContentRepository;
use Kumwe\Content\Application\ContentSearchRepository;
use Kumwe\Content\Domain\ContentEntry;
use Kumwe\Content\Domain\ContentStatus;
use Kumwe\Content\Domain\PublicationWindow;
use Kumwe\Content\Workflow\Domain\Workflow;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use Psr\Clock\ClockInterface;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\App\BusinessRecord\Application\RecordRequestGuard;
use Kumwe\App\BusinessReporting\Infrastructure\FilesystemExportArtifactStorage;
use Kumwe\App\Identity\Infrastructure\Administration\DoctrineAccessControlRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the App-owned query, page and result bounds P5-G relies on, each refused before any statement runs.
 *
 * The record-query and reporting packages bound filters, sorts, page size, relation hops, operation count
 * and report row caps in their own suites; these cases cover the bounds App itself owns.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineAccessControlRepository::class)]
#[CoversClass(RecordHistoryQuery::class)]
#[CoversClass(RecordRequestGuard::class)]
#[CoversClass(FilesystemExportArtifactStorage::class)]
#[CoversClass(ContentService::class)]
#[CoversClass(DoctrineContentRepository::class)]
#[CoversClass(DoctrineJobQueue::class)]
final class QueryAndResultBoundsTest extends TestCase
{
    /**
     * Administrator lists refuse a page deeper than the declared maximum offset without querying.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeepOffsetsAreRefusedBeforeAnyStatement(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('fetchAllAssociative');
        $repository = new DoctrineAccessControlRepository($database, new TableNames($database, 'kumwe_'));
        foreach (
            [
                static fn () => $repository->users(100, DoctrineAccessControlRepository::MAXIMUM_OFFSET + 1),
                static fn () => $repository->roles(501),
                static fn () => $repository->capabilities(100, -1),
                static fn () => $repository->securityEvents(100, DoctrineAccessControlRepository::MAXIMUM_OFFSET + 1),
            ] as $call
        ) {
            try {
                $call();
                self::fail('An unbounded page was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * History windows, value sets and export byte ceilings refuse their unbounded forms.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHistoryValueAndExportBoundsAreRefused(): void
    {
        $context = AuthorizationContext::system(SystemIdentity::Worker)->context(SiteContext::default(), 'bounds');
        $refused = 0;
        foreach (
            [
                static fn () => new RecordHistoryQuery(
                    $context,
                    'acme.thing',
                    '01890000-0000-7000-8000-000000000001',
                    null,
                    201,
                ),
                static fn () => RecordRequestGuard::values(array_fill_keys(
                    array_map(static fn (int $index): string => 'field_' . $index, range(0, 256)),
                    'x',
                )),
                static fn () => new FilesystemExportArtifactStorage(sys_get_temp_dir(), 536_870_913),
            ] as $call
        ) {
            try {
                $call();
            } catch (InvalidArgumentException) {
                $refused++;
            }
        }
        self::assertSame(3, $refused);
    }

    /**
     * Content storage windows refuse an offset deeper than the declared maximum before any statement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContentWindowsRefuseDeepOffsetsBeforeAnyStatement(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('createQueryBuilder');
        $repository = new DoctrineContentRepository($database, new TableNames($database, 'kumwe_'));
        $refused = 0;
        foreach (
            [
                static fn () => $repository->allForSite(
                    SiteContext::default(),
                    100,
                    false,
                    DoctrineContentRepository::MAXIMUM_OFFSET + 1,
                ),
                static fn () => $repository->searchForSite(
                    SiteContext::default(),
                    new ContentBrowseQuery(),
                    100,
                    DoctrineContentRepository::MAXIMUM_OFFSET + 1,
                ),
            ] as $call
        ) {
            try {
                $call();
            } catch (InvalidArgumentException) {
                $refused++;
            }
        }
        self::assertSame(2, $refused);
    }

    /**
     * An operator who may manage no listed job stops reading the job history after the declared scan bound.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheJobListingStopsAtTheScanBound(): void
    {
        $scanned = 0;
        $deepest = 0;
        $database = $this->createStub(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(static fn (string $name): string => $name);
        $database->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql) use (&$scanned, &$deepest): array {
                self::assertSame(1, preg_match('/LIMIT (\d+) OFFSET (\d+)$/D', $sql, $window));
                $deepest = max($deepest, (int) $window[2]);
                $scanned += (int) $window[1];

                return array_fill(0, (int) $window[1], [
                    'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb477',
                    'queue' => 'default',
                    'job_type' => 'bounds.test',
                    'payload' => '{}',
                    'attempts' => 0,
                    'maximum_attempts' => 5,
                    'execution_scope' => 'site',
                ]);
            },
        );
        $queue = new DoctrineJobQueue(
            $database,
            new TableNames($database, 'kumwe_'),
            new ImmediateTransactionManager(),
            $this->createStub(ClockInterface::class),
            '2.0.0',
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
            new JobExecutionScope(),
        );

        self::assertSame([], $queue->all(AuthorizationContext::human([]), 500));
        self::assertLessThanOrEqual(DoctrineJobQueue::MAXIMUM_LISTED_SCAN, $scanned);
        self::assertGreaterThan(9_000, $scanned, 'The walk uses its budget rather than stopping early.');
        self::assertLessThanOrEqual(10_000, $deepest);
    }

    /**
     * An actor who may read nothing stops listing and browsing after the declared scan bound, however many
     * entries the store holds, and never asks the store for a window deeper than it accepts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAuthorizationFilteredContentWalksStopAtTheScanBound(): void
    {
        $now = new DateTimeImmutable('2026-09-24T00:00:00+00:00');
        $record = new ContentRecord(
            ContentEntry::reconstitute(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb499',
                'Unreadable page',
                'unreadable-page',
                ['body' => 'Hidden.'],
                ContentStatus::Draft,
                PublicationWindow::unbounded(),
                1,
            ),
            ContentService::CORE_PAGE_TYPE_ID,
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb401',
            $now,
            $now,
        );
        $scanned = 0;
        $deepest = 0;
        $window = static function (int $limit, int $offset) use ($record, &$scanned, &$deepest): array {
            $deepest = max($deepest, $offset);
            $scanned += $limit;

            return array_fill(0, $limit, $record);
        };
        $repository = $this->createStubForIntersectionOfInterfaces([
            ContentRepository::class,
            ContentSearchRepository::class,
        ]);
        $repository->method('all')->willReturnCallback(
            static fn (int $limit, bool $includeDeleted, int $offset): array => $window($limit, $offset),
        );
        $repository->method('searchForSite')->willReturnCallback(
            static fn (SiteContext $site, ContentBrowseQuery $query, int $limit, int $offset): array
                => $window($limit, $offset),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now);
        $service = new ContentService(
            $repository,
            $this->createStub(AuditRecorder::class),
            new ImmediateTransactionManager(),
            $clock,
            new Workflow(),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
        );
        $context = AuthorizationContext::human([]);

        self::assertSame([], $service->list($context, 500));
        self::assertLessThanOrEqual(10_100, $scanned);
        self::assertLessThanOrEqual(DoctrineContentRepository::MAXIMUM_OFFSET, $deepest);
        $scanned = 0;
        $deepest = 0;
        $page = $service->browse($context, new ContentBrowseQuery(page: 400, perPage: 25));
        self::assertSame([], $page->items);
        self::assertFalse($page->hasNext);
        self::assertLessThanOrEqual(10_100, $scanned);
        self::assertLessThanOrEqual(DoctrineContentRepository::MAXIMUM_OFFSET, $deepest);
    }
}
