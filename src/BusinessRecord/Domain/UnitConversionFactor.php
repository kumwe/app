<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One conversion factor, the instant it was as at, and the provider that stands behind it.
 *
 * Core owns this shape and nothing else about unit conversion: it holds no table, reads no standard, and
 * has no opinion about how many litres are in a drum or how many bottles are in a case. What it does
 * insist on is that a factor is never an anonymous number. A factor that cannot say when it applied and
 * who published it cannot be used to convert, because the converted quantity would then be unauditable
 * the moment it left the screen it was rendered on — and unlike a currency, a packaging factor is a
 * commercial term that genuinely changes over time.
 *
 * The factor reads as units of $targetUnit per one unit of $sourceUnit, and it is carried as an
 * `ExactDecimal` so the conversion never routes through a float. Whether the provider reached it
 * directly or through a base unit is its own business; what it publishes here is the single factor it is
 * prepared to be held to.
 *
 * @since  2.0.0
 */
final readonly class UnitConversionFactor
{
    /**
     * The one spelling an as-at instant is written and read in, matching stored temporal values.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string INSTANT_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * The grammar a portable unit identifier is admitted under, matching `QuantityValue`'s own.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string UNIT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,62}$/D';

    /**
     * Bind a factor to the units it relates, the instant it applied, and the provider that supplied it.
     *
     * @param   string             $sourceUnit  Portable identifier of the unit the factor converts from.
     * @param   string             $targetUnit  Portable identifier of the unit the factor converts into.
     * @param   ExactDecimal       $factor      Units of $targetUnit per one unit of $sourceUnit, above zero.
     * @param   DateTimeImmutable  $asAt        Instant the factor was as at, spelled in UTC.
     * @param   string             $provider    Identifier of the conversion provider standing behind it.
     *
     * @throws  InvalidArgumentException  When a unit is not a bounded portable identifier, the two are the
     *          same, the factor is zero or negative or carries no fraction, the instant is not UTC, or the
     *          provider is not a namespaced identifier.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $sourceUnit,
        public string $targetUnit,
        public ExactDecimal $factor,
        public DateTimeImmutable $asAt,
        public string $provider,
    ) {
        if (
            preg_match(self::UNIT_PATTERN, $sourceUnit) !== 1
            || preg_match(self::UNIT_PATTERN, $targetUnit) !== 1
        ) {
            throw new InvalidArgumentException('A conversion factor unit must be a bounded portable identifier.');
        }
        if ($sourceUnit === $targetUnit) {
            throw new InvalidArgumentException('A conversion factor must relate two different units.');
        }
        if ($factor->scale < 1 || str_starts_with($factor->value(), '-') || trim($factor->value(), '0.') === '') {
            throw new InvalidArgumentException(
                'A conversion factor must be an exact value above zero with a fraction.',
            );
        }
        if ($asAt->format('P') !== '+00:00') {
            throw new InvalidArgumentException('A conversion factor as-at instant must be expressed in UTC.');
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $provider) !== 1) {
            throw new InvalidArgumentException('A conversion factor provider must be a namespaced identifier.');
        }
    }

    /**
     * Export the factor in the canonical shape provenance travels in.
     *
     * @return  array{
     *              source_unit: string,
     *              target_unit: string,
     *              factor: string,
     *              as_at: string,
     *              provider: string
     *          }  The two units, the exact factor literal, the UTC instant, and the provider identity.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'source_unit' => $this->sourceUnit,
            'target_unit' => $this->targetUnit,
            'factor' => $this->factor->value(),
            'as_at' => $this->asAt->format(self::INSTANT_FORMAT),
            'provider' => $this->provider,
        ];
    }

    /**
     * Rebuild a factor from the export shape, so provenance survives a round trip through a payload.
     *
     * @param   array<string, mixed>  $data  Export as `toArray()` produced it.
     *
     * @return  self  The same factor, with its literal and instant restored exactly.
     *
     * @throws  InvalidArgumentException  When a member is missing, extra, mistyped, or not canonical.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        $expected = ['source_unit', 'target_unit', 'factor', 'as_at', 'provider'];
        if (array_diff($expected, array_keys($data)) !== [] || array_diff(array_keys($data), $expected) !== []) {
            throw new InvalidArgumentException('A conversion factor export must carry exactly its declared members.');
        }
        $source = $data['source_unit'];
        $target = $data['target_unit'];
        $factor = $data['factor'];
        $asAt = $data['as_at'];
        $provider = $data['provider'];
        if (
            !is_string($source) || !is_string($target) || !is_string($factor)
            || !is_string($asAt) || !is_string($provider)
        ) {
            throw new InvalidArgumentException('A conversion factor export member has the wrong type.');
        }

        return new self(
            $source,
            $target,
            ExactDecimalArithmetic::fromLiteral($factor),
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
            throw new InvalidArgumentException('A conversion factor as-at instant is not a canonical UTC instant.');
        }

        return $instant;
    }
}
