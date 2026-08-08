<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessRecord\Domain;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Domain\RecordValueGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExactDecimal::class)]
#[CoversClass(RecordValueGuard::class)]
final class ExactDecimalTest extends TestCase
{
    public function testDecimalWithEqualPrecisionAndScaleAcceptsZeroAndMaximumFraction(): void
    {
        self::assertSame('0.' . str_repeat('0', 65), ExactDecimal::fromString('0', 65, 65)->value());
        self::assertSame(
            '0.' . str_repeat('9', 65),
            ExactDecimal::fromString('0.' . str_repeat('9', 65), 65, 65)->value(),
        );
    }

    public function testPortableSixtyFiveDigitBoundaryRejectsOverflow(): void
    {
        self::assertSame(str_repeat('9', 65), ExactDecimal::fromString(str_repeat('9', 65), 65, 0)->value());

        $this->expectException(InvalidArgumentException::class);
        ExactDecimal::fromString(str_repeat('9', 66), 65, 0);
    }

    public function testGeneratedCanonicalValuesRoundTripWithoutFloatLoss(): void
    {
        mt_srand(20260808);
        for ($iteration = 0; $iteration < 500; ++$iteration) {
            $integer = (string) mt_rand(0, 999_999);
            $fraction = str_pad((string) mt_rand(0, 9_999), 4, '0', STR_PAD_LEFT);
            $value = ($iteration % 2 === 0 ? '-' : '') . $integer . '.' . $fraction;
            self::assertSame($value, ExactDecimal::fromString($value, 10, 4)->value());
        }
    }

    public function testGenericRecordValuesRejectFloats(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $nodes = 0;
        RecordValueGuard::assertValue(0.1, 0, $nodes);
    }
}
