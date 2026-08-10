<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessReporting;

use Kumwe\CMS\BusinessReporting\Application\ReportDefinitionRegistry;
use Kumwe\CMS\BusinessReporting\Delivery\Api\ReportApiPresenter;
use Kumwe\CMS\BusinessReporting\Domain\ReportColumnDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportParameterDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportApiPresenter::class)]
final class ReportDeliveryPresenterTest extends TestCase
{
    public function testActiveReportAppearsWithTypedParametersAndDisappearsAfterReconciliation(): void
    {
        $report = new ReportDefinition(
            'acme.open_items',
            1,
            'Open items',
            'acme.item',
            'acme.reports.read',
            [new ReportParameterDefinition('owner', ReportValueType::Identifier, required: true)],
            [],
            [new ReportColumnDefinition('number', 'Number', 'number', ReportValueType::String)],
            portalVisible: true,
        );
        $active = new ReportDefinitionRegistry([$report]);
        $presenter = new ReportApiPresenter();

        $document = $presenter->definition($active->all()[0], '/api/v1/business/reports');

        self::assertSame('acme.open_items', $document['id']);
        self::assertSame('/api/v1/business/reports/acme.open_items', $document['execute_url']);
        self::assertSame('/api/v1/business/reports/acme.open_items/exports', $document['export_url']);
        self::assertSame([
            'name' => 'owner',
            'type' => 'identifier',
            'required' => true,
            'multiple' => false,
            'default' => null,
        ], $document['parameters'][0]);
        self::assertSame([], (new ReportDefinitionRegistry([]))->all());
    }
}
