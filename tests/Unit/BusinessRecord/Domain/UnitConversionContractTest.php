<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessRecord\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Domain\ConvertedQuantityValue;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimalArithmetic;
use Kumwe\CMS\BusinessRecord\Domain\QuantityConverter;
use Kumwe\CMS\BusinessRecord\Domain\QuantityRoundingMode;
use Kumwe\CMS\BusinessRecord\Domain\QuantityValue;
use Kumwe\CMS\BusinessRecord\Domain\UnitConversionFactor;
use Kumwe\CMS\BusinessRecord\Domain\UnitConversionRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConvertedQuantityValue::class)]
#[CoversClass(ExactDecimalArithmetic::class)]
#[CoversClass(QuantityConverter::class)]
#[CoversClass(QuantityRoundingMode::class)]
#[CoversClass(UnitConversionFactor::class)]
#[CoversClass(UnitConversionRequest::class)]
/**
 * Pins the unit conversion contract: exact arithmetic, declared rounding, and mandatory provenance.
 *
 * This is the named construction-and-serialization check the unit-of-measure half of decision D13.5
 * is carried by: the converted type is unconstructible without its provenance, and the encoder cannot
 * drop it.
 *
 * @since  2.0.0
 */
final class UnitConversionContractTest extends TestCase
{
    /**
     * Prove a conversion multiplies exactly and never routes the product through a float.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConversionMultipliesExactlyAndKeepsTheUnroundedProduct(): void
    {
        $converted = (new QuantityConverter())->convert(
            new UnitConversionRequest(
                new QuantityValue(ExactDecimal::fromString('25.0000', 12, 4), 'lb'),
                'kg',
                self::instant('2026-08-14T00:00:00'),
                12,
                3,
                QuantityRoundingMode::HalfUp,
            ),
            self::factor('0.45359237'),
        );

        self::assertSame('11.339809250000', $converted->unrounded->value());
        self::assertSame('11.340', $converted->converted->amount->value());
        self::assertSame('kg', $converted->converted->unit);
        self::assertSame('lb', $converted->source->unit);
        self::assertSame(
            '11.339809250000',
            ExactDecimalArithmetic::multiply(
                $converted->source->amount,
                $converted->factor->factor,
            )->value(),
        );
    }

    /**
     * Prove each declared rounding mode decides the discarded digits, and that none of them is implicit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryDeclaredRoundingModeProducesItsOwnAnswer(): void
    {
        $expected = [
            'half_up' => ['1.24', '-1.24'],
            'half_down' => ['1.23', '-1.23'],
            'half_even' => ['1.24', '-1.24'],
            'ceiling' => ['1.24', '-1.23'],
            'floor' => ['1.23', '-1.24'],
            'truncate' => ['1.23', '-1.23'],
        ];

        foreach (QuantityRoundingMode::cases() as $mode) {
            $positive = ExactDecimalArithmetic::round(
                ExactDecimal::fromString('1.2350', 8, 4),
                8,
                2,
                $mode,
            );
            $negative = ExactDecimalArithmetic::round(
                ExactDecimal::fromString('-1.2350', 8, 4),
                8,
                2,
                $mode,
            );
            self::assertSame($expected[$mode->value][0], $positive->value(), $mode->value);
            self::assertSame($expected[$mode->value][1], $negative->value(), $mode->value);
        }

        self::assertSame(
            '1.22',
            ExactDecimalArithmetic::round(
                ExactDecimal::fromString('1.2250', 8, 4),
                8,
                2,
                QuantityRoundingMode::HalfEven,
            )->value(),
        );
    }

    /**
     * Prove a converted quantity cannot exist without the factor, instant and provider that justify it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnIncompleteOrContradictoryConvertedQuantityCannotBeConstructed(): void
    {
        $source = new QuantityValue(ExactDecimal::fromString('25.0000', 12, 4), 'lb');
        $factor = self::factor('0.45359237');
        $unrounded = ExactDecimalArithmetic::multiply($source->amount, $factor->factor);

        $this->refuses(
            static fn (): ConvertedQuantityValue => new ConvertedQuantityValue(
                $source,
                new QuantityValue(ExactDecimal::fromString('9999.999', 12, 3), 'kg'),
                $factor,
                QuantityRoundingMode::HalfUp,
                $unrounded,
            ),
            'under its own rounding',
        );
        $this->refuses(
            static fn (): ConvertedQuantityValue => new ConvertedQuantityValue(
                $source,
                new QuantityValue(ExactDecimal::fromString('11.340', 12, 3), 'kg'),
                $factor,
                QuantityRoundingMode::HalfUp,
                ExactDecimal::fromString('1.000000000000', 20, 12),
            ),
            'the exact product',
        );
        $this->refuses(
            static fn (): ConvertedQuantityValue => new ConvertedQuantityValue(
                $source,
                new QuantityValue(ExactDecimal::fromString('11.340', 12, 3), 'g'),
                $factor,
                QuantityRoundingMode::HalfUp,
                $unrounded,
            ),
            'relating its own units',
        );
        $this->refuses(
            static fn (): UnitConversionFactor => new UnitConversionFactor(
                'lb',
                'kg',
                ExactDecimal::fromString('0.00000000', 12, 8),
                self::instant('2026-08-14T00:00:00'),
                'acme.units.trade',
            ),
            'above zero',
        );
        $this->refuses(
            static fn (): UnitConversionFactor => new UnitConversionFactor(
                'lb',
                'kg',
                ExactDecimal::fromString('0.45359237', 12, 8),
                new DateTimeImmutable('2026-08-14T02:00:00', new DateTimeZone('Africa/Windhoek')),
                'acme.units.trade',
            ),
            'expressed in UTC',
        );
        $this->refuses(
            static fn (): UnitConversionFactor => new UnitConversionFactor(
                'lb',
                'kg',
                ExactDecimal::fromString('0.45359237', 12, 8),
                self::instant('2026-08-14T00:00:00'),
                'anonymous',
            ),
            'namespaced identifier',
        );
    }

    /**
     * Prove a factor dated after the instant asked about cannot be presented as the historical one.
     *
     * A case size is a commercial term that genuinely changes, so this is not a formality: last week's
     * pack of twelve must not answer a document raised when the pack held ten.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFactorFromAfterTheInstantAskedAboutIsRefused(): void
    {
        $request = new UnitConversionRequest(
            new QuantityValue(ExactDecimal::fromString('25.0000', 12, 4), 'lb'),
            'kg',
            self::instant('2026-08-14T00:00:00'),
            12,
            3,
            QuantityRoundingMode::HalfUp,
        );

        self::assertFalse($request->answeredBy(self::factor('0.45359237', '2026-08-15T00:00:00')));
        self::assertFalse($request->answeredBy(new UnitConversionFactor(
            'lb',
            'g',
            ExactDecimal::fromString('453.59237000', 14, 8),
            self::instant('2026-08-13T00:00:00'),
            'acme.units.trade',
        )));
        $this->refuses(
            static fn (): ConvertedQuantityValue => (new QuantityConverter())->convert(
                $request,
                self::factor('0.45359237', '2026-08-15T00:00:00'),
            ),
            'as at the instant asked about',
        );
    }

    /**
     * Prove a request refuses the degenerate conversions that would produce an unmarked figure.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConversionRequestRefusesAUnitItWouldNotChange(): void
    {
        $this->refuses(
            static fn (): UnitConversionRequest => new UnitConversionRequest(
                new QuantityValue(ExactDecimal::fromString('1.000', 12, 3), 'kg'),
                'kg',
                self::instant('2026-08-14T00:00:00'),
                12,
                3,
                QuantityRoundingMode::HalfUp,
            ),
            'other than the quantity\'s own',
        );
        $this->refuses(
            static fn (): UnitConversionRequest => new UnitConversionRequest(
                new QuantityValue(ExactDecimal::fromString('1.000', 12, 3), 'kg'),
                'metric tonne',
                self::instant('2026-08-14T00:00:00'),
                12,
                3,
                QuantityRoundingMode::HalfUp,
            ),
            'bounded portable identifier',
        );
    }

    /**
     * Prove a converted quantity can never be read as, or substituted for, a stored quantity value.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConvertedQuantityIsStructurallyUnlikeAStoredOne(): void
    {
        $converted = self::converted();
        $stored = new QuantityValue(ExactDecimal::fromString('25.0000', 12, 4), 'lb');

        self::assertSame(['amount', 'unit'], array_keys($stored->toArray()));
        self::assertSame(
            [],
            array_intersect(array_keys($converted->toArray()), array_keys($stored->toArray())),
        );
        self::assertTrue($converted->toArray()['converted']);
        self::assertSame('acme.units.trade', $converted->toArray()['factor']['provider']);
        self::assertSame('2026-08-14T00:00:00.000000+00:00', $converted->toArray()['factor']['as_at']);
        self::assertSame('half_up', $converted->toArray()['rounding']['mode']);
        self::assertSame('11.339809250000', $converted->toArray()['rounding']['unrounded_amount']);
    }

    /**
     * Prove exported provenance round-trips, in both the payload shape and the artifact text form.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExportedProvenanceRoundTrips(): void
    {
        $converted = self::converted();

        self::assertSame(
            $converted->toArray(),
            ConvertedQuantityValue::fromArray($converted->toArray())->toArray(),
        );
        self::assertSame(
            '11.340 kg converted from 25.0000 lb at 0.45359237'
                . ' as at 2026-08-14T00:00:00.000000+00:00 by acme.units.trade rounded half_up'
                . ' from 11.339809250000',
            $converted->toPortableString(),
        );
        self::assertSame(
            $converted->toArray(),
            ConvertedQuantityValue::fromPortableString($converted->toPortableString())->toArray(),
        );
        self::assertTrue(ConvertedQuantityValue::isPortableString($converted->toPortableString()));
        self::assertFalse(ConvertedQuantityValue::isPortableString('11.340'));
        self::assertFalse(ConvertedQuantityValue::isPortableString('11.340 kg'));
        self::assertFalse(ConvertedQuantityValue::isPortableString(
            'EUR 1234.56 converted from ZAR 25000.00 at 0.04938240'
                . ' as at 2026-08-14T00:00:00.000000+00:00 by acme.rates.ecb rounded half_up from 1234.5600000000',
        ));

        $this->refuses(
            static fn (): ConvertedQuantityValue => ConvertedQuantityValue::fromArray(
                ['value' => ['amount' => '11.340', 'unit' => 'kg']],
            ),
            'exactly its declared members',
        );
        $incomplete = $converted->toArray();
        $incomplete['converted'] = false;
        $this->refuses(
            static fn (): ConvertedQuantityValue => ConvertedQuantityValue::fromArray($incomplete),
            'marked as converted',
        );
    }

    /**
     * Build the converted quantity every provenance assertion in this class is made against.
     *
     * @return  ConvertedQuantityValue  25.0000 lb expressed in kg at a factor from a named provider.
     *
     * @since   2.0.0
     */
    private static function converted(): ConvertedQuantityValue
    {
        return (new QuantityConverter())->convert(
            new UnitConversionRequest(
                new QuantityValue(ExactDecimal::fromString('25.0000', 12, 4), 'lb'),
                'kg',
                self::instant('2026-08-14T00:00:00'),
                12,
                3,
                QuantityRoundingMode::HalfUp,
            ),
            self::factor('0.45359237'),
        );
    }

