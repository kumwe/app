<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use DateInterval;
use DomainException;
use Error;
use InvalidArgumentException;
use LogicException;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Decides whether a failed job attempt is worth repeating, and how long to wait before repeating it.
 *
 * The policy keeps apart two questions a caller would otherwise blur: whether the fault can clear at all,
 * and how hard to back off if it can. The first is answered from the shape of the thrown value, so a
 * handler signals intent by what it throws rather than by a status it returns. The second is full jitter
 * — a delay drawn uniformly between zero and a cap that doubles each attempt — which scatters a fleet of
 * workers retrying the same outage instead of letting them collide on one schedule. Both the randomness
 * and the clock arrive through the constructor, so the same failure yields the same decision in a test.
 *
 * @since  2.0.0
 */
final readonly class RetryPolicy
{
    /**
     * Configure the backoff window every retry delay is drawn from.
     *
     * @param   ClockInterface  $clock                Reads the instant a retry becomes due.
     * @param   JitterSource    $jitter               Randomness the delay is drawn from, injected for tests.
     * @param   int             $baseDelaySeconds     Jitter cap for the first retry, doubled per attempt after.
     * @param   int             $maximumDelaySeconds  Ceiling the doubling cap is clamped to.
     *
     * @throws  InvalidArgumentException  When the base delay is below one second, or the maximum below the base.
     *
     * @since   2.0.0
     */
    public function __construct(
        private ClockInterface $clock,
        private JitterSource $jitter,
        private int $baseDelaySeconds = 1,
        private int $maximumDelaySeconds = 300,
    ) {
        if ($baseDelaySeconds < 1 || $maximumDelaySeconds < $baseDelaySeconds) {
            throw new InvalidArgumentException('Retry delays must be positive and have a valid upper bound.');
        }
    }

    /**
     * Judge whether a thrown value describes a fault that can clear or one that will repeat.
     *
     * `PermanentFailure`, `LogicException` and `Error` are permanent, because a programming or payload
     * fault reproduces on identical input. Everything else is transient, including throwables the policy
     * has never been taught about, since one more attempt costs less than discarding work over an
     * unfamiliar fault. The `TransientFailure` marker is honoured ahead of the `LogicException` and
     * `Error` fallback, so an exception in that hierarchy that marks itself transient is still retried.
     *
     * @param   Throwable  $failure  Value that ended the attempt.
     *
     * @return  FailureClassification  PERMANENT when a further attempt cannot succeed, TRANSIENT otherwise.
     *
     * @since   2.0.0
     */
    public function classify(Throwable $failure): FailureClassification
    {
        if ($failure instanceof PermanentFailure) {
            return FailureClassification::PERMANENT;
        }

        if ($failure instanceof TransientFailure) {
            return FailureClassification::TRANSIENT;
        }

        if ($failure instanceof LogicException || $failure instanceof Error) {
            return FailureClassification::PERMANENT;
        }

        return FailureClassification::TRANSIENT;
    }

    /**
     * Turn one failed attempt into the verdict and the moment the next attempt falls due.
     *
     * A permanent classification, or an attempt that was already the last one allowed, produces a decision
     * carrying no delay and no instant. Otherwise the delay comes from the jitter source, between zero and
     * the cap for this attempt, and the due instant is that delay after the clock's current reading. The
     * jitter source is not trusted blindly: a value outside the range it was handed is rejected rather than
     * turned into a retry that fires immediately or far too late.
     *
     * @param   Throwable  $failure          Value that ended the attempt.
     * @param   int        $attempt          Number of the attempt that just failed, counting from one.
     * @param   int        $maximumAttempts  Attempts this job is allowed in total.
     *
     * @return  RetryDecision  The classification, plus the delay and due instant when a retry follows.
     *
     * @throws  InvalidArgumentException  When either count is below one, or the attempt is past the limit.
     * @throws  DomainException  When the jitter source returns a value outside the range it was given.
     *
     * @since   2.0.0
     */
    public function decide(Throwable $failure, int $attempt, int $maximumAttempts): RetryDecision
    {
        if ($attempt < 1 || $maximumAttempts < 1 || $attempt > $maximumAttempts) {
            throw new InvalidArgumentException('Retry attempts must be within the configured attempt limit.');
        }

        $classification = $this->classify($failure);

        if ($classification === FailureClassification::PERMANENT || $attempt >= $maximumAttempts) {
            return new RetryDecision($classification, false, 0, null);
        }

        $maximumJitter = $this->delayCapFor($attempt);
        $delay = $this->jitter->between(0, $maximumJitter);

        if ($delay < 0 || $delay > $maximumJitter) {
            throw new DomainException('The jitter source returned a value outside the requested range.');
        }

        return new RetryDecision(
            $classification,
            true,
            $delay,
            $this->clock->now()->add(new DateInterval(sprintf('PT%dS', $delay))),
        );
    }

    /**
     * Work out the upper bound of the jitter window for one attempt.
     *
     * The base delay doubles once per attempt already spent. The loop returns the configured maximum as
     * soon as one more doubling would pass it, so a large attempt number neither overflows the delay nor
     * costs more than a handful of iterations.
     *
     * @param   int  $attempt  Number of the attempt that just failed, counting from one.
     *
     * @return  int  Seconds: the base delay on the first attempt, never above the configured maximum.
     *
     * @since   2.0.0
     */
    private function delayCapFor(int $attempt): int
    {
        $delay = $this->baseDelaySeconds;

        for ($index = 1; $index < $attempt; $index++) {
            if ($delay >= intdiv($this->maximumDelaySeconds, 2)) {
                return $this->maximumDelaySeconds;
            }

            $delay *= 2;
        }

        return min($delay, $this->maximumDelaySeconds);
    }
}
