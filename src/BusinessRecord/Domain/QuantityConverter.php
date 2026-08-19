<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use InvalidArgumentException;

/**
 * The one rule that turns a request and a factor into a converted quantity.
 *
 * Core owns the arithmetic so that two extensions converting the same quantity with the same factor
 * produce the same figure and the same provenance — which is exactly the disagreement about what a case
 * of a product is that owning the contract exists to prevent. The rule is short and deliberately has no
 * discretion in it: multiply exactly, round once by the mode the request declared, and hand back a value
 * that carries the unrounded product beside the rounded one. Choosing the factor is the provider's
 * decision and choosing the rounding is the request's; neither is made here.
 *
 * The converter reads stored values and writes none. It holds no state and no collaborator, which is
 * what lets a domain object and an application service use the same instance of it.
 *
 * @since  2.0.0
 */
final readonly class QuantityConverter
{
    /**
     * Apply one factor to one request and produce the fully evidenced result.
     *
     * @param   UnitConversionRequest  $request  Quantity, target unit, as-at instant and declared rounding.
     * @param   UnitConversionFactor   $factor   Factor offered for that request by a conversion provider.
     *
     * @return  ConvertedQuantityValue  The expressed figure, marked as converted and carrying its whole
     *          provenance.
     *
     * @throws  InvalidArgumentException  When the factor relates another pair of units, is dated after the
     *          instant asked about, or the exact product is wider than a portable exact value can hold.
     *
     * @since   2.0.0
     */
    public function convert(UnitConversionRequest $request, UnitConversionFactor $factor): ConvertedQuantityValue
    {
        if (!$request->answeredBy($factor)) {
            throw new InvalidArgumentException(
                'A conversion factor must relate the requested units as at the instant asked about.',
            );
        }
        $unrounded = ExactDecimalArithmetic::multiply($request->quantity->amount, $factor->factor);
        $rounded = ExactDecimalArithmetic::round(
            $unrounded,
            $request->precision,
            $request->scale,
            $request->rounding,
        );

        return new ConvertedQuantityValue(
            $request->quantity,
            new QuantityValue($rounded, $request->targetUnit),
            $factor,
            $request->rounding,
            $unrounded,
        );
    }
}
