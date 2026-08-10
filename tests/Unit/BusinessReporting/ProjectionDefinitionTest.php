<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessReporting;

use Kumwe\CMS\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\CMS\BusinessReporting\Domain\ProjectionDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ProjectionFieldDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ProjectionSourceDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectionDefinition::class)]
final class ProjectionDefinitionTest extends TestCase
{
    public function testRebuildContractRoundTripsWithVersionedSources(): void
    {
        $projection = new ProjectionDefinition(
            'acme.sales_summary',
            2,
            'builder-v2',
            EventSensitivity::RESTRICTED,
            [new ProjectionSourceDefinition('acme.invoice_posted', [1, 2])],
            [
                new ProjectionFieldDefinition('organization', ReportValueType::Identifier),
                new ProjectionFieldDefinition('total', ReportValueType::Decimal),
            ],
            ['organization'],
            250,
        );

        $rebuilt = ProjectionDefinition::fromArray($projection->toArray());

        self::assertSame($projection->toArray(), $rebuilt->toArray());
        self::assertSame($projection->checksum(), $rebuilt->checksum());
        self::assertTrue($rebuilt->toArray()['rebuildable']);
    }
}
