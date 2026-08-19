<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use InvalidArgumentException;

/**
 * An amount presented in a currency it is not stored in, carrying everything needed to justify it.
 *
 * This is the non-negotiable half of the money conversion contract. A converted amount is never
 * interchangeable with a stored one: it says that it is converted, and it carries the amount and
 * currency it came from, the rate applied, the instant that rate was as at, the provider that supplied
 * the rate, the rounding rule, and the unrounded product the rounding was applied to. An operator
 * reading the figure can therefore tell, without asking anyone, whether they are looking at what was
 * agreed or at what it is worth today — and reproduce the second from the first.
 *
 * The type is the enforcement, not a convention. There is no partial shape: the constructor recomputes
 * the product from the source amount and the rate and recomputes the rounding from the declared mode,
 * so a value whose numbers do not follow from its own provenance cannot be built, let alone serialized.
 * A converted amount is also structurally unlike a stored one — `MoneyValue::toArray()`'s `amount` and
 * `currency` pair appears nowhere at this export's top level — so a write path expecting a stored money
 * value refuses a converted one rather than quietly accepting it.
 *
 * Conversion sits above storage and never enters it: `RecordValueGuard` admits no such object into a
 * record value, and `RecordValueCodec` refuses this export for a `core.money` field.
 *
 * @since  2.0.0
 */
