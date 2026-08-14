<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use InvalidArgumentException;

/**
 * The one rule that turns a request and a rate into a converted amount.
 *
 * Core owns the arithmetic so that two extensions converting the same amount with the same rate produce
 * the same figure and the same provenance. The rule is short and deliberately has no discretion in it:
 * multiply exactly, round once by the mode the request declared, and hand back a value that carries the
 * unrounded product beside the rounded one. Choosing the rate is the provider's decision and choosing
 * the rounding is the request's; neither is made here.
 *
 * The converter reads stored values and writes none. It holds no state and no collaborator, which is
 * what lets a domain object and an application service use the same instance of it.
 *
 * @since  2.0.0
 */
final readonly class MoneyConverter
{
    /**
     * Apply one rate to one request and produce the fully evidenced result.
     *
     * @param   MoneyConversionRequest  $request  Amount, target currency, as-at instant and declared rounding.
     * @param   MoneyExchangeRate       $rate     Rate offered for that request by a rate provider.
     *
     * @return  ConvertedMoneyValue  The presented figure, marked as converted and carrying its whole provenance.
     *
     * @throws  InvalidArgumentException  When the rate prices another pair, is dated after the instant asked
     *          about, or the exact product is wider than a portable exact value can hold.
     *
     * @since   2.0.0
     */
    public function convert(MoneyConversionRequest $request, MoneyExchangeRate $rate): ConvertedMoneyValue
    {
        if (!$request->answeredBy($rate)) {
            throw new InvalidArgumentException(
                'A conversion rate must price the requested pair as at the instant asked about.',
            );
        }
        $unrounded = ExactDecimalArithmetic::multiply($request->amount->amount, $rate->rate);
        $rounded = ExactDecimalArithmetic::round(
            $unrounded,
            $request->precision,
            $request->scale,
            $request->rounding,
        );

        return new ConvertedMoneyValue(
            $request->amount,
            new MoneyValue($rounded, $request->targetCurrency),
            $rate,
            $request->rounding,
            $unrounded,
        );
    }
}
