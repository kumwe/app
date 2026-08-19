<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Domain\ConvertedMoneyValue;
use Kumwe\App\BusinessRecord\Domain\ExactDecimal;
use Kumwe\App\BusinessRecord\Domain\ExactDecimalArithmetic;
use Kumwe\App\BusinessRecord\Domain\MoneyConversionRequest;
use Kumwe\App\BusinessRecord\Domain\MoneyConverter;
use Kumwe\App\BusinessRecord\Domain\MoneyExchangeRate;
use Kumwe\App\BusinessRecord\Domain\MoneyRoundingMode;
use Kumwe\App\BusinessRecord\Domain\MoneyValue;
use Kumwe\App\BusinessReporting\Application\ReportCsvEncoder;
use Kumwe\App\BusinessReporting\Application\ReportExecutionResult;
use Kumwe\App\BusinessReporting\Domain\ReportValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportCsvEncoder::class)]
#[CoversClass(ReportExecutionResult::class)]
#[CoversClass(ReportValueType::class)]
/**
 * Pins that a converted figure keeps its provenance all the way into a downloaded artifact.
 *
 * @since  2.0.0
 */
final class ConvertedMoneyProvenanceTest extends TestCase
{
    /**
     * Prove a converted-money column admits the evidenced figure and refuses a bare one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConvertedMoneyColumnRefusesAFigureWithoutItsProvenance(): void
    {
        $type = ReportValueType::ConvertedMoney;
        $portable = self::converted()->toPortableString();

        self::assertTrue($type->accepts($portable));
        self::assertFalse($type->accepts('1234.56'));
        self::assertFalse($type->accepts('EUR 1234.56'));
        self::assertFalse($type->accepts(
            'EUR 1234.56 converted from ZAR 25000.00 at 0.04938240'
                . ' as at 2026-08-14T00:00:00.000000+00:00 by acme.rates.ecb rounded half_up',
        ));
        self::assertFalse($type->accepts(1234));
        self::assertFalse($type->accepts(str_replace('EUR 1234.56', 'EUR 9999.99', $portable)));
        self::assertFalse($type->accepts(str_replace('from 1234.5600000000', 'from 1.0000000000', $portable)));
        self::assertFalse($type->accepts(str_replace('2026-08-14', '2026-02-31', $portable)));

        try {
            new ReportExecutionResult(
                'acme.price_list',
                str_repeat('a', 64),
                str_repeat('b', 64),
                ['presented' => 'Presented price'],
                ['presented' => ReportValueType::ConvertedMoney],
                [['presented' => '1234.56']],
            );
            self::fail('A converted-money column accepted a figure stripped of its provenance.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('contradicts its declared output type', $exception->getMessage());
        }
    }

    /**
     * Prove an exported artifact stays self-describing once it is separated from this installation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExportedConvertedFigureCarriesAndRoundTripsItsProvenance(): void
    {
        $converted = self::converted();
        $result = new ReportExecutionResult(
            'acme.price_list',
            str_repeat('a', 64),
            str_repeat('b', 64),
            ['agreed' => 'Agreed price', 'presented' => 'Presented price'],
            ['agreed' => ReportValueType::Decimal, 'presented' => ReportValueType::ConvertedMoney],
            [['agreed' => '25000.00', 'presented' => $converted->toPortableString()]],
        );

        $csv = implode('', iterator_to_array((new ReportCsvEncoder())->encode($result), false));
        $rows = explode("\r\n", $csv);
        self::assertArrayHasKey(1, $rows);
        $cells = str_getcsv($rows[1], ',', '"', '');

        self::assertSame('25000.00', $cells[0]);
        self::assertStringContainsString('converted from ZAR 25000.00', $cells[1]);
        self::assertStringContainsString('by acme.rates.ecb', $cells[1]);
        self::assertStringContainsString('as at 2026-08-14T00:00:00.000000+00:00', $cells[1]);
        self::assertStringContainsString('rounded half_up', $cells[1]);
        self::assertIsString($cells[1]);
        self::assertSame(
            $converted->toArray(),
            ConvertedMoneyValue::fromPortableString($cells[1])->toArray(),
        );
        self::assertNotSame($cells[0], $cells[1]);
    }

    /**
     * Build the converted figure the report and export assertions are made against.
     *
     * @return  ConvertedMoneyValue  25000.00 ZAR presented as EUR from a named rate package.
     *
     * @since   2.0.0
     */
    private static function converted(): ConvertedMoneyValue
    {
        $asAt = new DateTimeImmutable('2026-08-14T00:00:00', new DateTimeZone('UTC'));

        return (new MoneyConverter())->convert(
            new MoneyConversionRequest(
                new MoneyValue(ExactDecimal::fromString('25000.00', 12, 2), 'ZAR'),
                'EUR',
                $asAt,
                12,
                2,
                MoneyRoundingMode::HalfUp,
            ),
            new MoneyExchangeRate(
                'ZAR',
                'EUR',
                ExactDecimalArithmetic::fromLiteral('0.04938240'),
                $asAt,
                'acme.rates.ecb',
            ),
        );
    }
}
