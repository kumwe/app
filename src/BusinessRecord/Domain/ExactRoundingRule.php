<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

/**
 * The decision a declared rounding rule makes about the digits a target scale discards.
 *
 * Money and quantity conversions narrow their exact product the same way, and `ExactDecimalArithmetic`
 * has no idea which of the two it is narrowing, so the two contracts share this one contract and nothing
 * else. Each still names its own vocabulary — `MoneyRoundingMode` and `QuantityRoundingMode` — because
 * which modes a business offers for a currency and for a unit of measure are separate declarations that
 * travel in separate payloads, and collapsing them into one enum would let a money conversion be
 * described in a quantity's terms.
 *
 * Implementations are backed enums, so a mode is a value an export can carry and a reader can resolve,
 * not a strategy object a caller has to construct.
 *
 * @since  2.0.0
 */
interface ExactRoundingRule
{
    /**
     * Decide whether the kept digits are incremented once the dropped digits are known.
     *
     * The caller has already split the exact product into the digits the target scale keeps and the
     * digits it drops, so an implementation never sees a number — only the four facts every rounding
     * rule is expressed in. That is what lets `ExactDecimalArithmetic` stay pure digit handling and a
     * new mode be added without touching it.
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
    ): bool;
}
