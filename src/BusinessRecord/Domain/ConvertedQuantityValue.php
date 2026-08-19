<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use InvalidArgumentException;

/**
 * A quantity expressed in a unit it is not stored in, carrying everything needed to justify it.
 *
 * This is the non-negotiable half of the unit conversion contract, and it is the half that stops a stock
 * extension and a sales extension disagreeing about what a case of a product is. A converted quantity is
 * never interchangeable with a stored one: it says that it is converted, and it carries the quantity and
 * unit it came from, the factor applied, the instant that factor was as at, the provider that supplied
 * it, the rounding rule, and the unrounded product the rounding was applied to. An operator reading the
 * figure can therefore tell, without asking anyone, whether they are looking at what was counted or at
 * what it works out to in another unit — and reproduce the second from the first.
 *
 * The type is the enforcement, not a convention. There is no partial shape: the constructor recomputes
 * the product from the source quantity and the factor and recomputes the rounding from the declared
 * mode, so a value whose numbers do not follow from its own provenance cannot be built, let alone
 * serialized. A converted quantity is also structurally unlike a stored one — `QuantityValue::toArray()`'s
 * `amount` and `unit` pair appears nowhere at this export's top level — so a write path expecting a
 * stored quantity refuses a converted one rather than quietly accepting it.
 *
 * Conversion sits above storage and never enters it: `RecordValueGuard` admits no such object into a
 * record value, and `RecordValueCodec` refuses this export for a `core.quantity` field.
 *
 * @since  2.0.0
 */
