<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final readonly class ZonedDateTimeValue
{
    private function __construct(public DateTimeImmutable $instant, public string $timezone)
    {
    }

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

    /** @return array{instant: string, timezone: string} */
    public function toArray(): array
    {
        return [
            'instant' => $this->instant->format('Y-m-d\TH:i:s.u\Z'),
            'timezone' => $this->timezone,
        ];
    }
}