    /**
     * Build one factor for the fixture pair, attributed to the test conversion package.
     *
     * @param   string  $factor  Canonical factor literal, kilograms per pound.
     * @param   string  $asAt    Naive UTC instant the factor is as at.
     *
     * @return  UnitConversionFactor  The factor, in UTC, attributed to `acme.units.trade`.
     *
     * @since   2.0.0
     */
    private static function factor(string $factor, string $asAt = '2026-08-14T00:00:00'): UnitConversionFactor
    {
        return new UnitConversionFactor(
            'lb',
            'kg',
            ExactDecimalArithmetic::fromLiteral($factor),
            self::instant($asAt),
            'acme.units.trade',
        );
    }

    /**
     * Read one naive instant as UTC, which is the only spelling the contract admits.
     *
     * @param   string  $value  Instant without an offset.
     *
     * @return  DateTimeImmutable  The same instant, in UTC.
     *
     * @since   2.0.0
     */
    private static function instant(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /**
     * Require one construction to be refused, and its reason to name the rule it broke.
     *
     * @param   callable(): object  $construction  Construction expected to fail.
     * @param   string              $reason        Fragment the refusal message must contain.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function refuses(callable $construction, string $reason): void
    {
        try {
            $construction();
            self::fail(sprintf('A value violating "%s" was constructed.', $reason));
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
        }
    }
}
