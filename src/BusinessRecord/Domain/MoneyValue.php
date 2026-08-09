<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use InvalidArgumentException;

/**
 * An exact amount bound to the ISO 4217 currency it is denominated in.
 *
 * The pair is the whole value of a `core.money` field: `RecordValueCodec` splits it across the
 * `.amount` and `.currency` physical columns, rebuilds it on read, and refuses a currency that
 * differs from one pinned in the field configuration. Carrying the amount as an `ExactDecimal` keeps
 * money out of float arithmetic, and pairing it with the currency here stops an amount from being
 * moved or compared without the denomination that gives it meaning.
 *
 * @since  2.0.0
 */
final readonly class MoneyValue
{
    /**
     * Bind an amount to its currency.
     *
     * @param   ExactDecimal  $amount    Amount in the currency's own units, at the field's precision and scale.
     * @param   string        $currency  Uppercase ISO 4217 alphabetic code, such as `ZAR`.
     *
     * @throws  InvalidArgumentException  When the currency is not exactly three uppercase letters.
     *
     * @since   2.0.0
     */
    public function __construct(public ExactDecimal $amount, public string $currency)
    {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('A money currency must be an uppercase ISO 4217 code.');
        }
    }

    /**
     * Export the pair in the canonical shape used for storage, checksums and API output.
     *
     * @return  array{amount: string, currency: string}  The amount as its canonical decimal string, beside its code.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['amount' => $this->amount->value(), 'currency' => $this->currency];
    }
}
