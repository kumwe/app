<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final readonly class CronExpression
{
    /** @var array<int, true> */
    private array $minutes;

    /** @var array<int, true> */
    private array $hours;

    /** @var array<int, true> */
    private array $days;

    /** @var array<int, true> */
    private array $months;

    /** @var array<int, true> */
    private array $weekdays;

    private bool $anyDay;

    private bool $anyWeekday;

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

    public function __toString(): string
    {
        return $this->expression;
    }

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

    /** @return array<int, true> */
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
