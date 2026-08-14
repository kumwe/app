<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessReporting;

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
use Kumwe\CMS\BusinessReporting\Application\ReportCsvEncoder;
use Kumwe\CMS\BusinessReporting\Application\ReportExecutionResult;
use Kumwe\CMS\BusinessReporting\Domain\ReportValueType;
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

        self::assertTrue($type->accepts(self::converted()->toPortableString()));
        self::assertFalse($type->accepts('1234.56'));
        self::assertFalse($type->accepts('EUR 1234.56'));
        self::assertFalse($type->accepts(
            'EUR 1234.56 converted from ZAR 25000.00 at 0.04938240'
                . ' as at 2026-08-14T00:00:00.000000+00:00 by acme.rates.ecb rounded half_up',
        ));
        self::assertFalse($type->accepts(1234));

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
