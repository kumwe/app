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

/**
 * Proves provenance is refused when it is incomplete, rather than restored with the gaps guessed at.
 *
 * A converted quantity is only worth anything because it carries what produced it, so the readers that
 * bring one back from a payload or a report cell are the place that guarantee is most easily lost: a
 * missing member, a number where text belongs, or an export whose factor is a bare list would each
 * yield a figure nobody can reproduce. Every one of those is refused here by name, in both the export
 * shape and the self-describing text form, and so are the malformed factors and requests a conversion
 * would otherwise be built on.
 *
 * These are the refusals the contract is made of; the accompanying `UnitConversionContractTest` pins
 * what happens when everything is in order.
 *
 * @since  2.0.0
 */
#[CoversClass(ConvertedQuantityValue::class)]
#[CoversClass(UnitConversionFactor::class)]
#[CoversClass(UnitConversionRequest::class)]
final class UnitConversionRefusalTest extends TestCase
{
    /**
     * Prove a factor must name two different units, each a bounded portable identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFactorMustRelateTwoDifferentBoundedUnits(): void
    {
        foreach ([['metric tonne', 'kg'], ['lb', 'metric tonne'], ['', 'kg'], ['lb', str_repeat('u', 64)]] as $pair) {
            $this->refuses(
                static fn (): UnitConversionFactor => self::factor($pair[0], $pair[1]),
                'bounded portable identifier',
            );
        }

        $this->refuses(
            static fn (): UnitConversionFactor => self::factor('kg', 'kg'),
            'two different units',
        );
    }

    /**
     * Prove a factor export missing or padding a member is refused rather than read partially.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFactorExportMustCarryExactlyItsDeclaredMembers(): void
    {
        $exported = self::factor()->toArray();

        foreach (array_keys($exported) as $member) {
            $missing = $exported;
            unset($missing[$member]);
            $this->refuses(
                static fn (): UnitConversionFactor => UnitConversionFactor::fromArray($missing),
                'exactly its declared members',
            );
        }

        $this->refuses(
            static fn (): UnitConversionFactor => UnitConversionFactor::fromArray(
                $exported + ['base_unit' => 'kg'],
            ),
            'exactly its declared members',
        );
    }

    /**
     * Prove a factor export member that is not text is refused before it is read as an identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFactorExportMemberOfTheWrongTypeIsRefused(): void
    {
        $exported = self::factor()->toArray();

        foreach (array_keys($exported) as $member) {
            $mistyped = $exported;
            $mistyped[$member] = 12;
            $this->refuses(
                static fn (): UnitConversionFactor => UnitConversionFactor::fromArray($mistyped),
                'member has the wrong type',
            );
        }
    }

    /**
     * Prove an as-at instant is read back only from the one canonical spelling this contract writes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAsAtInstantIsReadBackOnlyFromItsCanonicalSpelling(): void
    {
        $spellings = [
            '2026-08-14 00:00:00',
            '2026-08-14T00:00:00+00:00',
            '2026-08-14T00:00:00.000000Z',
            '2026-02-30T00:00:00.000000+00:00',
            '',
        ];

        foreach ($spellings as $spelling) {
            $this->refuses(
                static fn (): DateTimeImmutable => UnitConversionFactor::instant($spelling),
                'not a canonical UTC instant',
            );
            $mistyped = self::factor()->toArray();
            $mistyped['as_at'] = $spelling;
            $this->refuses(
                static fn (): UnitConversionFactor => UnitConversionFactor::fromArray($mistyped),
                'not a canonical UTC instant',
            );
        }

        self::assertSame(
            '2026-08-14T00:00:00.000000+00:00',
            UnitConversionFactor::instant('2026-08-14T00:00:00.000000+00:00')
                ->format(UnitConversionFactor::INSTANT_FORMAT),
        );
    }

    /**
     * Prove a request's as-at instant must be expressed in UTC, so the vintage asked about is unambiguous.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARequestAsAtInstantMustBeExpressedInUtc(): void
    {
        foreach (['Africa/Windhoek', 'America/New_York', '+02:00'] as $zone) {
            $this->refuses(
                static fn (): UnitConversionRequest => new UnitConversionRequest(
                    new QuantityValue(ExactDecimal::fromString('25.0000', 12, 4), 'lb'),
                    'kg',
                    new DateTimeImmutable('2026-08-14T02:00:00', new DateTimeZone($zone)),
                    12,
                    3,
                    QuantityRoundingMode::HalfUp,
                ),
                'must be expressed in UTC',
            );
        }
    }

    /**
     * Prove a request cannot ask for an answer wider, or shaped otherwise, than an exact value holds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARequestPrecisionAndScaleMustLieInThePortableRange(): void
    {
        $outside = [
            [0, 0],
            [-1, 0],
            [ExactDecimalArithmetic::MAXIMUM_PRECISION + 1, 3],
            [12, -1],
            [12, 13],
        ];

        foreach ($outside as $shape) {
            $this->refuses(
                static fn (): UnitConversionRequest => self::request($shape[0], $shape[1]),
                'outside the portable range',
            );
        }

        $widest = self::request(ExactDecimalArithmetic::MAXIMUM_PRECISION, 0);
        self::assertSame(ExactDecimalArithmetic::MAXIMUM_PRECISION, $widest->precision);
        self::assertSame(0, $widest->scale);
    }

    /**
     * Prove a converted quantity export whose nested members are not documents is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConvertedQuantityExportMemberOfTheWrongTypeIsRefused(): void
    {
        foreach (['factor', 'rounding'] as $member) {
            $mistyped = self::converted()->toArray();
            $mistyped[$member] = 'half_up';
            $this->refuses(
                static fn (): ConvertedQuantityValue => ConvertedQuantityValue::fromArray($mistyped),
                'export member has the wrong type',
            );
        }
    }

    /**
     * Prove the rounding a figure claims to have had applied must itself be readable text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConvertedQuantityExportRoundingMustBeSpelledOutInText(): void
    {
        foreach (['mode', 'unrounded_amount'] as $member) {
            $mistyped = self::converted()->toArray();
            $mistyped['rounding'][$member] = 12;
            $this->refuses(
                static fn (): ConvertedQuantityValue => ConvertedQuantityValue::fromArray($mistyped),
                'rounding member has the wrong type',
            );

            $missing = self::converted()->toArray();
            unset($missing['rounding'][$member]);
            $this->refuses(
                static fn (): ConvertedQuantityValue => ConvertedQuantityValue::fromArray($missing),
                'rounding member has the wrong type',
            );
        }
    }

    /**
     * Prove an exported factor arriving as a bare list is refused rather than read positionally.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConvertedQuantityExportFactorMustBeAKeyedDocument(): void
    {
        $positional = self::converted()->toArray();
        $positional['factor'] = array_values($positional['factor']);
        $this->refuses(
            static fn (): ConvertedQuantityValue => ConvertedQuantityValue::fromArray($positional),
            'must be a keyed document',
        );

        $partly = self::converted()->toArray();
        $partly['factor'][0] = 'lb';
        $this->refuses(
            static fn (): ConvertedQuantityValue => ConvertedQuantityValue::fromArray($partly),
            'must be a keyed document',
        );
    }

    /**
     * Prove neither the stored nor the expressed figure is restored from anything but an exact pair.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConvertedQuantityExportRequiresExactAmountAndUnitPairs(): void
    {
        $malformed = [
            '11.340',
            ['amount' => '11.340'],
            ['unit' => 'kg'],
            ['amount' => '11.340', 'unit' => 'kg', 'precision' => 12],
            ['amount' => 11.34, 'unit' => 'kg'],
            ['amount' => '11.340', 'unit' => 12],
        ];

        foreach (['source', 'value'] as $member) {
            foreach ($malformed as $candidate) {
                $export = self::converted()->toArray();
                $export[$member] = $candidate;
                $this->refuses(
                    static fn (): ConvertedQuantityValue => ConvertedQuantityValue::fromArray($export),
                    'exact amount and unit pairs',
                );
            }
        }
    }

    /**
     * Prove text that is not the whole portable form is refused rather than partially believed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTextThatIsNotThePortableFormIsRefusedRatherThanPartlyBelieved(): void
    {
        $converted = self::converted();
        $truncated = [
            '11.340',
            '11.340 kg',
            '11.340 kg converted from 25.0000 lb at 0.45359237',
            '11.340 kg converted from 25.0000 lb at 0.45359237 as at 2026-08-14T00:00:00.000000+00:00',
            str_replace(' from 11.339809250000', '', $converted->toPortableString()),
            str_replace(' rounded half_up ', ' rounded HALF_UP ', $converted->toPortableString()),
            str_replace(' by acme.units.trade', ' by anonymous', $converted->toPortableString()),
            $converted->toPortableString() . ' or thereabouts',
        ];

        foreach ($truncated as $candidate) {
            self::assertFalse(ConvertedQuantityValue::isPortableString($candidate), $candidate);
            $this->refuses(
                static fn (): ConvertedQuantityValue => ConvertedQuantityValue::fromPortableString($candidate),
                'spelled in the portable form',
            );
        }
    }

    /**
     * Build the converted quantity every export assertion in this class is mutated from.
     *
     * @return  ConvertedQuantityValue  25.0000 lb expressed in kg at a factor from a named provider.
     *
     * @since   2.0.0
     */
    private static function converted(): ConvertedQuantityValue
    {
        return (new QuantityConverter())->convert(self::request(), self::factor());
    }

