<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting\Delivery\Browser;

use InvalidArgumentException;
use Kumwe\App\BusinessReporting\Delivery\Browser\ReportParameterInput;
use Kumwe\App\BusinessReporting\Domain\ReportParameterDefinition;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies no-JavaScript report controls preserve strict report-domain types.
 *
 * @since  2.0.0
 */
#[CoversClass(ReportParameterInput::class)]
final class ReportParameterInputTest extends TestCase
{
    /**
     * Proves browser strings map without converting precision-safe decimals to floats.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMapsNativeScalarsAndOnePerLineLists(): void
    {
        self::assertSame([
            'active' => false,
            'limit' => 25,
            'threshold' => '1000000000000000.0001',
            'regions' => ['north', 'south'],
        ], ReportParameterInput::map([
            new ReportParameterDefinition('active', ReportValueType::Boolean),
            new ReportParameterDefinition('limit', ReportValueType::Integer),
            new ReportParameterDefinition('threshold', ReportValueType::Decimal),
            new ReportParameterDefinition('regions', ReportValueType::Identifier, multiple: true),
        ], [
            'active' => '0',
            'limit' => '25',
            'threshold' => '1000000000000000.0001',
            'regions' => "north\nsouth",
        ]));
    }

    /**
     * Proves blank optional controls stay omitted so domain defaults remain authoritative.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOmitsBlankOptionalControl(): void
    {
        self::assertSame([], ReportParameterInput::map([
            new ReportParameterDefinition('as_of', ReportValueType::Date),
        ], ['as_of' => '']));
    }

    /**
     * Proves undeclared names and repaired integer spellings fail closed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsUndeclaredAndNonCanonicalValues(): void
    {
        $definitions = [new ReportParameterDefinition('limit', ReportValueType::Integer)];
        foreach ([['secret' => '1'], ['limit' => '01']] as $input) {
            try {
                ReportParameterInput::map($definitions, $input);
                self::fail('Untrusted browser input must fail closed.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
