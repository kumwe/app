<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessReporting;

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
use Kumwe\CMS\BusinessReporting\Application\ReportCsvEncoder;
use Kumwe\CMS\BusinessReporting\Application\ReportExecutionResult;
use Kumwe\CMS\BusinessReporting\Domain\ReportValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportCsvEncoder::class)]
#[CoversClass(ReportExecutionResult::class)]
#[CoversClass(ReportValueType::class)]
/**
 * Pins that a converted quantity keeps its provenance all the way into a downloaded artifact.
 *
 * @since  2.0.0
 */
final class ConvertedQuantityProvenanceTest extends TestCase
{
    /**
     * Prove a converted-quantity column admits the evidenced figure and refuses a bare one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConvertedQuantityColumnRefusesAFigureWithoutItsProvenance(): void
    {
        $type = ReportValueType::ConvertedQuantity;
        $portable = self::converted()->toPortableString();

        self::assertTrue($type->accepts($portable));
        self::assertFalse($type->accepts('24.000000'));
        self::assertFalse($type->accepts('24.000000 unit'));
        self::assertFalse($type->accepts(
            '24.000000 unit converted from 2.000000 case at 12.000000'
                . ' as at 2026-08-14T00:00:00.000000+00:00 by acme.units.trade rounded half_up',
        ));
        self::assertFalse($type->accepts(24));
        self::assertFalse($type->accepts(str_replace('24.000000 unit', '25.000000 unit', $portable)));
        self::assertFalse($type->accepts(str_replace('from 24.000000000000', 'from 25.000000000000', $portable)));
        self::assertFalse($type->accepts(str_replace('2026-08-14', '2026-02-31', $portable)));

        try {
            new ReportExecutionResult(
                'acme.pick_list',
                str_repeat('a', 64),
                str_repeat('b', 64),
                ['presented' => 'Presented quantity'],
                ['presented' => ReportValueType::ConvertedQuantity],
                [['presented' => '24.000000']],
            );
            self::fail('A converted-quantity column accepted a figure stripped of its provenance.');
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
    public function testAnExportedConvertedQuantityCarriesAndRoundTripsItsProvenance(): void
    {
        $converted = self::converted();
        $result = new ReportExecutionResult(
            'acme.pick_list',
            str_repeat('a', 64),
            str_repeat('b', 64),
            ['counted' => 'Counted quantity', 'presented' => 'Presented quantity'],
            ['counted' => ReportValueType::Decimal, 'presented' => ReportValueType::ConvertedQuantity],
            [['counted' => '2.000000', 'presented' => $converted->toPortableString()]],
        );

        $csv = implode('', iterator_to_array((new ReportCsvEncoder())->encode($result), false));
        $rows = explode("\r\n", $csv);
        self::assertArrayHasKey(1, $rows);
        $cells = str_getcsv($rows[1], ',', '"', '');

        self::assertSame('2.000000', $cells[0]);
        self::assertStringContainsString('converted from 2.000000 case', $cells[1]);
        self::assertStringContainsString('by acme.units.trade', $cells[1]);
        self::assertStringContainsString('as at 2026-08-14T00:00:00.000000+00:00', $cells[1]);
        self::assertStringContainsString('rounded half_up', $cells[1]);
        self::assertIsString($cells[1]);
        self::assertSame(
            $converted->toArray(),
            ConvertedQuantityValue::fromPortableString($cells[1])->toArray(),
        );
        self::assertNotSame($cells[0], $cells[1]);
    }

    /**
     * Build the converted quantity the report and export assertions are made against.
     *
     * @return  ConvertedQuantityValue  Two cases expressed in units from a named conversion package.
     *
     * @since   2.0.0
     */
    private static function converted(): ConvertedQuantityValue
    {
        $asAt = new DateTimeImmutable('2026-08-14T00:00:00', new DateTimeZone('UTC'));

        return (new QuantityConverter())->convert(
            new UnitConversionRequest(
                new QuantityValue(ExactDecimal::fromString('2.000000', 12, 6), 'case'),
                'unit',
                $asAt,
                12,
                6,
                QuantityRoundingMode::HalfUp,
            ),
            new UnitConversionFactor(
                'case',
                'unit',
                ExactDecimalArithmetic::fromLiteral('12.000000'),
                $asAt,
                'acme.units.trade',
            ),
        );
    }
}