final readonly class ConvertedMoneyValue
{
    /**
     * The canonical text form, which is also the grammar `fromPortableString()` reads back.
     *
     * @var    string
     * @since  2.0.0
     */
    private const PORTABLE_PATTERN = '/^([A-Z]{3}) (?<converted>-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?)'
        . ' converted from ([A-Z]{3}) (?<source>-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?)'
        . ' at (?<rate>(?:0|[1-9][0-9]*)\.[0-9]+)'
        . ' as at (?<as_at>[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}\+00:00)'
        . ' by (?<provider>[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15})'
        . ' rounded (?<mode>[a-z_]{1,16})'
        . ' from (?<unrounded>-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?)$/D';

    /**
     * Bind a converted figure to the whole of the evidence that produced it.
     *
     * @param   MoneyValue         $source     Stored amount the conversion read, in the currency it is held in.
     * @param   MoneyValue         $converted  Presented amount, in the currency the rate quotes.
     * @param   MoneyExchangeRate  $rate       Rate applied, its as-at instant, and the provider behind it.
     * @param   MoneyRoundingMode  $rounding   Declared rule applied to the digits the target scale discards.
     * @param   ExactDecimal       $unrounded  Exact product of the source amount and the rate, before rounding.
     *
     * @throws  InvalidArgumentException  When the rate does not price the two currencies given, the unrounded
     *          product is not the exact product of the source amount and the rate, or the converted amount is
     *          not that product rounded under the declared mode.
     *
     * @since   2.0.0
     */
    public function __construct(
        public MoneyValue $source,
        public MoneyValue $converted,
        public MoneyExchangeRate $rate,
        public MoneyRoundingMode $rounding,
        public ExactDecimal $unrounded,
    ) {
        if ($rate->baseCurrency !== $source->currency || $rate->quoteCurrency !== $converted->currency) {
            throw new InvalidArgumentException('A converted amount must carry the rate that prices its own pair.');
        }
        if (ExactDecimalArithmetic::multiply($source->amount, $rate->rate)->value() !== $unrounded->value()) {
            throw new InvalidArgumentException('A converted amount must carry the exact product of its own rate.');
        }
        $rounded = ExactDecimalArithmetic::round(
            $unrounded,
            $converted->amount->precision,
            $converted->amount->scale,
            $rounding,
        );
        if ($rounded->value() !== $converted->amount->value()) {
            throw new InvalidArgumentException('A converted amount must be its exact product under its own rounding.');
        }
    }

    /**
     * Export the figure and the whole of its provenance, in the shape every surface carries.
     *
     * The `converted` marker is present unconditionally and the presented figure sits under `value`
     * rather than at the top level, so this export can never be read as, or substituted for, a stored
     * `MoneyValue` export.
     *
     * @return  array{
     *              converted: true,
     *              value: array{amount: string, currency: string},
     *              source: array{amount: string, currency: string},
     *              rate: array{
     *                  base_currency: string,
     *                  quote_currency: string,
     *                  rate: string,
     *                  as_at: string,
     *                  provider: string
     *              },
     *              rounding: array{mode: string, scale: int, unrounded_amount: string}
     *          }  The presented figure, what it came from, the rate that made it, and the rounding applied.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'converted' => true,
            'value' => $this->converted->toArray(),
            'source' => $this->source->toArray(),
            'rate' => $this->rate->toArray(),
            'rounding' => [
                'mode' => $this->rounding->value,
                'scale' => $this->converted->amount->scale,
                'unrounded_amount' => $this->unrounded->value(),
            ],
        ];
    }

    /**
     * Rebuild a converted amount from the export shape, so a payload can be read back exactly.
     *
     * Precision is not part of the export, because a reader outside the system has no business knowing
     * a field's digit budget; each literal is restored at the narrowest precision that holds it, which
     * leaves every exported literal byte-identical on the way back out.
     *
     * @param   array<string, mixed>  $data  Export as `toArray()` produced it.
     *
     * @return  self  The same converted amount, with its provenance restored.
     *
     * @throws  InvalidArgumentException  When a member is missing, extra, mistyped, or contradicts the rest.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        $expected = ['converted', 'value', 'source', 'rate', 'rounding'];
        if (array_diff($expected, array_keys($data)) !== [] || array_diff(array_keys($data), $expected) !== []) {
            throw new InvalidArgumentException('A converted amount export must carry exactly its declared members.');
        }
        if ($data['converted'] !== true) {
            throw new InvalidArgumentException('A converted amount export must be marked as converted.');
        }
        $rate = $data['rate'];
        $rounding = $data['rounding'];
        if (!is_array($rate) || !is_array($rounding)) {
            throw new InvalidArgumentException('A converted amount export member has the wrong type.');
        }
        $expectedRounding = ['mode', 'scale', 'unrounded_amount'];
        if (
            array_diff($expectedRounding, array_keys($rounding)) !== []
            || array_diff(array_keys($rounding), $expectedRounding) !== []
        ) {
            throw new InvalidArgumentException(
                'A converted amount export rounding must carry exactly its declared members.',
            );
        }
        $mode = $rounding['mode'] ?? null;
        $scale = $rounding['scale'] ?? null;
        $unrounded = $rounding['unrounded_amount'] ?? null;
        if (!is_string($mode) || !is_int($scale) || !is_string($unrounded)) {
            throw new InvalidArgumentException('A converted amount export rounding member has the wrong type.');
        }
        $converted = self::money($data['value']);
        if ($scale !== $converted->amount->scale) {
            throw new InvalidArgumentException(
                'A converted amount export rounding scale must match the converted amount.',
            );
        }

        return new self(
            self::money($data['source']),
            $converted,
            MoneyExchangeRate::fromArray(self::document($rate)),
            MoneyRoundingMode::tryFrom($mode)
                ?? throw new InvalidArgumentException('A converted amount export names an unknown rounding mode.'),
            ExactDecimalArithmetic::fromLiteral($unrounded),
        );
    }

    /**
     * Spell the figure and its provenance as one self-describing line of text.
     *
     * A report cell and an export column carry a scalar, not a structure, so this is the form provenance
     * travels in once a figure leaves the system in an artifact somebody keeps. It is deliberately a
     * sentence rather than a code: the recipient of a downloaded export can read it without the system
     * that produced it, and `fromPortableString()` can read it back.
     *
     * @return  string  For example `EUR 1234.56 converted from ZAR 25000.00 at 0.049382 as at
     *          2026-08-14T00:00:00.000000+00:00 by acme.rates.ecb rounded half_up from 1234.560000`.
     *
     * @since   2.0.0
     */
    public function toPortableString(): string
    {
        return sprintf(
            '%s %s converted from %s %s at %s as at %s by %s rounded %s from %s',
            $this->converted->currency,
            $this->converted->amount->value(),
            $this->source->currency,
            $this->source->amount->value(),
            $this->rate->rate->value(),
            $this->rate->asAt->format(MoneyExchangeRate::INSTANT_FORMAT),
            $this->rate->provider,
            $this->rounding->value,
            $this->unrounded->value(),
        );
    }

    /**
     * Read a converted amount back from its self-describing text form.
     *
     * @param   string  $value  Text as `toPortableString()` wrote it.
     *
     * @return  self  The same converted amount, with every element of its provenance restored.
     *
     * @throws  InvalidArgumentException  When the text is not that exact grammar, or its numbers contradict
     *          each other.
     *
     * @since   2.0.0
     */
    public static function fromPortableString(string $value): self
    {
        $matches = [];
        if (preg_match(self::PORTABLE_PATTERN, $value, $matches) !== 1) {
            throw new InvalidArgumentException('A converted amount must be spelled in the portable form.');
        }

        return new self(
            new MoneyValue(ExactDecimalArithmetic::fromLiteral($matches['source']), $matches[3]),
            new MoneyValue(ExactDecimalArithmetic::fromLiteral($matches['converted']), $matches[1]),
            new MoneyExchangeRate(
                $matches[3],
                $matches[1],
                ExactDecimalArithmetic::fromLiteral($matches['rate']),
                MoneyExchangeRate::instant($matches['as_at']),
                $matches['provider'],
            ),
            MoneyRoundingMode::tryFrom($matches['mode'])
                ?? throw new InvalidArgumentException('A converted amount names an unknown rounding mode.'),
            ExactDecimalArithmetic::fromLiteral($matches['unrounded']),
        );
    }

    /**
     * Whether one text value is a converted amount rather than a bare figure.
     *
     * Surfaces that only need to tell the two apart — a report column type, a column renderer — use this
     * instead of parsing, so the answer comes from the one grammar rather than from a guess.
     *
     * @param   string  $value  Candidate cell text.
     *
     * @return  bool  True only when the text is the complete portable form, provenance included.
     *
     * @since   2.0.0
     */
    public static function isPortableString(string $value): bool
    {
        return preg_match(self::PORTABLE_PATTERN, $value) === 1;
    }

    /**
     * Recognise a converted amount in an already-decoded value, whichever of its two forms it arrived in.
     *
     * Every surface that renders a value asks this one question rather than testing for the object, the
     * export, or a `converted` key by itself. That is what makes the rendering rule enforceable: a
     * presenter, a projector or a report column cannot accidentally treat a converted amount as an
     * ordinary figure, because the recognition and the provenance come from the same place. The declared
     * member set has to match exactly, so an unrelated structured value that happens to carry a
     * `converted` flag is not mistaken for one.
     *
     * @param   mixed  $value  Disclosed value being prepared for a surface.
     *
     * @return  ?self  The converted amount, or null when the value is not one.
     *
     * @throws  InvalidArgumentException  When the value carries exactly the declared member set but those
     *          members are missing, mistyped, or contradict each other — a figure that says it is
     *          converted and cannot prove it is refused rather than rendered bare.
     *
     * @since   2.0.0
     */
    public static function detect(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }
        $expected = ['converted', 'value', 'source', 'rate', 'rounding'];
        $keys = array_keys($value);
        if (array_diff($expected, $keys) !== [] || array_diff($keys, $expected) !== []) {
            return null;
        }

        return self::fromArray(self::document($value));
    }

    /**
     * Prove one nested export member really is a keyed document before it is read as one.
     *
     * A decoded payload arrives with whatever keys it was given, so the string-keyed shape the nested
     * readers expect is established here rather than assumed by them.
     *
     * @param   array<mixed, mixed>  $data  Nested member of a decoded export.
     *
     * @return  array<string, mixed>  The same members, proved to be string-keyed.
     *
     * @throws  InvalidArgumentException  When any key is not a string.
     *
     * @since   2.0.0
     */
    private static function document(array $data): array
    {
        $document = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('A converted amount export member must be a keyed document.');
            }
            $document[$key] = $value;
        }

        return $document;
    }

    /**
     * Read one exported amount-and-currency pair back into a stored money value.
     *
     * @param   mixed  $data  Candidate `array{amount: string, currency: string}` member of an export.
     *
     * @return  MoneyValue  The pair, with its amount restored at its own minimal precision.
     *
     * @throws  InvalidArgumentException  When the member is not exactly that pair.
     *
     * @since   2.0.0
     */
    private static function money(mixed $data): MoneyValue
    {
        if (
            !is_array($data)
            || array_diff(['amount', 'currency'], array_keys($data)) !== []
            || array_diff(array_keys($data), ['amount', 'currency']) !== []
            || !is_string($data['amount'])
            || !is_string($data['currency'])
        ) {
            throw new InvalidArgumentException('A converted amount export requires exact amount and currency pairs.');
        }

        return new MoneyValue(ExactDecimalArithmetic::fromLiteral($data['amount']), $data['currency']);
    }
}
