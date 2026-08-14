<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessRecord\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Domain\ConvertedMoneyValue;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimalArithmetic;
use Kumwe\CMS\BusinessRecord\Domain\MoneyConversionRequest;
use Kumwe\CMS\BusinessRecord\Domain\MoneyConverter;
use Kumwe\CMS\BusinessRecord\Domain\MoneyExchangeRate;
use Kumwe\CMS\BusinessRecord\Domain\MoneyRoundingMode;
use Kumwe\CMS\BusinessRecord\Domain\MoneyValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConvertedMoneyValue::class)]
#[CoversClass(ExactDecimalArithmetic::class)]
#[CoversClass(MoneyConversionRequest::class)]
#[CoversClass(MoneyConverter::class)]
#[CoversClass(MoneyExchangeRate::class)]
#[CoversClass(MoneyRoundingMode::class)]
/**
 * Pins the money conversion contract: exact arithmetic, declared rounding, and mandatory provenance.
 *
 * @since  2.0.0
 */
final class MoneyConversionContractTest extends TestCase
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
        $converted = (new MoneyConverter())->convert(
            new MoneyConversionRequest(
                new MoneyValue(ExactDecimal::fromString('25000.00', 12, 2), 'ZAR'),
                'EUR',
                self::instant('2026-08-14T00:00:00'),
                12,
                2,
                MoneyRoundingMode::HalfUp,
            ),
            self::rate('0.04938240'),
        );

        self::assertSame('1234.5600000000', $converted->unrounded->value());
        self::assertSame('1234.56', $converted->converted->amount->value());
        self::assertSame('EUR', $converted->converted->currency);
        self::assertSame('ZAR', $converted->source->currency);
        self::assertSame(
            '1234.5600000000',
            ExactDecimalArithmetic::multiply(
                $converted->source->amount,
                $converted->rate->rate,
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

        foreach (MoneyRoundingMode::cases() as $mode) {
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
            ExactDecimalArithmetic::round(ExactDecimal::fromString('1.2250', 8, 4), 8, 2, MoneyRoundingMode::HalfEven)
                ->value(),
        );
        self::assertSame(
            '10.0',
            ExactDecimalArithmetic::round(ExactDecimal::fromString('9.99', 8, 2), 8, 1, MoneyRoundingMode::HalfUp)
                ->value(),
        );
    }

    /**
     * Prove a converted amount cannot exist without the rate, instant and provider that justify it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnIncompleteOrContradictoryConvertedAmountCannotBeConstructed(): void
    {
        $source = new MoneyValue(ExactDecimal::fromString('25000.00', 12, 2), 'ZAR');
        $rate = self::rate('0.04938240');
        $unrounded = ExactDecimalArithmetic::multiply($source->amount, $rate->rate);

        $this->refuses(
            static fn (): ConvertedMoneyValue => new ConvertedMoneyValue(
                $source,
                new MoneyValue(ExactDecimal::fromString('9999.99', 12, 2), 'EUR'),
                $rate,
                MoneyRoundingMode::HalfUp,
                $unrounded,
            ),
            'under its own rounding',
        );
        $this->refuses(
            static fn (): ConvertedMoneyValue => new ConvertedMoneyValue(
                $source,
                new MoneyValue(ExactDecimal::fromString('1234.56', 12, 2), 'EUR'),
                $rate,
                MoneyRoundingMode::HalfUp,
                ExactDecimal::fromString('1.000000000000', 20, 12),
            ),
            'the exact product',
        );
        $this->refuses(
            static fn (): ConvertedMoneyValue => new ConvertedMoneyValue(
                $source,
                new MoneyValue(ExactDecimal::fromString('1234.56', 12, 2), 'USD'),
                $rate,
                MoneyRoundingMode::HalfUp,
                $unrounded,
            ),
            'prices its own pair',
        );
        $this->refuses(
            static fn (): MoneyExchangeRate => new MoneyExchangeRate(
                'ZAR',
                'EUR',
                ExactDecimal::fromString('0.00000000', 12, 8),
                self::instant('2026-08-14T00:00:00'),
                'acme.rates.ecb',
            ),
            'above zero',
        );
        $this->refuses(
            static fn (): MoneyExchangeRate => new MoneyExchangeRate(
                'ZAR',
                'EUR',
                ExactDecimal::fromString('0.04938240', 12, 8),
                new DateTimeImmutable('2026-08-14T02:00:00', new DateTimeZone('Africa/Windhoek')),
                'acme.rates.ecb',
            ),
            'expressed in UTC',
        );
        $this->refuses(
            static fn (): MoneyExchangeRate => new MoneyExchangeRate(
                'ZAR',
                'EUR',
                ExactDecimal::fromString('0.04938240', 12, 8),
                self::instant('2026-08-14T00:00:00'),
                'anonymous',
            ),
            'namespaced identifier',
        );
    }

    /**
     * Prove a rate dated after the instant asked about cannot be presented as the historical one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARateFromAfterTheInstantAskedAboutIsRefused(): void
    {
        $request = new MoneyConversionRequest(
            new MoneyValue(ExactDecimal::fromString('25000.00', 12, 2), 'ZAR'),
            'EUR',
            self::instant('2026-08-14T00:00:00'),
            12,
            2,
            MoneyRoundingMode::HalfUp,
        );

        self::assertFalse($request->answeredBy(self::rate('0.04938240', '2026-08-15T00:00:00')));
        self::assertFalse($request->answeredBy(new MoneyExchangeRate(
            'ZAR',
            'USD',
            ExactDecimal::fromString('0.04938240', 12, 8),
            self::instant('2026-08-13T00:00:00'),
            'acme.rates.ecb',
        )));
        $this->refuses(
            static fn (): ConvertedMoneyValue => (new MoneyConverter())->convert(
                $request,
                self::rate('0.04938240', '2026-08-15T00:00:00'),
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
    public function testAConversionRequestRefusesADenominationItWouldNotChange(): void
    {
        $this->refuses(
            static fn (): MoneyConversionRequest => new MoneyConversionRequest(
                new MoneyValue(ExactDecimal::fromString('1.00', 12, 2), 'ZAR'),
                'ZAR',
                self::instant('2026-08-14T00:00:00'),
                12,
                2,
                MoneyRoundingMode::HalfUp,
            ),
            'other than the amount\'s own',
        );
        $this->refuses(
            static fn (): MoneyConversionRequest => new MoneyConversionRequest(
                new MoneyValue(ExactDecimal::fromString('1.00', 12, 2), 'ZAR'),
                'eur',
                self::instant('2026-08-14T00:00:00'),
                12,
                2,
                MoneyRoundingMode::HalfUp,
            ),
            'uppercase ISO 4217',
        );
    }

    /**
     * Prove a converted amount can never be read as, or substituted for, a stored money value.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConvertedAmountIsStructurallyUnlikeAStoredOne(): void
    {
        $converted = self::converted();
        $stored = new MoneyValue(ExactDecimal::fromString('25000.00', 12, 2), 'ZAR');

        self::assertSame(['amount', 'currency'], array_keys($stored->toArray()));
        self::assertSame(
            [],
            array_intersect(array_keys($converted->toArray()), array_keys($stored->toArray())),
        );
        self::assertTrue($converted->toArray()['converted']);
        self::assertSame('acme.rates.ecb', $converted->toArray()['rate']['provider']);
        self::assertSame('2026-08-14T00:00:00.000000+00:00', $converted->toArray()['rate']['as_at']);
        self::assertSame('half_up', $converted->toArray()['rounding']['mode']);
        self::assertSame('1234.5600000000', $converted->toArray()['rounding']['unrounded_amount']);
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
            ConvertedMoneyValue::fromArray($converted->toArray())->toArray(),
        );
        self::assertSame(
            'EUR 1234.56 converted from ZAR 25000.00 at 0.04938240'
                . ' as at 2026-08-14T00:00:00.000000+00:00 by acme.rates.ecb rounded half_up from 1234.5600000000',
            $converted->toPortableString(),
        );
        self::assertSame(
            $converted->toArray(),
            ConvertedMoneyValue::fromPortableString($converted->toPortableString())->toArray(),
        );
        self::assertTrue(ConvertedMoneyValue::isPortableString($converted->toPortableString()));
        self::assertFalse(ConvertedMoneyValue::isPortableString('1234.56'));
        self::assertFalse(ConvertedMoneyValue::isPortableString('EUR 1234.56'));

        $this->refuses(
            static fn (): ConvertedMoneyValue => ConvertedMoneyValue::fromArray(
                ['value' => ['amount' => '1234.56', 'currency' => 'EUR']],
            ),
            'exactly its declared members',
        );
        $incomplete = $converted->toArray();
        $incomplete['converted'] = false;
        $this->refuses(
            static fn (): ConvertedMoneyValue => ConvertedMoneyValue::fromArray($incomplete),
            'marked as converted',
        );
    }

    /**
     * Build the converted amount every provenance assertion in this class is made against.
     *
     * @return  ConvertedMoneyValue  25000.00 ZAR presented as EUR at a rate from a named provider.
     *
     * @since   2.0.0
     */
    private static function converted(): ConvertedMoneyValue
    {
        return (new MoneyConverter())->convert(
            new MoneyConversionRequest(
                new MoneyValue(ExactDecimal::fromString('25000.00', 12, 2), 'ZAR'),
                'EUR',
                self::instant('2026-08-14T00:00:00'),
                12,
                2,
                MoneyRoundingMode::HalfUp,
            ),
            self::rate('0.04938240'),
        );
    }

    /**
     * Build one rate for the fixture pair, attributed to the test rate package.
     *
     * @param   string  $rate  Canonical rate literal, EUR per ZAR.
     * @param   string  $asAt  Naive UTC instant the rate is as at.
     *
     * @return  MoneyExchangeRate  The rate, in UTC, attributed to `acme.rates.ecb`.
     *
     * @since   2.0.0
     */
    private static function rate(string $rate, string $asAt = '2026-08-14T00:00:00'): MoneyExchangeRate
    {
        return new MoneyExchangeRate(
            'ZAR',
            'EUR',
            ExactDecimalArithmetic::fromLiteral($rate),
            self::instant($asAt),
            'acme.rates.ecb',
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
