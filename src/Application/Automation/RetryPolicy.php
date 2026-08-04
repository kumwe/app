<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DateInterval;
use DomainException;
use Error;
use InvalidArgumentException;
use LogicException;
use Psr\Clock\ClockInterface;
use Throwable;

final readonly class RetryPolicy
{
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
