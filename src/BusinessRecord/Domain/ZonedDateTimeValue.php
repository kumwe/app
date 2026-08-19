<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * A UTC instant paired with the IANA timezone the value was authored in.
 *
 * This is the whole value of a `core.zoned_datetime` field: `RecordValueCodec` splits it across the
 * `.instant` and `.timezone` physical columns and rebuilds it on read, because an offset alone loses
 * the rule that produced it — keeping the zone name means a stored time can still be rendered as its
 * author meant after that zone's offset rules change. The constructor is private, so every instance
 * has come through `fromStrings()` and already holds a portable UTC instant beside a canonical zone
 * name, which is what lets `toArray()` be stored, checksummed and compared as plain strings.
 *
 * @since  2.0.0
 */
final readonly class ZonedDateTimeValue
{
    /**
     * Hold an instant and zone that `fromStrings()` has already normalised.
     *
     * @param  DateTimeImmutable  $instant   Moment in time, carried in UTC.
     * @param  string             $timezone  Canonical IANA zone name the value was authored in.
     *
     * @since  2.0.0
     */
    private function __construct(public DateTimeImmutable $instant, public string $timezone)
    {
    }

    /**
     * Build the value from a canonical UTC instant and an IANA zone name.
     *
     * Nothing is repaired silently. The instant is accepted only in the exact form this type writes —
     * `Y-m-d\TH:i:s`, optional microseconds, then `Z` or `+00:00` — and is re-inspected after parsing
     * for a zero offset, a four-digit year, and a parse that raised no warning or error, so a value PHP
     * would quietly coerce is refused instead. The timezone must appear in
     * `DateTimeZone::listIdentifiers()`, which rules out the raw offsets and abbreviations
     * `DateTimeZone` would otherwise construct happily.
     *
     * @param   string  $instant   UTC timestamp in `Y-m-d\TH:i:s[.u]` form with a `Z` or `+00:00` suffix.
     * @param   string  $timezone  Canonical IANA zone name, such as `Africa/Johannesburg`.
     *
     * @return  self  Value holding the parsed instant in UTC beside the canonical zone name.
     *
     * @throws  InvalidArgumentException  When the instant is not in canonical UTC form, does not parse
     *          cleanly into a portable UTC time, or the timezone is not a canonical IANA identifier.
     *
     * @since   2.0.0
     */
    public static function fromStrings(string $instant, string $timezone): self
    {
        if (
            preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
                . '(?:\.[0-9]{1,6})?(?:Z|\+00:00)$/D',
                $instant,
            ) !== 1
        ) {
            throw new InvalidArgumentException('A zoned date-time instant must use canonical UTC form.');
        }
        try {
            $zone = new DateTimeZone($timezone);
            $parsed = new DateTimeImmutable($instant);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'A zoned date-time contains an invalid instant or timezone.',
                0,
                $exception,
            );
        }
        $errors = DateTimeImmutable::getLastErrors();
        $year = (int) $parsed->format('Y');
        if (
            $parsed->format('P') !== '+00:00' || $year < 1000 || $year > 9999
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
        ) {
            throw new InvalidArgumentException('A zoned date-time instant must use valid portable UTC time.');
        }
        if (!in_array($zone->getName(), DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('A zoned date-time requires a canonical IANA timezone.');
        }

        return new self($parsed->setTimezone(new DateTimeZone('UTC')), $zone->getName());
    }

    /**
     * Export the pair in the canonical shape used for storage, checksums and API output.
     *
     * @return  array{instant: string, timezone: string}  The instant as `Y-m-d\TH:i:s.u\Z`, always with
     *          six fractional digits, beside the IANA zone name.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'instant' => $this->instant->format('Y-m-d\TH:i:s.u\Z'),
            'timezone' => $this->timezone,
        ];
    }
}
