<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use InvalidArgumentException;

/**
 * An exact amount bound to the unit it is measured in.
 *
 * The pair is the whole value of a `core.quantity` field: `RecordValueCodec` splits it across the
 * `.amount` and `.unit` physical columns, rebuilds it on read, and refuses a unit that differs from
 * one pinned in the field configuration. The unit is an opaque portable identifier — nothing here
 * converts between units, so two quantities are only comparable when their units are identical.
 *
 * @since  2.0.0
 */
final readonly class QuantityValue
{
    /**
     * Bind an amount to its unit of measure.
     *
     * @param   ExactDecimal  $amount  Amount expressed in $unit, at the field's precision and scale.
     * @param   string        $unit    Unit identifier of up to 63 characters, such as `kg` or `m/s`.
     *
     * @throws  InvalidArgumentException  When the unit is empty, over-long, or uses characters outside
     *          letters, digits, dot, underscore, hyphen and slash.
     *
     * @since   2.0.0
     */
    public function __construct(public ExactDecimal $amount, public string $unit)
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,62}$/D', $unit) !== 1) {
            throw new InvalidArgumentException('A quantity unit must be a bounded portable identifier.');
        }
    }

    /**
     * Export the pair in the canonical shape used for storage, checksums and API output.
     *
     * @return  array{amount: string, unit: string}  The amount as its canonical decimal string, beside its unit.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['amount' => $this->amount->value(), 'unit' => $this->unit];
    }
}
