<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

/**
 * The declared rule a conversion rounds by when the exact product is wider than the target scale.
 *
 * Multiplying an exact amount by an exact rate almost always produces more fractional digits than the
 * currency keeps, so a conversion has to say what it did with them. Rounding is therefore an explicit,
 * named step of the conversion contract rather than a side effect: the mode travels with every
 * converted amount, beside the unrounded product it was applied to, so a reader can reproduce the
 * figure instead of trusting it. Which mode a business uses is the rate owner's rule; core only
 * insists that the answer is recorded.
 *
 * @since  2.0.0
 */
enum MoneyRoundingMode: string
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
     * Any remainder moves toward positive infinity, favouring the payee on a positive amount.
     *
     * @since  2.0.0
     */
    case Ceiling = 'ceiling';

    /**
     * Any remainder moves toward negative infinity, favouring the payer on a positive amount.
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