    /**
     * Build one factor for the fixture pair, attributed to the test conversion package.
     *
     * @param   string  $source  Portable identifier of the unit the factor converts from.
     * @param   string  $target  Portable identifier of the unit the factor converts into.
     *
     * @return  UnitConversionFactor  Kilograms per pound, in UTC, attributed to `acme.units.trade`.
     *
     * @since   2.0.0
     */
    private static function factor(string $source = 'lb', string $target = 'kg'): UnitConversionFactor
    {
        return new UnitConversionFactor(
            $source,
            $target,
            ExactDecimalArithmetic::fromLiteral('0.45359237'),
            new DateTimeImmutable('2026-08-14T00:00:00', new DateTimeZone('UTC')),
            'acme.units.trade',
        );
    }

    /**
     * Build the fixture conversion request at the requested answer shape.
     *
     * @param   int  $precision  Total digit budget the converted quantity is expressed at.
     * @param   int  $scale      Fractional digits the target unit keeps.
     *
     * @return  UnitConversionRequest  25.0000 lb expressed in kg, rounded half up.
     *
     * @since   2.0.0
     */
    private static function request(int $precision = 12, int $scale = 3): UnitConversionRequest
    {
        return new UnitConversionRequest(
            new QuantityValue(ExactDecimal::fromString('25.0000', 12, 4), 'lb'),
            'kg',
            new DateTimeImmutable('2026-08-14T00:00:00', new DateTimeZone('UTC')),
            $precision,
            $scale,
            QuantityRoundingMode::HalfUp,
        );
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