final readonly class ConvertedQuantityValue
{
    /**
     * The canonical text form, which is also the grammar `fromPortableString()` reads back.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PORTABLE_PATTERN = '/^(?<converted>-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?)'
        . ' (?<target>[A-Za-z0-9][A-Za-z0-9._\/-]{0,62})'
        . ' converted from (?<source>-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?)'
        . ' (?<origin>[A-Za-z0-9][A-Za-z0-9._\/-]{0,62})'
        . ' at (?<factor>(?:0|[1-9][0-9]*)\.[0-9]+)'
        . ' as at (?<as_at>[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}\+00:00)'
        . ' by (?<provider>[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15})'
        . ' rounded (?<mode>[a-z_]{1,16})'
        . ' from (?<unrounded>-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?)$/D';

    /**
     * Bind a converted quantity to the whole of the evidence that produced it.
     *
     * @param   QuantityValue         $source     Stored quantity the conversion read, in the unit it is held in.
     * @param   QuantityValue         $converted  Expressed quantity, in the unit the factor targets.
     * @param   UnitConversionFactor  $factor     Factor applied, its as-at instant, and the provider behind it.
     * @param   QuantityRoundingMode  $rounding   Declared rule applied to the digits the target scale discards.
     * @param   ExactDecimal          $unrounded  Exact product of the source quantity and the factor, before
     *          rounding.
     *
     * @throws  InvalidArgumentException  When the factor does not relate the two units given, the unrounded
     *          product is not the exact product of the source quantity and the factor, or the converted
     *          quantity is not that product rounded under the declared mode.
     *
     * @since   2.0.0
     */
    public function __construct(
        public QuantityValue $source,
        public QuantityValue $converted,
        public UnitConversionFactor $factor,
        public QuantityRoundingMode $rounding,
        public ExactDecimal $unrounded,
    ) {
        if ($factor->sourceUnit !== $source->unit || $factor->targetUnit !== $converted->unit) {
            throw new InvalidArgumentException('A converted quantity must carry the factor relating its own units.');
        }
        if (ExactDecimalArithmetic::multiply($source->amount, $factor->factor)->value() !== $unrounded->value()) {
            throw new InvalidArgumentException('A converted quantity must carry the exact product of its own factor.');
        }
        $rounded = ExactDecimalArithmetic::round(
            $unrounded,
            $converted->amount->precision,
            $converted->amount->scale,
            $rounding,
        );
        if ($rounded->value() !== $converted->amount->value()) {
            throw new InvalidArgumentException(
                'A converted quantity must be its exact product under its own rounding.',
            );
        }
    }

    /**
     * Export the figure and the whole of its provenance, in the shape every surface carries.
     *
     * The `converted` marker is present unconditionally and the expressed figure sits under `value`
     * rather than at the top level, so this export can never be read as, or substituted for, a stored
     * `QuantityValue` export.
     *
     * @return  array{
     *              converted: true,
     *              value: array{amount: string, unit: string},
     *              source: array{amount: string, unit: string},
     *              factor: array{
     *                  source_unit: string,
     *                  target_unit: string,
     *                  factor: string,
     *                  as_at: string,
     *                  provider: string
     *              },
     *              rounding: array{mode: string, scale: int, unrounded_amount: string}
     *          }  The expressed figure, what it came from, the factor that made it, and the rounding applied.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'converted' => true,
            'value' => $this->converted->toArray(),
            'source' => $this->source->toArray(),
            'factor' => $this->factor->toArray(),
            'rounding' => [
                'mode' => $this->rounding->value,
                'scale' => $this->converted->amount->scale,
                'unrounded_amount' => $this->unrounded->value(),
            ],
        ];
    }

    /**
     * Rebuild a converted quantity from the export shape, so a payload can be read back exactly.
     *
     * Precision is not part of the export, because a reader outside the system has no business knowing
     * a field's digit budget; each literal is restored at the narrowest precision that holds it, which
     * leaves every exported literal byte-identical on the way back out.
     *
     * @param   array<string, mixed>  $data  Export as `toArray()` produced it.
     *
     * @return  self  The same converted quantity, with its provenance restored.
     *
     * @throws  InvalidArgumentException  When a member is missing, extra, mistyped, or contradicts the rest.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        $expected = ['converted', 'value', 'source', 'factor', 'rounding'];
        if (array_diff($expected, array_keys($data)) !== [] || array_diff(array_keys($data), $expected) !== []) {
            throw new InvalidArgumentException(
                'A converted quantity export must carry exactly its declared members.',
            );
        }
        if ($data['converted'] !== true) {
            throw new InvalidArgumentException('A converted quantity export must be marked as converted.');
        }
        $factor = $data['factor'];
        $rounding = $data['rounding'];
        if (!is_array($factor) || !is_array($rounding)) {
            throw new InvalidArgumentException('A converted quantity export member has the wrong type.');
        }
        $expectedRounding = ['mode', 'scale', 'unrounded_amount'];
        if (
            array_diff($expectedRounding, array_keys($rounding)) !== []
            || array_diff(array_keys($rounding), $expectedRounding) !== []
        ) {
            throw new InvalidArgumentException(
                'A converted quantity export rounding must carry exactly its declared members.',
            );
        }
        $mode = $rounding['mode'] ?? null;
        $scale = $rounding['scale'] ?? null;
        $unrounded = $rounding['unrounded_amount'] ?? null;
        if (!is_string($mode) || !is_int($scale) || !is_string($unrounded)) {
            throw new InvalidArgumentException('A converted quantity export rounding member has the wrong type.');
        }
        $converted = self::quantity($data['value']);
        if ($scale !== $converted->amount->scale) {
            throw new InvalidArgumentException(
                'A converted quantity export rounding scale must match the converted amount.',
            );
        }

        return new self(
            self::quantity($data['source']),
            $converted,
            UnitConversionFactor::fromArray(self::document($factor)),
            QuantityRoundingMode::tryFrom($mode)
                ?? throw new InvalidArgumentException('A converted quantity export names an unknown rounding mode.'),
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
     * @return  string  For example `11.340 kg converted from 25.0000 lb at 0.45359237 as at
     *          2026-08-14T00:00:00.000000+00:00 by acme.units.trade rounded half_up from 11.339809250`.
     *
     * @since   2.0.0
     */
    public function toPortableString(): string
    {
        return sprintf(
            '%s %s converted from %s %s at %s as at %s by %s rounded %s from %s',
            $this->converted->amount->value(),
            $this->converted->unit,
            $this->source->amount->value(),
            $this->source->unit,
            $this->factor->factor->value(),
            $this->factor->asAt->format(UnitConversionFactor::INSTANT_FORMAT),
            $this->factor->provider,
            $this->rounding->value,
            $this->unrounded->value(),
        );
    }

    /**
     * Read a converted quantity back from its self-describing text form.
     *
     * @param   string  $value  Text as `toPortableString()` wrote it.
     *
     * @return  self  The same converted quantity, with every element of its provenance restored.
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
            throw new InvalidArgumentException('A converted quantity must be spelled in the portable form.');
        }

        return new self(
            new QuantityValue(ExactDecimalArithmetic::fromLiteral($matches['source']), $matches['origin']),
            new QuantityValue(ExactDecimalArithmetic::fromLiteral($matches['converted']), $matches['target']),
            new UnitConversionFactor(
                $matches['origin'],
                $matches['target'],
                ExactDecimalArithmetic::fromLiteral($matches['factor']),
                UnitConversionFactor::instant($matches['as_at']),
                $matches['provider'],
            ),
            QuantityRoundingMode::tryFrom($matches['mode'])
                ?? throw new InvalidArgumentException('A converted quantity names an unknown rounding mode.'),
            ExactDecimalArithmetic::fromLiteral($matches['unrounded']),
        );
    }

    /**
     * Whether one text value is a converted quantity rather than a bare figure.
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
                throw new InvalidArgumentException('A converted quantity export member must be a keyed document.');
            }
            $document[$key] = $value;
        }

        return $document;
    }

    /**
     * Read one exported amount-and-unit pair back into a stored quantity value.
     *
     * @param   mixed  $data  Candidate `array{amount: string, unit: string}` member of an export.
     *
     * @return  QuantityValue  The pair, with its amount restored at its own minimal precision.
     *
     * @throws  InvalidArgumentException  When the member is not exactly that pair.
     *
     * @since   2.0.0
     */
    private static function quantity(mixed $data): QuantityValue
    {
        if (
            !is_array($data)
            || array_diff(['amount', 'unit'], array_keys($data)) !== []
            || array_diff(array_keys($data), ['amount', 'unit']) !== []
            || !is_string($data['amount'])
            || !is_string($data['unit'])
        ) {
            throw new InvalidArgumentException('A converted quantity export requires exact amount and unit pairs.');
        }

        return new QuantityValue(ExactDecimalArithmetic::fromLiteral($data['amount']), $data['unit']);
    }
}
