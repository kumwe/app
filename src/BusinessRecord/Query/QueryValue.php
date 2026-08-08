<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Domain\MoneyValue;
use Kumwe\CMS\BusinessRecord\Domain\QuantityValue;
use Kumwe\CMS\BusinessRecord\Domain\ZonedDateTimeValue;

final class QueryValue
{
    public static function assert(mixed $value): void
    {
        if (
            $value === null || is_bool($value) || is_int($value) || is_string($value)
            || $value instanceof DateTimeImmutable || $value instanceof ExactDecimal
            || $value instanceof MoneyValue || $value instanceof QuantityValue
            || $value instanceof ZonedDateTimeValue
        ) {
            if (is_string($value) && strlen($value) > 4096) {
                throw new InvalidArgumentException('A query value exceeds 4096 bytes.');
            }
            return;
        }

        throw new InvalidArgumentException('A query value must be a bounded typed scalar and cannot be a float.');
    }

    private function __construct()
    {
    }
}
