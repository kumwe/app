<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use InvalidArgumentException;

final readonly class MoneyValue
{
    public function __construct(public ExactDecimal $amount, public string $currency)
    {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('A money currency must be an uppercase ISO 4217 code.');
        }
    }

    /** @return array{amount: string, currency: string} */
    public function toArray(): array
    {
        return ['amount' => $this->amount->value(), 'currency' => $this->currency];
    }
}
