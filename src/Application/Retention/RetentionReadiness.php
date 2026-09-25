<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Retention;

use InvalidArgumentException;

/**
 * Turns a set of retention observations into the warn-or-fail verdict readiness publishes.
 *
 * The rules are the capacity contract's, made executable. A required setting that is absent fails the
 * enterprise profile and warns the baseline one, because an installation that has not declared itself
 * enterprise-scale is allowed to run without every drain configured but must be told. A drain slope
 * that predicts exhaustion inside the failure horizon fails either profile, inside the warning horizon
 * warns either, and a backlog already at capacity fails. Every reason names its store, so the verdict
 * reads as a checklist rather than a boolean.
 *
 * @since  2.0.0
 */
final readonly class RetentionReadiness
{
    /**
     * Declare the horizons.
     *
     * @param   int  $failureHorizonSeconds  Forecast at or below which the verdict fails; one day by default.
     * @param   int  $warningHorizonSeconds  Forecast at or below which the verdict warns; seven days by default.
     *
     * @throws  InvalidArgumentException  When a horizon is not positive or the warning horizon is shorter
     *          than the failure horizon.
     *
     * @since   2.0.0
     */
    public function __construct(
        private int $failureHorizonSeconds = 86_400,
        private int $warningHorizonSeconds = 604_800,
    ) {
        if ($failureHorizonSeconds < 1 || $warningHorizonSeconds < $failureHorizonSeconds) {
            throw new InvalidArgumentException('The retention readiness horizons are inverted or not positive.');
        }
    }

    /**
     * Assess a set of observations under one capacity profile.
     *
     * @param   list<RetentionObservation>  $observations  One per store, as the observer reported them.
     * @param   bool                        $enterprise    True when the installation declares the enterprise
     *          profile, which turns a missing required setting from a warning into a failure.
     *
     * @return  RetentionVerdict  The worst state reached and every reason behind it.
     *
     * @since   2.0.0
     */
    public function assess(array $observations, bool $enterprise): RetentionVerdict
    {
        $state = RetentionReadinessState::Ready;
        $reasons = [];
        foreach ($observations as $observation) {
            $store = $observation->store->value;
            if (!$observation->configured) {
                foreach ($observation->settingProblems as $problem) {
                    $reasons[] = sprintf('%s: required retention setting %s', $store, $problem);
                }
                $state = self::worst(
                    $state,
                    $enterprise ? RetentionReadinessState::Failed : RetentionReadinessState::Warning,
                );
            }
            if ($observation->backlogRows >= $observation->capacityRows) {
                $reasons[] = sprintf('%s: backlog has reached its declared capacity', $store);
                $state = self::worst($state, RetentionReadinessState::Failed);
            }
            $forecast = $observation->forecastSecondsToCapacity;
            if ($forecast !== null && $forecast <= $this->failureHorizonSeconds) {
                $reasons[] = sprintf('%s: drain slope predicts exhaustion within %d seconds', $store, (int) $forecast);
                $state = self::worst($state, RetentionReadinessState::Failed);
            } elseif ($forecast !== null && $forecast <= $this->warningHorizonSeconds) {
                $reasons[] = sprintf('%s: drain slope predicts exhaustion within %d seconds', $store, (int) $forecast);
                $state = self::worst($state, RetentionReadinessState::Warning);
            }
        }

        return new RetentionVerdict($state, $reasons);
    }

    /**
     * Keep the worse of two states.
     *
     * @param   RetentionReadinessState  $current    State reached so far.
     * @param   RetentionReadinessState  $candidate  State a finding implies.
     *
     * @return  RetentionReadinessState  Whichever is worse.
     *
     * @since   2.0.0
     */
    private static function worst(
        RetentionReadinessState $current,
        RetentionReadinessState $candidate,
    ): RetentionReadinessState {
        return $candidate->value > $current->value ? $candidate : $current;
    }
}
