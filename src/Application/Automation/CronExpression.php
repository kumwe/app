<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Five-field cron expression parsed once and asked for the instants a schedule fires on.
 *
 * `DoctrineScheduler` builds one when a schedule is created, so an unusable expression is rejected
 * before it reaches the database, and builds it again at dispatch to advance `next_run_at`. Each
 * field accepts `*`, a single value, a range, a comma-separated list, and a `/step` suffix, and a
 * day-of-week seven is folded onto zero so both spellings of Sunday work. The two calendar fields
 * follow the traditional cron rule: when day-of-month and day-of-week are both restricted, either
 * one matching is enough, and when either is a bare `*` both have to match.
 *
 * @since  2.0.0
 */
final readonly class CronExpression
{
    /**
     * Minutes of the hour the expression fires on, as a set keyed by minute.
     *
     * @var    array<int, true>
     * @since  2.0.0
     */
    private array $minutes;

    /**
     * Hours of the day the expression fires on, as a set keyed by hour on a 24-hour clock.
     *
     * @var    array<int, true>
     * @since  2.0.0
     */
    private array $hours;

    /**
     * Days of the month the expression fires on, as a set keyed by day number.
     *
     * @var    array<int, true>
     * @since  2.0.0
     */
    private array $days;

    /**
     * Months the expression fires in, as a set keyed by month number.
     *
     * @var    array<int, true>
     * @since  2.0.0
     */
    private array $months;

    /**
     * Days of the week the expression fires on, as a set keyed 0 (Sunday) through 6.
     *
     * @var    array<int, true>
     * @since  2.0.0
     */
    private array $weekdays;

    /**
     * Whether the day-of-month field was written as a bare `*`, which decides how the calendar fields combine.
     *
     * @var    bool
     * @since  2.0.0
     */
    private bool $anyDay;

    /**
     * Whether the day-of-week field was written as a bare `*`, which decides how the calendar fields combine.
     *
     * @var    bool
     * @since  2.0.0
     */
    private bool $anyWeekday;

    /**
     * Parse an expression into the per-field match sets used for every later lookup.
     *
     * @param   string  $expression  Five whitespace-separated fields: minute, hour, day of month, month, weekday.
     *
     * @throws  InvalidArgumentException  When the expression does not hold exactly five fields, when a field
     *          is malformed, or when a value falls outside the field's range.
     *
     * @since   2.0.0
     */
    public function __construct(private string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression));

        if (!is_array($parts) || count($parts) !== 5) {
            throw new InvalidArgumentException('A schedule requires a five-field cron expression.');
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;
        $this->minutes = $this->parse($minute, 0, 59);
        $this->hours = $this->parse($hour, 0, 23);
        $this->days = $this->parse($day, 1, 31);
        $this->months = $this->parse($month, 1, 12);
        $this->weekdays = $this->parse($weekday, 0, 7, true);
        $this->anyDay = $day === '*';
        $this->anyWeekday = $weekday === '*';
    }

    /**
     * Find the first instant strictly after a given time that the expression fires on.
     *
     * Matching happens in the schedule's own timezone, so an expression pinned to a wall-clock hour keeps
     * that hour across a daylight-saving shift; the answer is converted to UTC for storage. The search
     * starts at the following whole minute with seconds cleared, and walks forward a minute at a time.
     *
     * @param   DateTimeImmutable  $after     Instant the search starts from, exclusive.
     * @param   string             $timezone  IANA identifier the expression's fields are read in.
     *
     * @return  DateTimeImmutable  The next matching minute, expressed in UTC.
     *
     * @throws  \DateInvalidTimeZoneException  When the timezone is not a known identifier.
     * @throws  RuntimeException  When no minute within the next five years matches, which means
     *          the expression names an impossible date such as 30 February.
     *
     * @since   2.0.0
     */
    public function next(DateTimeImmutable $after, string $timezone): DateTimeImmutable
    {
        $zone = new DateTimeZone($timezone);
        $candidate = $after->setTimezone($zone)->modify('+1 minute')->setTime(
            (int) $after->setTimezone($zone)->modify('+1 minute')->format('H'),
            (int) $after->setTimezone($zone)->modify('+1 minute')->format('i'),
        );
        $limit = $candidate->modify('+5 years');

        while ($candidate <= $limit) {
            if ($this->matches($candidate)) {
                return $candidate->setTimezone(new DateTimeZone('UTC'));
            }

            $candidate = $candidate->modify('+1 minute');
        }

        throw new RuntimeException('The cron expression has no occurrence within five years.');
    }

    /**
     * Render the expression back to the exact text it was constructed from.
     *
     * @return  string  The original expression, unnormalised, so a stored schedule round-trips unchanged.
     *
     * @since   2.0.0
     */
    public function __toString(): string
    {
        return $this->expression;
    }

    /**
     * Decide whether one candidate minute satisfies every field of the expression.
     *
     * @param   DateTimeImmutable  $time  Candidate instant, already expressed in the schedule's timezone.
     *
     * @return  bool  True when minute, hour, month and the calendar-field rule all match.
     *
     * @since   2.0.0
     */
    private function matches(DateTimeImmutable $time): bool
    {
        $dayMatches = isset($this->days[(int) $time->format('j')]);
        $weekdayMatches = isset($this->weekdays[(int) $time->format('w')]);
        $calendarMatches = $this->anyDay || $this->anyWeekday
            ? $dayMatches && $weekdayMatches
            : $dayMatches || $weekdayMatches;

        return isset($this->minutes[(int) $time->format('i')])
            && isset($this->hours[(int) $time->format('G')])
            && isset($this->months[(int) $time->format('n')])
            && $calendarMatches;
    }

    /**
     * Expand one cron field into the set of values it selects.
     *
     * Each comma-separated term is a `*`, a single value or a range, optionally followed by `/step`;
     * stepping always counts from the start of the term's range.
     *
     * @param   string  $field            Raw field text taken from the expression.
     * @param   int     $minimum          Lowest value the field accepts, and the start of `*`.
     * @param   int     $maximum          Highest value the field accepts, and the end of `*`.
     * @param   bool    $normalizeSunday  Whether to fold the weekday value 7 onto 0, for day-of-week only.
     *
     * @return  array<int, true>  Selected values as a set keyed in ascending numeric order.
     *
     * @throws  InvalidArgumentException  When a term is malformed or selects a value outside the range.
     *
     * @since   2.0.0
     */
    private function parse(string $field, int $minimum, int $maximum, bool $normalizeSunday = false): array
    {
        $values = [];

        foreach (explode(',', $field) as $part) {
            if (preg_match('/^(\*|[0-9]+(?:-[0-9]+)?)(?:\/([1-9][0-9]*))?$/D', $part, $matches) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid cron field %s.', $field));
            }

            $step = isset($matches[2]) ? (int) $matches[2] : 1;
            $range = $matches[1];

            if ($range === '*') {
                $start = $minimum;
                $end = $maximum;
            } elseif (str_contains($range, '-')) {
                [$startValue, $endValue] = explode('-', $range, 2);
                $start = (int) $startValue;
                $end = (int) $endValue;
            } else {
                $start = (int) $range;
                $end = (int) $range;
            }

            if ($start < $minimum || $end > $maximum || $start > $end) {
                throw new InvalidArgumentException(sprintf('Cron field %s is outside its allowed range.', $field));
            }

            for ($value = $start; $value <= $end; $value += $step) {
                $values[$normalizeSunday && $value === 7 ? 0 : $value] = true;
            }
        }

        ksort($values, SORT_NUMERIC);

        return $values;
    }
}
