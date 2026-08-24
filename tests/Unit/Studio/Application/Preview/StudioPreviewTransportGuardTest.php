<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Preview;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRefused;
use Kumwe\App\Studio\Application\Preview\StudioPreviewSequenceClaim;
use Kumwe\App\Studio\Application\Preview\StudioPreviewSequenceRepository;
use Kumwe\App\Studio\Application\Preview\StudioPreviewSequenceWaiter;
use Kumwe\App\Studio\Application\Preview\StudioPreviewTransportGuard;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use Kumwe\App\Studio\Infrastructure\Transport\NativeStudioPreviewSequenceWaiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins each transport refusal to its own non-disclosing diagnostic and proves monotonic replay defence.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioPreviewTransportGuard::class)]
#[CoversClass(StudioPreviewTransport::class)]
#[CoversClass(NativeStudioPreviewSequenceWaiter::class)]
final class StudioPreviewTransportGuardTest extends TestCase
{
    /**
     * Reject an invalid configured origin and any delivery lane outside the closed transport vocabulary.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testConfigurationAndLaneVocabulariesAreClosed(): void
    {
        $sequences = self::createStub(StudioPreviewSequenceRepository::class);
        $waiter = self::createStub(StudioPreviewSequenceWaiter::class);

        try {
            new StudioPreviewTransportGuard('/relative-origin', $sequences, $waiter);
            self::fail('A relative Studio preview origin was accepted.');
        } catch (InvalidArgumentException $failure) {
            self::assertSame('The Studio preview base origin is invalid.', $failure->getMessage());
        }

        $invalidTransports = [
            'origin' => static fn () => new StudioPreviewTransport(
                'https://kumwe.test/path',
                'channels/one',
                'sources/one',
                0,
            ),
            'identity' => static fn () => new StudioPreviewTransport(
                'https://kumwe.test',
                'bad channel',
                'sources/one',
                0,
            ),
            'sequence' => static fn () => new StudioPreviewTransport(
                'https://kumwe.test',
                'channels/one',
                'sources/one',
                -1,
            ),
        ];
        foreach ($invalidTransports as $case => $operation) {
            try {
                $operation();
                self::fail('An invalid Studio preview transport was accepted: ' . $case);
            } catch (InvalidArgumentException $failure) {
                self::assertNotSame('', $failure->getMessage(), $case);
            }
        }

        $snapshot = self::snapshot();
        $guard = new StudioPreviewTransportGuard('https://kumwe.test', $sequences, $waiter);
        self::assertRefusal(
            'studio.preview/invalid-lane',
            fn () => $guard->authorize(
                $snapshot,
                new StudioPreviewTransport(
                    'https://kumwe.test',
                    $guard->channelId($snapshot->session),
                    $guard->sourceId($snapshot->session),
                    0,
                ),
                'side-channel',
            ),
        );
    }

    /**
     * Foreign origin, wrong channel, wrong source and replayed/out-of-order sequence are distinguishable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryTransportFailureHasADistinctStableCode(): void
    {
        $sequences = new class implements StudioPreviewSequenceRepository {
            /**
             * Next acceptable sequence by resource and delivery lane.
             *
             * @var    array<string, int>
             * @since  2.0.0
             */
            private array $next = [];

