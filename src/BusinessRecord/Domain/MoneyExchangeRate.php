<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One rate, the instant it was as at, and the provider that stands behind it.
 *
 * Core owns this shape and nothing else about rates: it holds no table, reads no feed, and has no
 * opinion about which rate is correct. What it does insist on is that a rate is never an anonymous
 * number. A rate that cannot say when it applied and who supplied it cannot be used to convert, because
 * the converted amount would then be unauditable the moment it left the screen it was rendered on.
 *
 * The rate reads as units of $quoteCurrency per one unit of $baseCurrency, and it is carried as an
 * `ExactDecimal` so the conversion never routes through a float. Whether the provider reached this rate
 * directly or through a base currency is its own business; what it publishes here is the single rate it
 * is prepared to be held to.
 *
 * @since  2.0.0
 */
final readonly class MoneyExchangeRate
{
    /**
     * The one spelling an as-at instant is written and read in, matching stored temporal values.
     *
     * @var    string
     * @since  2.0.0
     */
    public const INSTANT_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * Bind a rate to the pair it prices, the instant it applied, and the provider that supplied it.
     *
     * @param   string             $baseCurrency   Uppercase ISO 4217 code the rate converts from.
     * @param   string             $quoteCurrency  Uppercase ISO 4217 code the rate converts into.
     * @param   ExactDecimal       $rate           Units of $quoteCurrency per one unit of $baseCurrency, above zero.
     * @param   DateTimeImmutable  $asAt           Instant the rate was as at, spelled in UTC.
     * @param   string             $provider       Identifier of the rate provider standing behind this rate.
     *
     * @throws  InvalidArgumentException  When a currency is not an ISO 4217 code, the two are the same, the
     *          rate is zero or negative or carries no fraction, the instant is not UTC, or the provider is
     *          not a namespaced identifier.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $baseCurrency,
        public string $quoteCurrency,
        public ExactDecimal $rate,
        public DateTimeImmutable $asAt,
        public string $provider,
    ) {
        if (
            preg_match('/^[A-Z]{3}$/D', $baseCurrency) !== 1
            || preg_match('/^[A-Z]{3}$/D', $quoteCurrency) !== 1
        ) {
            throw new InvalidArgumentException('An exchange rate currency must be an uppercase ISO 4217 code.');
        }
        if ($baseCurrency === $quoteCurrency) {
            throw new InvalidArgumentException('An exchange rate must price two different currencies.');
        }
        if ($rate->scale < 1 || str_starts_with($rate->value(), '-') || trim($rate->value(), '0.') === '') {
            throw new InvalidArgumentException('An exchange rate must be an exact value above zero with a fraction.');
        }
        if ($asAt->format('P') !== '+00:00') {
            throw new InvalidArgumentException('An exchange rate as-at instant must be expressed in UTC.');
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $provider) !== 1) {
            throw new InvalidArgumentException('An exchange rate provider must be a namespaced identifier.');
        }
    }

    /**
     * Export the rate in the canonical shape provenance travels in.
     *
     * @return  array{
     *              base_currency: string,
     *              quote_currency: string,
     *              rate: string,
     *              as_at: string,
     *              provider: string
     *          }  The pair, the exact rate literal, the UTC instant, and the provider identity.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'base_currency' => $this->baseCurrency,
            'quote_currency' => $this->quoteCurrency,
            'rate' => $this->rate->value(),
            'as_at' => $this->asAt->format(self::INSTANT_FORMAT),
            'provider' => $this->provider,
        ];
    }

    /**
     * Rebuild a rate from the export shape, so provenance survives a round trip through a payload.
     *
     * @param   array<string, mixed>  $data  Export as `toArray()` produced it.
     *
     * @return  self  The same rate, with its literal and instant restored exactly.
     *
     * @throws  InvalidArgumentException  When a member is missing, extra, mistyped, or not canonical.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        $expected = ['base_currency', 'quote_currency', 'rate', 'as_at', 'provider'];
        if (array_diff($expected, array_keys($data)) !== [] || array_diff(array_keys($data), $expected) !== []) {
            throw new InvalidArgumentException('An exchange rate export must carry exactly its declared members.');
        }
        $base = $data['base_currency'];
        $quote = $data['quote_currency'];
        $rate = $data['rate'];
        $asAt = $data['as_at'];
        $provider = $data['provider'];
        if (
            !is_string($base) || !is_string($quote) || !is_string($rate)
            || !is_string($asAt) || !is_string($provider)
        ) {
            throw new InvalidArgumentException('An exchange rate export member has the wrong type.');
        }

        return new self(
            $base,
            $quote,
            ExactDecimalArithmetic::fromLiteral($rate),
            self::instant($asAt),
            $provider,
        );
    }

    /**
     * Read a UTC instant back from the one spelling this contract writes.
     *
     * @param   string  $value  Instant as `toArray()` spelled it.
     *
     * @return  DateTimeImmutable  The same instant, in UTC.
     *
     * @throws  InvalidArgumentException  When the text is not that exact spelling.
     *
     * @since   2.0.0
     */
    public static function instant(string $value): DateTimeImmutable
    {
        $instant = DateTimeImmutable::createFromFormat(self::INSTANT_FORMAT, $value);
        if ($instant === false || $instant->format(self::INSTANT_FORMAT) !== $value) {
            throw new InvalidArgumentException('An exchange rate as-at instant is not a canonical UTC instant.');
        }

        return $instant;
    }
}
