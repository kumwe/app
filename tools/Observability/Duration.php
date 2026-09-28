<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

use InvalidArgumentException;

/**
 * Converts Prometheus durations such as `5m` or `1h30m` to seconds and back.
 *
 * @since  2.0.0
 */
final class Duration
{
    /**
     * Seconds per unit.
     *
     * @var    array<string, int>
     * @since  2.0.0
     */
    private const UNITS = ['y' => 31_536_000, 'w' => 604_800, 'd' => 86_400, 'h' => 3_600, 'm' => 60, 's' => 1];

    /**
     * Parse a duration.
     *
     * @param   string  $duration  Prometheus duration; milliseconds are not accepted.
     *
     * @return  int  Whole seconds.
     *
     * @throws  InvalidArgumentException  When the text is not a duration.
     *
     * @since   2.0.0
     */
    public static function seconds(string $duration): int
    {
        if (preg_match('/^(?:[0-9]+[ywdhms])+$/D', $duration) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a Prometheus duration.', $duration));
        }
        preg_match_all('/([0-9]+)([ywdhms])/', $duration, $parts, PREG_SET_ORDER);
        $seconds = 0;
        foreach ($parts as [, $amount, $unit]) {
            $seconds += (int) $amount * self::UNITS[$unit];
        }

        return $seconds;
    }

    /**
     * Format seconds as the shortest whole-minute or whole-second duration.
     *
     * @param   int  $seconds  Non-negative seconds.
     *
     * @return  string  Duration text such as `25m` or `90s`.
     *
     * @since   2.0.0
     */
    public static function format(int $seconds): string
    {
        return $seconds % 60 === 0 ? intdiv($seconds, 60) . 'm' : $seconds . 's';
    }
}
