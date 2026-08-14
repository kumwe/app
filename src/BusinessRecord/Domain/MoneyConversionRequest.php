<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * What a caller asks for when it wants a stored amount presented in another currency.
 *
 * The request is the half of the conversion contract a rate provider reads. It names the amount, the
 * currency it is to be shown in, the instant the rate must be as at, and — because rounding is a
 * declared step and never an accident — the exact shape the answer is to be rounded to. A provider
 * decides which rate answers this; it never decides what the answer looks like.
 *
 * Nothing here reaches storage. A request is built above stored exact values, is satisfied for one
 * presentation or one report row, and is discarded.
 *
 * @since  2.0.0
 */
final readonly class MoneyConversionRequest
{
    /**
     * State the amount, the target denomination, the as-at instant, and the rounding to apply.
     *
     * @param   MoneyValue         $amount          Stored amount being presented, with the currency it is held in.
     * @param   string             $targetCurrency  Uppercase ISO 4217 code the amount is to be shown in.
     * @param   DateTimeImmutable  $asAt            Instant the rate must be as at, spelled in UTC; a provider may
     *          answer with an earlier rate but never with a later one.
     * @param   int                $precision       Total digit budget the converted amount is expressed at, 1 to 65.
     * @param   int                $scale           Fractional digits the target currency keeps, 0 to $precision.
     * @param   MoneyRoundingMode  $rounding        Declared rule for the digits the target scale discards.
     *
     * @throws  InvalidArgumentException  When the target currency is not an ISO 4217 code or is the currency the
     *          amount is already held in, the instant is not UTC, or precision and scale fall outside the
     *          portable exact range.
     *
     * @since   2.0.0
     */
    public function __construct(
        public MoneyValue $amount,
        public string $targetCurrency,
        public DateTimeImmutable $asAt,
        public int $precision,
        public int $scale,
        public MoneyRoundingMode $rounding,
    ) {
        if (preg_match('/^[A-Z]{3}$/D', $targetCurrency) !== 1) {
            throw new InvalidArgumentException('A conversion target currency must be an uppercase ISO 4217 code.');
        }
        if ($targetCurrency === $amount->currency) {
            throw new InvalidArgumentException('A conversion must name a currency other than the amount\'s own.');
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
     * Whether one rate is the right shape and vintage to answer this request.
     *
     * A provider that offers a rate for the wrong pair, or one dated after the instant asked about, has
     * answered a different question. Checking it here keeps the rule in one place rather than in every
     * provider, and keeps a late rate from being presented as though it were the historical one.
     *
     * @param   MoneyExchangeRate  $rate  Rate a provider offered for this request.
     *
     * @return  bool  True when the rate prices this pair and is as at this instant or earlier.
     *
     * @since   2.0.0
     */
    public function answeredBy(MoneyExchangeRate $rate): bool
    {
        return $rate->baseCurrency === $this->amount->currency
            && $rate->quoteCurrency === $this->targetCurrency
            && $rate->asAt <= $this->asAt;
    }
}
