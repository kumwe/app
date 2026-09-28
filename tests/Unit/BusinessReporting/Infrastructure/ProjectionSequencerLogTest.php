<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting\Infrastructure;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Kumwe\App\BusinessReporting\Infrastructure\DoctrineProjectionEventSequencer;
use Kumwe\App\Infrastructure\Observability\NullMetricRecorder;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the sequencer's structured failure line: an operator sees the failed pass, and the caller still owns it.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineProjectionEventSequencer::class)]
final class ProjectionSequencerLogTest extends TestCase
{
    /**
     * A pass that cannot read its journal head is logged as a sequencing failure and rethrown unchanged.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFailedPassIsLoggedAndRethrownUnchanged(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $records = new TestHandler();
        $sequencer = new DoctrineProjectionEventSequencer(
            $database,
            new TableNames($database, 'sequencer_'),
            new DoctrineTransactionManager($database),
            new NullMetricRecorder(),
            new Logger('kumwe', [$records]),
        );

        try {
            $sequencer->sequence();
            self::fail('A missing journal head must not be sequenced past.');
        } catch (TableNotFoundException $failure) {
            $logged = $records->getRecords();
            self::assertCount(1, $logged);
            self::assertSame('Projection source sequencing failed.', $logged[0]->message);
            self::assertSame('projection.sequence', $logged[0]->context['operation']);
            self::assertSame($failure, $logged[0]->context['exception']);
            self::assertSame(300, $logged[0]->level->value);
        }
    }
}
