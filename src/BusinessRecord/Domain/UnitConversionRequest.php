<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * What a caller asks for when it wants a stored quantity expressed in another unit of measure.
 *
 * The request is the half of the conversion contract a table owner reads. It names the quantity, the
 * unit it is to be expressed in, the instant the factor must be as at, and — because rounding is a
 * declared step and never an accident — the exact shape the answer is to be rounded to. A provider
 * decides which factor answers this; it never decides what the answer looks like.
 *
 * Nothing here reaches storage. A request is built above stored exact values, is satisfied for one
 * presentation, one picking list or one report row, and is discarded.
 *
 * @since  2.0.0
 */
final readonly class UnitConversionRequest
{
    /**
     * State the quantity, the target unit, the as-at instant, and the rounding to apply.
     *
     * @param   QuantityValue         $quantity    Stored quantity being expressed, with the unit it is held in.
     * @param   string                $targetUnit  Portable identifier of the unit the quantity is to be shown in.
     * @param   DateTimeImmutable     $asAt        Instant the factor must be as at, spelled in UTC; a provider may
     *          answer with an earlier factor but never with a later one.
     * @param   int                   $precision   Total digit budget the converted quantity is expressed at, 1 to 65.
     * @param   int                   $scale       Fractional digits the target unit keeps, 0 to $precision.
     * @param   QuantityRoundingMode  $rounding    Declared rule for the digits the target scale discards.
     *
     * @throws  InvalidArgumentException  When the target unit is not a bounded portable identifier or is the unit
     *          the quantity is already held in, the instant is not UTC, or precision and scale fall outside the
     *          portable exact range.
     *
     * @since   2.0.0
     */
    public function __construct(
        public QuantityValue $quantity,
        public string $targetUnit,
        public DateTimeImmutable $asAt,
        public int $precision,
        public int $scale,
        public QuantityRoundingMode $rounding,
    ) {
        if (preg_match(UnitConversionFactor::UNIT_PATTERN, $targetUnit) !== 1) {
            throw new InvalidArgumentException('A conversion target unit must be a bounded portable identifier.');
        }
        if ($targetUnit === $quantity->unit) {
            throw new InvalidArgumentException('A conversion must name a unit other than the quantity\'s own.');
        }
        if ($asAt->format('P') !== '+00:00') {
            throw new InvalidArgumentException('A conversion as-at instant must be expressed in UTC.');
        }
        if (
            $precision < 1 || $precision > ExactDecimalArithmetic::MAXIMUM_PRECISION
            || $scale < 0 || $scale > $precision
        ) {
            throw new InvalidArgumentException('A conversion precision or scale is outside the portable range.');
        }
    }

    /**
     * Whether one factor is the right shape and vintage to answer this request.
     *
     * A provider that offers a factor for the wrong pair of units, or one dated after the instant asked
     * about, has answered a different question. Checking it here keeps the rule in one place rather than
     * in every provider, and keeps last week's case size from being presented as though it were the one
     * that applied when the document was raised.
     *
     * @param   UnitConversionFactor  $factor  Factor a provider offered for this request.
     *
     * @return  bool  True when the factor relates this pair and is as at this instant or earlier.
     *
     * @since   2.0.0
     */
    public function answeredBy(UnitConversionFactor $factor): bool
    {
        return $factor->sourceUnit === $this->quantity->unit
            && $factor->targetUnit === $this->targetUnit
            && $factor->asAt <= $this->asAt;
    }
}
