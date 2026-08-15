<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

/**
 * The declared rule a unit conversion rounds by when the exact product is wider than the target scale.
 *
 * Multiplying an exact quantity by an exact factor almost always produces more fractional digits than
 * the target unit keeps, so a conversion has to say what it did with them. Rounding is therefore an
 * explicit, named step of the conversion contract rather than a side effect: the mode travels with every
 * converted quantity, beside the unrounded product it was applied to, so a reader can reproduce the
 * figure instead of trusting it. Which mode a business uses is the conversion table owner's rule; core
 * only insists that the answer is recorded.
 *
 * The vocabulary matches `MoneyRoundingMode` case for case, because the arithmetic is the same
 * arithmetic; it is a separate type so a quantity conversion is declared and exported in a quantity's
 * own terms and a payload can never claim a currency rule rounded a weight.
 *
 * @since  2.0.0
 */
enum QuantityRoundingMode: string implements ExactRoundingRule
{
    /**
     * Ties move away from zero, which is the ordinary commercial expectation.
     *
     * @since  2.0.0
     */
    case HalfUp = 'half_up';

    /**
     * Ties move toward zero, keeping the smaller magnitude.
     *
     * @since  2.0.0
     */
    case HalfDown = 'half_down';

    /**
     * Ties move to the even neighbour, so repeated rounding does not drift upward.
     *
     * @since  2.0.0
     */
    case HalfEven = 'half_even';

    /**
     * Any remainder moves toward positive infinity, favouring the receiver on a positive quantity.
     *
     * @since  2.0.0
     */
    case Ceiling = 'ceiling';

    /**
     * Any remainder moves toward negative infinity, favouring the issuer on a positive quantity.
     *
     * @since  2.0.0
     */
    case Floor = 'floor';

    /**
     * The dropped digits are discarded, so the magnitude never grows.
     *
     * @since  2.0.0
     */
    case Truncate = 'truncate';

    /**
     * Decide whether the kept digits are incremented once the dropped digits are known.
     *
     * The caller has already split the exact product into the digits the target scale keeps and the
     * digits it drops, so this method never sees a number — only the four facts every rounding rule is
     * expressed in. Keeping the decision here is what lets `ExactDecimalArithmetic` stay pure digit
     * handling and a new mode be added without touching it.
     *
     * @param   int   $firstDropped          First discarded digit, 0 through 9.
     * @param   bool  $remainderBeyondFirst  Whether any discarded digit after the first is non-zero.
     * @param   bool  $lastKeptOdd           Whether the last retained digit is odd.
     * @param   bool  $negative              Whether the value being rounded is below zero.
     *
     * @return  bool  True when the retained magnitude is incremented by one at its last digit.
     *
     * @since   2.0.0
     */
    public function increments(
        int $firstDropped,
        bool $remainderBeyondFirst,
        bool $lastKeptOdd,
        bool $negative,
    ): bool {
        $remainder = $firstDropped > 0 || $remainderBeyondFirst;

        return match ($this) {
            self::HalfUp => $firstDropped >= 5,
            self::HalfDown => $firstDropped > 5 || ($firstDropped === 5 && $remainderBeyondFirst),
            self::HalfEven => $firstDropped > 5
                || ($firstDropped === 5 && ($remainderBeyondFirst || $lastKeptOdd)),
            self::Ceiling => $remainder && !$negative,
            self::Floor => $remainder && $negative,
            self::Truncate => false,
        };
    }
}
