<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting;

use InvalidArgumentException;
use Kumwe\App\BusinessReporting\Domain\ReportColumnDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportFilterDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportFilterOperator;
use Kumwe\App\BusinessReporting\Domain\ReportParameterDefinition;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportDefinition::class)]
final class ReportDefinitionTest extends TestCase
{
    public function testManifestRoundTripAndChecksumAreDeterministic(): void
    {
        $report = new ReportDefinition(
            'acme.open_invoices',
            3,
            'Open invoices',
            'acme.invoice',
            'acme.reports.read',
            [new ReportParameterDefinition('customer', ReportValueType::Identifier)],
            [new ReportFilterDefinition('customer.code', ReportFilterOperator::Equal, 'customer')],
            [
                new ReportColumnDefinition('number', 'Number', 'number', ReportValueType::String),
                new ReportColumnDefinition('customer_name', 'Customer', 'customer.name', ReportValueType::String),
            ],
            portalVisible: true,
        );

        $rebuilt = ReportDefinition::fromArray($report->toArray());

        self::assertSame($report->toArray(), $rebuilt->toArray());
        self::assertSame($report->checksum(), $rebuilt->checksum());
        self::assertTrue($rebuilt->portalVisible);
        self::assertSame('acme.reports.read', $rebuilt->requiredCapability);
    }

    public function testManifestRejectsUnknownKeysAndMoreThanOneRelationship(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReportDefinition(
            'acme.invalid',
            1,
            'Invalid',
            'acme.invoice',
            'acme.reports.read',
            [],
            [],
            [
                new ReportColumnDefinition('customer', 'Customer', 'customer.name', ReportValueType::String),
                new ReportColumnDefinition('owner', 'Owner', 'owner.name', ReportValueType::String),
            ],
        );
    }
}
