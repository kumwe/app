<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Scale;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
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
}
