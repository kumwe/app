<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class RecordValueGuard
{
    public static function assertValue(mixed $value, int $depth = 0, int &$nodes = 0): void
    {
        ++$nodes;
        if ($depth > 8 || $nodes > 4096) {
            throw new InvalidArgumentException('A business-record value exceeds its structural bounds.');
        }
        if (is_float($value)) {
            throw new InvalidArgumentException('Business-record values cannot contain PHP floats.');
        }
        if (
            $value === null || is_bool($value) || is_int($value) || is_string($value)
            || $value instanceof ExactDecimal || $value instanceof MoneyValue
            || $value instanceof QuantityValue || $value instanceof ZonedDateTimeValue
            || $value instanceof EncryptedEnvelope || $value instanceof DateTimeImmutable
        ) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertValue($item, $depth + 1, $nodes);
            }
            return;
        }

        throw new InvalidArgumentException('A business-record value has an unsupported runtime type.');
    }

    public static function canonical(mixed $value): mixed
    {
        if ($value instanceof ExactDecimal) {
            return $value->value();
        }
        if ($value instanceof MoneyValue || $value instanceof QuantityValue || $value instanceof ZonedDateTimeValue) {
            return $value->toArray();
        }
        if ($value instanceof EncryptedEnvelope) {
            return $value->toStorage();
        }
        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = self::canonical($item);
            }
            if (!array_is_list($result)) {
                ksort($result, SORT_STRING);
            }
            return $result;
        }

        self::assertValue($value);
        return $value;
    }

    private function __construct()
    {
    }
}
