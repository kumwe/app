<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use InvalidArgumentException;

final readonly class QuantityValue
{
    public function __construct(public ExactDecimal $amount, public string $unit)
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,62}$/D', $unit) !== 1) {
            throw new InvalidArgumentException('A quantity unit must be a bounded portable identifier.');
        }
    }

    /** @return array{amount: string, unit: string} */
    public function toArray(): array
    {
        return ['amount' => $this->amount->value(), 'unit' => $this->unit];
    }
}