            /**
             * Advance exactly one monotonic delivery sequence.
             *
             * @param   string  $resourceContextKey  Opaque resource-context identity.
             * @param   string  $lane                Closed delivery lane.
             * @param   int     $sequence            Claimed next sequence.
             *
             * @return  StudioPreviewSequenceClaim  Atomic claim classification.
             *
             * @since   2.0.0
             */
            public function advance(
                string $resourceContextKey,
                string $lane,
                int $sequence,
            ): StudioPreviewSequenceClaim {
                $key = $resourceContextKey . ':' . $lane;
                $expected = $this->next[$key] ?? 0;
                if ($expected === $sequence) {
                    $this->next[$key] = $sequence + 1;

                    return StudioPreviewSequenceClaim::Accepted;
                }

                return $expected < PHP_INT_MAX && $sequence === $expected + 1
                    ? StudioPreviewSequenceClaim::PredecessorPending
                    : StudioPreviewSequenceClaim::Refused;
            }
        };
        $waiter = new class implements StudioPreviewSequenceWaiter {
            /**
             * Return immediately while refusal behavior is exercised.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function pause(): void
            {
            }
        };
        $guard = new StudioPreviewTransportGuard('https://kumwe.test/base', $sequences, $waiter);
        $snapshot = self::snapshot();
        $channel = $guard->channelId($snapshot->session);
        $source = $guard->sourceId($snapshot->session);

        foreach (['port', 'document'] as $lane) {
            self::assertRefusal(
                'studio.preview/foreign-origin',
                fn () => $guard->authorize(
                    $snapshot,
                    new StudioPreviewTransport('https://foreign.test', $channel, $source, 0),
                    $lane,
                ),
            );
            self::assertRefusal(
                'studio.preview/wrong-channel',
                fn () => $guard->authorize(
                    $snapshot,
                    new StudioPreviewTransport('https://kumwe.test', 'channels/wrong', $source, 0),
                    $lane,
                ),
            );
            self::assertRefusal(
                'studio.preview/wrong-source',
                fn () => $guard->authorize(
                    $snapshot,
                    new StudioPreviewTransport('https://kumwe.test', $channel, 'sources/wrong', 0),
                    $lane,
                ),
            );
            self::assertRefusal(
                'studio.preview/sequence-replayed',
                fn () => $guard->authorize(
                    $snapshot,
                    new StudioPreviewTransport('https://kumwe.test', $channel, $source, 1),
                    $lane,
                ),
            );

            $guard->authorize(
                $snapshot,
                new StudioPreviewTransport('https://kumwe.test', $channel, $source, 0),
                $lane,
            );
            self::assertRefusal(
                'studio.preview/sequence-replayed',
                fn () => $guard->authorize(
                    $snapshot,
                    new StudioPreviewTransport('https://kumwe.test', $channel, $source, 0),
                    $lane,
                ),
            );
        }
    }

    /**
     * One immediate-future request can yield for its predecessor, while a larger gap never yields.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyTheImmediatePredecessorCanBeAwaited(): void
    {
        $sequences = new class implements StudioPreviewSequenceRepository {
            /**
             * Next sequence after any claim made by the waiter.
             *
             * @var    int
             * @since  2.0.0
             */
            private int $next = 0;

            /**
             * Classify one candidate against the mutable expected sequence.
             *
             * @param   string  $resourceContextKey  Opaque resource context.
             * @param   string  $lane                Closed delivery lane.
             * @param   int     $sequence            Candidate sequence.
             *
             * @return  StudioPreviewSequenceClaim  Atomic claim classification.
             *
             * @since   2.0.0
             */
            public function advance(
                string $resourceContextKey,
                string $lane,
                int $sequence,
            ): StudioPreviewSequenceClaim {
                if ($sequence === $this->next) {
                    $this->next++;

                    return StudioPreviewSequenceClaim::Accepted;
                }

                return $sequence === $this->next + 1
                    ? StudioPreviewSequenceClaim::PredecessorPending
                    : StudioPreviewSequenceClaim::Refused;
            }
        };
        $waiter = new class ($sequences) implements StudioPreviewSequenceWaiter {
            /**
             * Number of real scheduling yields completed before the predecessor is released.
             *
             * @var    int
             * @since  2.0.0
             */
            private int $pauses = 0;

            /**
             * Bind the pause to the predecessor claim the concurrent request would make.
             *
             * @param  StudioPreviewSequenceRepository  $sequences  Shared atomic sequence state.
             *
             * @since  2.0.0
             */
            public function __construct(private StudioPreviewSequenceRepository $sequences)
            {
            }

            /**
             * Release the predecessor only after more than one hundred milliseconds of scheduling delay.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function pause(): void
            {
                $this->pauses++;
                (new NativeStudioPreviewSequenceWaiter())->pause();
                if ($this->pauses === 15) {
                    $this->sequences->advance('contexts/preview-guard', 'port', 0);
                }
            }

            /**
             * Return the exact number of scheduling yields observed.
             *
             * @return  int  Completed yields.
             *
             * @since   2.0.0
             */
            public function pauses(): int
            {
                return $this->pauses;
            }
        };
        $snapshot = self::snapshot();
        $guard = new StudioPreviewTransportGuard('https://kumwe.test', $sequences, $waiter);
        $transport = static fn (int $sequence): StudioPreviewTransport => new StudioPreviewTransport(
            'https://kumwe.test',
            $guard->channelId($snapshot->session),
            $guard->sourceId($snapshot->session),
            $sequence,
        );

        $started = microtime(true);
        $guard->authorize($snapshot, $transport(1), 'port');
        $elapsed = microtime(true) - $started;
        self::assertGreaterThan(0.1, $elapsed);
        self::assertRefusal(
            'studio.preview/sequence-replayed',
            fn () => $guard->authorize($snapshot, $transport(1), 'port'),
        );
        self::assertSame(15, $waiter->pauses());
        self::assertRefusal(
            'studio.preview/sequence-replayed',
            fn () => $guard->authorize($snapshot, $transport(4), 'port'),
        );
        self::assertSame(15, $waiter->pauses());
    }

    /**
     * A missing immediate predecessor retains a worker for less than the fixed scheduling ceiling.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testImmediatePredecessorWaitIsBounded(): void
    {
        $sequences = new class implements StudioPreviewSequenceRepository {
            /**
             * Keep reporting exactly one missing predecessor.
             *
             * @param   string  $resourceContextKey  Opaque resource context.
             * @param   string  $lane                Closed delivery lane.
             * @param   int     $sequence            Candidate sequence.
             *
             * @return  StudioPreviewSequenceClaim  Immediate predecessor remains pending.
             *
             * @since   2.0.0
             */
            public function advance(
                string $resourceContextKey,
                string $lane,
                int $sequence,
            ): StudioPreviewSequenceClaim {
                return StudioPreviewSequenceClaim::PredecessorPending;
            }
        };
        $waiter = new class implements StudioPreviewSequenceWaiter {
            /**
             * Number of scheduling yields requested by the guard.
             *
             * @var    int
             * @since  2.0.0
             */
            private int $pauses = 0;

            /**
             * Count one yield without making the unit test depend on operating-system scheduler timing.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function pause(): void
            {
                $this->pauses++;
            }

            /**
             * Return the exact number of scheduling yields requested.
             *
             * @return  int  Completed yields.
             *
             * @since   2.0.0
             */
            public function pauses(): int
            {
                return $this->pauses;
            }
        };
        $snapshot = self::snapshot();
        $guard = new StudioPreviewTransportGuard(
            'https://kumwe.test',
            $sequences,
            $waiter,
        );

        self::assertRefusal(
            'studio.preview/sequence-replayed',
            fn () => $guard->authorize(
                $snapshot,
                new StudioPreviewTransport(
                    'https://kumwe.test',
                    $guard->channelId($snapshot->session),
                    $guard->sourceId($snapshot->session),
                    1,
                ),
                'port',
            ),
        );
        self::assertSame(100, $waiter->pauses());
    }

    /**
     * Assert one guard callback throws the expected stable refusal.
     *
     * @param   string    $code      Expected diagnostic.
     * @param   callable  $callback  Guard invocation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertRefusal(string $code, callable $callback): void
    {
        try {
            $callback();
            self::fail('The Studio preview guard accepted invalid transport evidence.');
        } catch (StudioPreviewRefused $refused) {
            self::assertSame($code, $refused->diagnosticCode);
        }
    }

    /**
     * Build one trusted preview session snapshot without involving HTTP identity middleware.
     *
     * @return  StudioHostSessionSnapshot  Live read-authorized session.
     *
     * @since   2.0.0
     */
    private static function snapshot(): StudioHostSessionSnapshot
    {
        $session = new StudioHostSession(
            'contexts/preview-guard',
            'actor-1',
            'default',
            null,
            null,
            'administrator',
            hash('sha256', 'session-1'),
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            'blueprints/preview',
            'session-preview-1',
        );

        return new StudioHostSessionSnapshot(
            $session,
            ['studio.permission/read'],
            $session->sessionGeneration,
            true,
            false,
            false,
        );
    }
}
