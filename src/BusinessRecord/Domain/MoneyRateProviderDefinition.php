<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use InvalidArgumentException;
use Kumwe\App\Extension\Contribution\ContributionDefinition;
use Kumwe\Conversion\Contract\MoneyConversionRequest;

/**
 * What a package declares before any of its code is allowed to supply an exchange rate.
 *
 * Core ships no rate table, no rate feed and no rate policy, so every rate in a Kumwe installation
 * arrives from a package that said in its signed manifest that it would supply one. The declaration is
 * what makes that inspectable before install: an operator can read which currencies a package claims to
 * price and where it sits in the resolution order, without running it. An external rate service, a
 * manually administered table, a bank feed and a contractual fixed rate all declare through this same
 * shape.
 *
 * The currency list is a closed claim, not a hint. A conversion whose source or target currency is
 * outside it is not offered to that provider at all, so a package cannot quietly widen its reach after
 * admission by changing its runtime behaviour.
 *
 * @since  2.0.0
 */
final readonly class MoneyRateProviderDefinition implements ContributionDefinition
{
    /**
     * Currencies one provider may claim, which bounds both the manifest and the resolution scan.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MAXIMUM_CURRENCIES = 64;

    /**
     * Declared ISO 4217 codes, deduplicated and sorted so two orderings declare the same thing.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $currencies;

    /**
     * Declare one rate provider, the currencies it prices, and where it sits in resolution order.
     *
     * @param   string        $providerId  Namespaced identifier inside the declaring package's namespace.
     * @param   list<string>  $currencies  Uppercase ISO 4217 codes this provider is prepared to price between.
     * @param   int           $priority    Resolution order, lowest first, between -128 and 127.
     *
     * @throws  InvalidArgumentException  When the identifier is not namespaced, the currency list is empty,
     *          over its bound or holds something other than an ISO 4217 code, or the priority is outside
     *          its range.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $providerId,
        array $currencies,
        private int $priority = 0,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $providerId) !== 1) {
            throw new InvalidArgumentException('A money rate provider identifier must be namespaced.');
        }
        if ($currencies === [] || count($currencies) > self::MAXIMUM_CURRENCIES) {
            throw new InvalidArgumentException('A money rate provider must declare between one and 64 currencies.');
        }
        foreach ($currencies as $currency) {
            if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new InvalidArgumentException('A money rate provider currency must be an ISO 4217 code.');
            }
        }
        if ($priority < -128 || $priority > 127) {
            throw new InvalidArgumentException('A money rate provider priority is outside its declared range.');
        }
        $currencies = array_values(array_unique($currencies));
        sort($currencies, SORT_STRING);
        $this->currencies = $currencies;
    }

    /**
     * The identifier this provider is registered, resolved, and attributed under.
     *
     * @return  string  Namespaced provider identity, matching the `provider` on every rate it supplies.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->providerId;
    }

    /**
     * Where this provider sits when more than one package can price the same pair.
     *
     * @return  int  Lowest first; equal priorities resolve in identifier order.
     *
     * @since   2.0.0
     */
    public function priority(): int
    {
        return $this->priority;
    }

    /**
     * Whether this declaration admits a conversion at all, before the provider itself is consulted.
     *
     * @param   MoneyConversionRequest  $request  Conversion a caller is looking for a rate for.
     *
     * @return  bool  True only when both the stored and the target currency are declared.
     *
     * @since   2.0.0
     */
    public function prices(MoneyConversionRequest $request): bool
    {
        return in_array($request->amount->currency, $this->currencies, true)
            && in_array($request->targetCurrency, $this->currencies, true);
    }

    /**
     * Serialize the declaration for the signed manifest, the runtime publication, and inventory.
     *
     * @return  array{provider_id: string, currencies: list<string>, priority: int}  Canonical declaration.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'currencies' => $this->currencies,
            'priority' => $this->priority,
        ];
    }

    /**
     * Reconstitute the declaration from validated manifest data.
     *
     * @param   array<string, mixed>  $data  Declaration as `toArray()` produced it.
     *
     * @return  self  Validated rate-provider declaration.
     *
     * @throws  InvalidArgumentException  When a member is missing, extra, or mistyped.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        $expected = ['provider_id', 'currencies', 'priority'];
        if (array_diff($expected, array_keys($data)) !== [] || array_diff(array_keys($data), $expected) !== []) {
            throw new InvalidArgumentException('A money rate provider declaration must carry exactly its members.');
        }
        $providerId = $data['provider_id'];
        $currencies = $data['currencies'];
        $priority = $data['priority'];
        if (!is_string($providerId) || !is_array($currencies) || !array_is_list($currencies) || !is_int($priority)) {
            throw new InvalidArgumentException('A money rate provider declaration member has the wrong type.');
        }
        $codes = [];
        foreach ($currencies as $currency) {
            if (!is_string($currency)) {
                throw new InvalidArgumentException('A money rate provider currency must be a string.');
            }
            $codes[] = $currency;
        }

        return new self($providerId, $codes, $priority);
    }
}
