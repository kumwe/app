<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use Kumwe\App\BusinessRecord\Application\DocumentCommitTimingRecorder;
use Kumwe\App\BusinessRecord\Application\DocumentCommitTimings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Holds the shared timing recorder to the frame discipline the document command depends on.
 *
 * The recorder is wired into collaborators every mutation shares — the fence path, the publication — so
 * its silence outside a frame is what keeps ordinary record commands from paying for, or polluting, a
 * measurement only the document command reads. Each promise is proven the way it would break: a span
 * reported with no frame open, a failed command abandoning its frame, and a retry accumulating rather
 * than replacing.
 *
 * @since  2.0.0
 */
#[CoversClass(DocumentCommitTimingRecorder::class)]
#[CoversClass(DocumentCommitTimings::class)]
final class DocumentCommitTimingRecorderTest extends TestCase
{
    /**
     * A span reported while no frame is open is dropped rather than attributed to anything.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASpanOutsideAFrameIsDropped(): void
    {
        $recorder = new DocumentCommitTimingRecorder();
        $recorder->add('lock_wait', 5.0);
        $recorder->commit(10.0);

        self::assertNull($recorder->latest(), 'Nothing was armed, so nothing may be recorded.');
        self::assertSame(0.0, $recorder->accumulated('lock_wait'));
    }

    /**
     * A committed frame reports every phase it accumulated, and accumulation adds rather than replaces.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACommittedFrameReportsAccumulatedPhases(): void
    {
        $recorder = new DocumentCommitTimingRecorder();
        $recorder->begin();
        $recorder->add('lock_wait', 2.0);
        $recorder->add('lock_wait', 3.0);
        $recorder->add('validation', 7.0);
        $recorder->add('write', 11.0);
        $recorder->add('revision', 1.0);
        $recorder->add('audit', 1.5);
        $recorder->add('event', 0.5);
        self::assertSame(5.0, $recorder->accumulated('lock_wait'));
        $recorder->commit(30.0);

        $timings = $recorder->latest();
        self::assertNotNull($timings);
        self::assertSame(
            [
                'validation' => 7.0,
                'lock_wait' => 5.0,
                'write' => 11.0,
                'revision' => 1.0,
                'audit' => 1.5,
                'event' => 0.5,
                'total' => 30.0,
            ],
            $timings->toArray(),
        );
    }

    /**
     * An abandoned frame records nothing and leaves the previous commit's answer standing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAbandonedFrameLeavesThePreviousAnswerStanding(): void
    {
        $recorder = new DocumentCommitTimingRecorder();
        $recorder->begin();
        $recorder->add('write', 4.0);
        $recorder->commit(6.0);
        $committed = $recorder->latest();

        $recorder->begin();
        $recorder->add('write', 99.0);
        $recorder->abandon();

        self::assertSame($committed, $recorder->latest());
        $recorder->add('write', 50.0);
        self::assertSame(0.0, $recorder->accumulated('write'), 'An abandoned frame stays closed.');
    }
}
