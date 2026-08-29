<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting;

use Kumwe\App\BusinessReporting\Application\ReportCsvEncoder;
use Kumwe\App\BusinessReporting\Application\ReportExecutionResult;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportCsvEncoder::class)]
final class ReportCsvEncoderTest extends TestCase
{
    public function testQuotesEveryCellAndNeutralizesSpreadsheetFormulasWithoutChangingNumbers(): void
    {
        $result = new ReportExecutionResult(
            'acme.safe_export',
            str_repeat('a', 64),
            str_repeat('b', 64),
            ['name' => '=Label', 'amount' => 'Amount', 'note' => 'Note'],
            [
                'name' => ReportValueType::String,
                'amount' => ReportValueType::Decimal,
                'note' => ReportValueType::String,
            ],
            [[
                'name' => '=HYPERLINK("https://invalid")',
                'amount' => '-12.50',
                'note' => "  +SUM(1,1)\r\nnext",
            ]],
        );

        $csv = implode('', iterator_to_array((new ReportCsvEncoder())->encode($result), false));

        self::assertStringStartsWith("\xEF\xBB\xBF\"'=Label\",\"Amount\",\"Note\"\r\n", $csv);
        self::assertStringContainsString('"\'=HYPERLINK(""https://invalid"")"', $csv);
        self::assertStringContainsString('"-12.50"', $csv);
        self::assertStringContainsString('"\'  +SUM(1,1)', $csv);
    }
}
